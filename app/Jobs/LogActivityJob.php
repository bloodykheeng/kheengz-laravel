<?php

namespace App\Jobs;

use App\Models\Activity;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Jenssegers\Agent\Agent;
use Stevebauman\Location\Facades\Location;
use Stevebauman\Location\Position;

/**
 * Writes one audit entry, off the request thread.
 *
 * The "where" enrichment (IP geolocation, user-agent parsing) used to run inline in
 * LoggableTrait, which put a third-party HTTP call on the critical path of every
 * create/update/delete. Queuing it keeps writes fast; the entry still carries the
 * moment the action happened because occurredAt is pinned by the caller.
 */
class LogActivityJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $properties
     */
    public function __construct(
        private string $module,
        private string $event,
        private string $description,
        private array $properties,
        private ?string $causerId,
        private ?string $causerType,
        private ?string $causerName,
        private ?string $causerEmail,
        private ?string $subjectId,
        private ?string $subjectType,
        private ?string $ip,
        private ?string $userAgent,
        private Carbon $occurredAt,
    ) {}

    public function handle(): void
    {
        $location = $this->ip ? $this->locate($this->ip) : null;

        $agent = new Agent;
        $agent->setUserAgent($this->userAgent ?? '');

        // WHO and WHERE. The caller's own properties win on a key clash, so a module can
        // always override anything here with something more specific.
        $context = [
            'created_by' => $this->causerName,
            'created_by_email' => $this->causerEmail,
            'ip' => $this->ip,
            'country' => $location->countryName ?? null,
            'city' => $location->cityName ?? null,
            'region' => $location->regionName ?? null,
            'latitude' => $location->latitude ?? null,
            'longitude' => $location->longitude ?? null,
            'timezone' => $location->timezone ?? null,
            'user_agent' => $this->userAgent,
            'device' => $agent->device(),
            'platform' => $agent->platform(),
            'platform_version' => $agent->version($agent->platform()),
            'browser' => $agent->browser(),
            'browser_version' => $agent->version($agent->browser()),
        ];

        $logger = activity($this->module)
            ->event($this->event)
            ->withProperties(array_merge($context, $this->properties))
            ->createdAt($this->occurredAt);

        // Resolved by key rather than serialising the models: by the time this runs the
        // subject may already be deleted, and a missing record must not lose the entry:
        // subject_type/subject_id stay on the row either way.
        if ($this->causerId && $this->causerType) {
            if ($causer = app($this->causerType)->find($this->causerId)) {
                $logger->causedBy($causer);
            }
        }

        if ($this->subjectId && $this->subjectType) {
            if ($subject = app($this->subjectType)->find($this->subjectId)) {
                $logger->performedOn($subject);
            }
        }

        $activity = $logger->log($this->description);

        // A delete removes the row before this job runs, so performedOn() above finds
        // nothing. Write the morph columns directly so the trail still names the record
        // that was destroyed: otherwise deletions are the one event with no Module.
        // config/activitylog.php makes App\Models\Activity the model, so this is the row just written.
        if ($this->subjectId && $this->subjectType && $activity instanceof Activity && ! $activity->subject_type) {
            $activity->forceFill([
                'subject_type' => $this->subjectType,
                'subject_id' => $this->subjectId,
            ])->save();
        }
    }

    /**
     * Geolocate an IP at most once per day.
     *
     * The provider lookup is an external HTTP call. Private and reserved addresses (local
     * dev, LAN) resolve to nothing, so those skip it entirely. A hit is cached for a day
     * because a location is stable; a miss is cached for only ten minutes, so one transient
     * provider failure does not blank coordinates for a full day.
     */
    private function locate(string $ip): ?Position
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return null;
        }

        $key = 'geoip:'.$ip;
        $cached = Cache::get($key);

        if ($cached instanceof Position) {
            return $cached;
        }

        // 'unresolved' sentinel: a recent failure, don't hammer the provider again yet.
        if ($cached === 'unresolved') {
            return null;
        }

        $position = Location::get($ip) ?: null;

        Cache::put($key, $position ?: 'unresolved', $position ? now()->addDay() : now()->addMinutes(10));

        return $position instanceof Position ? $position : null;
    }
}
