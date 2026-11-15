<?php

namespace App\Traits;

use App\Jobs\LogActivityJob;
use App\Models\User;
use App\Support\SnapshotPresenter;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;

/**
 * Writing to the audit trail.
 *
 * Every entry aims to answer the six questions a defensible trail must answer: who, what,
 * which record, what changed, when, where. See docs/audit-trail.md.
 *
 * Controllers never call Spatie's `activity()` directly; they call `logActivity()` so the
 * shape stays uniform across the whole system.
 */
trait LoggableTrait
{
    /**
     * Request fields never written to the audit trail, whatever the module.
     *
     * `code` is deliberately not here: administrative units, departments and service areas
     * all carry a business code an investigator needs to see. Only genuine secrets belong.
     *
     * @var array<int, string>
     */
    private array $auditRedactKeys = [
        'password',
        'password_confirmation',
        'current_password',
        'new_password',
        'otp',
        'token',
        '_token',
        '_method',
        'access_token',
        'refresh_token',
        'api_key',
        'secret',
    ];

    /**
     * Bookkeeping columns that are never interesting on their own.
     *
     * `updated_at` changes on every save, so leaving it in would put a meaningless row at
     * the top of every diff.
     *
     * @var array<int, string>
     */
    private array $auditIgnoredFields = [
        'id',
        'created_at',
        'updated_at',
        'created_by',
        'updated_by',
        'remember_token',
        'email_verified_at',
    ];

    /**
     * Records one audit entry.
     *
     * @param  string  $event  Specific action key, e.g. `district_updated`.
     * @param  string  $description  Human sentence shown in the trail.
     * @param  array<string, mixed>  $properties  Extra context. Put a field diff under
     *                                            `changes` and created values under `snapshot`.
     * @param  Model|null  $subject  The record acted on, which becomes the Module column.
     * @param  string|null  $outcome  `success` or `failed`. Inferred from the event key when
     *                                omitted, so failure events need no extra argument.
     * @param  User|null  $causer  Defaults to the authenticated user. Pass it explicitly on
     *                             routes that run before authentication, such as sign-in and
     *                             the OTP reset flow, where the guard is never populated and
     *                             the entry would otherwise name nobody.
     */
    public function logActivity(
        string $event,
        string $description,
        array $properties = [],
        ?Model $subject = null,
        ?string $outcome = null,
        ?User $causer = null
    ): void {
        $causer ??= Auth::user();

        $properties['outcome'] = $outcome
            ?? ($properties['outcome'] ?? (str_contains($event, 'fail') ? 'failed' : 'success'));

        $this->captureRequestContext($properties);

        // Drop keys that carry nothing. A `changes: {}` or `cascade: []` renders as an
        // empty section in the trail, which reads as "we looked and found nothing" when
        // the truth is the module simply had none to report. `false` and `0` are kept.
        $properties = array_filter(
            $properties,
            fn ($value) => $value !== null && $value !== '' && $value !== [],
        );

        // Resolved before the unset: `module` is a routing hint for callers with no model
        // to point at, and it is stored as `log_name`, so keeping it in properties too
        // would show the same value twice on every entry.
        $module = $this->moduleFor($subject, $properties);
        unset($properties['module']);

        dispatch(new LogActivityJob(
            module: $module,
            event: $event,
            description: $description,
            properties: $properties,
            causerId: $causer?->getKey(),
            causerType: $causer ? $causer::class : null,
            causerName: $causer?->name,
            causerEmail: $causer?->email,
            subjectId: $subject?->getKey(),
            subjectType: $subject ? $subject::class : null,
            ip: request()->ip(),
            userAgent: request()->header('User-Agent'),
            // Pinned here, not in the job: the queue may run the job seconds later, and
            // "when it happened" is not "when we got round to writing it down".
            occurredAt: now(),
        ));
    }

    /**
     * The record's stored values, captured before a write so a diff can be taken after.
     *
     * Pair with `buildChanges()`, calling this before the update and that after it.
     *
     * Includes every value the schema declares with a `current` closure, not just columns:
     * `getChanges()` only reports columns, so an edit that stopped a district being
     * inspected on Health would be logged as "nothing changed".
     *
     * @return array<string, mixed>
     */
    protected function captureOriginal(Model $model): array
    {
        return $model->getRawOriginal() + $this->captureDerived($model);
    }

    /**
     * A field-level diff, resolved through the model's `snapshotSchema()`.
     *
     * Diffs what Eloquent actually wrote rather than what the request asked for. Those
     * differ more often than expected: a mutator, a cast, or a status flipped by a side
     * effect all land in the row without appearing in the validated input.
     *
     * Call after the save, with the array `captureOriginal()` returned before it.
     *
     * @param  array<string, mixed>  $before  From `captureOriginal()`.
     * @param  array<int, string>  $exclude  Extra fields to leave out.
     * @return array<string, mixed>
     */
    protected function buildChanges(Model $model, array $before, array $exclude = []): array
    {
        $after = $model->getChanges();

        // A value that is not a column can never appear in `getChanges()`, so it
        // is re-read here and merged in. Always merged, never pre-filtered: the
        // diff decides what actually moved, and drops anything that did not.
        $after += $this->captureDerived($model);

        return SnapshotPresenter::changes(
            $before,
            $after,
            SnapshotPresenter::schemaFor($model),
            array_merge($this->auditRedactKeys, $this->auditIgnoredFields, $exclude),
        );
    }

    /**
     * Every value a model's schema declares through a `current` closure.
     *
     * The closure is what makes a value auditable without living on this table, and it
     * works for all three shapes: `kind: list` diffs rows one by one, `kind: object` diffs
     * one nested record field by field, and no kind is one scalar before and after. None of
     * them appears in `getChanges()`, which is why they are read here.
     *
     * A schema entry becomes an auditable collection by declaring `kind: list` and a
     * `current` closure returning its rows, with an `item` map naming the fields. Declared
     * once on the model, picked up everywhere, so no controller writes capture code.
     *
     * Costs one query per declared list, and none for a model that declares none.
     *
     * Only one level deep, and only what is declared: walking the object graph would embed
     * thousands of rows into a JSON column on every edit. See docs/snapshot-schema.md.
     *
     * @return array<string, mixed>
     */
    protected function captureDerived(Model $model): array
    {
        $out = [];

        foreach (SnapshotPresenter::schemaFor($model) as $field => $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $current = $entry['current'] ?? null;

            if (! is_callable($current)) {
                continue;
            }

            $kind = $entry['kind'] ?? null;
            $value = $current($model);

            // No kind: an ordinary value that simply lives somewhere else. It
            // formats and diffs exactly like a column from here on.
            if ($kind !== 'list' && $kind !== 'object') {
                $out[$field] = $value;

                continue;
            }

            // An object is a single record, so it is captured whole. A null one
            // (no security detail on this transaction) stays null, which reads
            // as "absent" on both sides of the diff rather than as an empty row.
            if ($kind === 'object') {
                $out[$field] = $value instanceof Model
                    ? $value->attributesToArray()
                    : (is_array($value) ? $value : null);

                continue;
            }

            $out[$field] = collect($value)
                ->map(fn ($row) => $row instanceof Model ? $row->attributesToArray() : (array) $row)
                ->values()
                ->all();
        }

        return $out;
    }

    /**
     * What a record was created with, resolved through `snapshotSchema()`.
     *
     * A create has no "before", so this is a snapshot rather than a diff.
     *
     * @param  array<string, mixed>  $values  The validated values.
     * @param  array<int, string>  $exclude  Extra fields to leave out.
     * @param  Model|null  $model  Supplies the schema; without one the values are recorded
     *                             raw, which is a fallback rather than the intended path.
     * @return array<string, mixed>
     */
    protected function buildSnapshot(array $values, array $exclude = [], ?Model $model = null): array
    {
        return SnapshotPresenter::snapshot(
            $values,
            SnapshotPresenter::schemaFor($model),
            array_merge($this->auditRedactKeys, $this->auditIgnoredFields, $exclude),
        );
    }

    /**
     * A snapshot of a record as it stands, from its own attributes.
     *
     * Used by deletes: once the row is gone, the audit entry is the only account of what
     * was destroyed. Only fillable fields are taken, which keeps ids and timestamps out.
     *
     * Declared collections are captured too and must be, nothing being left to re-read the
     * pivot from afterwards. Call this before the delete.
     *
     * @return array<string, mixed>
     */
    protected function buildModelSnapshot(Model $model): array
    {
        return $this->buildSnapshot(
            $model->only($model->getFillable()) + $this->captureDerived($model),
            [],
            $model,
        );
    }

    /**
     * What a cascading delete is about to destroy, counted per level.
     *
     * The location hierarchy cascades in the database, so deleting one region takes
     * thousands of descendants without firing a single Eloquent event: no child entries and
     * no broadcasts. Counting first is the only record they existed. Call before the delete.
     *
     * @return array<string, int>
     */
    protected function buildBlastRadius(Model $model): array
    {
        if (! method_exists($model, 'cascadeBlastRadius')) {
            return [];
        }

        return array_filter($model->cascadeBlastRadius(), fn (int $count) => $count > 0);
    }

    /**
     * What a bulk delete is about to remove, named rather than listed as bare ids.
     *
     * Nobody can tell from a column of ULIDs what was destroyed, so each record is
     * snapshotted first.
     *
     * Both details and cascade counts are capped: a 500-row batch must not write a megabyte
     * of JSON or fire 2,000 count queries. The total is always exact; only the per-record
     * detail is truncated, and the entry says so.
     *
     * @template TModel of Model
     *
     * @param  Collection<int, TModel>  $models
     * @return array<string, mixed>
     */
    protected function buildBulkDeleteContext(Collection $models, int $detailCap = 50): array
    {
        $context = ['count' => $models->count()];

        $context['records'] = $models
            ->take($detailCap)
            ->map(fn (Model $model) => $this->buildModelSnapshot($model))
            ->values()
            ->all();

        if ($models->count() > $detailCap) {
            $context['records_truncated'] = $models->count() - $detailCap;
        }

        // Counting the cascade costs a few queries per record, so only do it for batches
        // small enough for the answer to be worth it.
        if ($models->count() <= 25) {
            $cascade = [];

            foreach ($models as $model) {
                foreach ($this->buildBlastRadius($model) as $level => $count) {
                    $cascade[$level] = ($cascade[$level] ?? 0) + $count;
                }
            }

            if (! empty($cascade)) {
                $context['cascade'] = $cascade;
            }
        }

        return $context;
    }

    /**
     * Attaches the HTTP context every entry carries: the correlation id, the route hit and
     * the raw payload the request sent.
     *
     * `request` is kept verbatim, ids and all. It is the one part of the entry that is
     * evidence rather than presentation: exactly what the client sent, including fields
     * that were ignored or rejected. Trimming it would make it a worse copy of `snapshot`.
     *
     * Only mutating verbs are captured, a GET having no meaningful body. Secrets are
     * stripped and uploaded files become a `[file: name]` marker, so binary content never
     * lands in the log. Runs synchronously, while `request()` is still the originating one.
     *
     * @param  array<string, mixed>  $properties  Mutated in place.
     */
    private function captureRequestContext(array &$properties): void
    {
        $request = request();

        if (! $request instanceof Request) {
            return;
        }

        $properties['request_id'] ??= $request->attributes->get('request_id');
        $properties['method'] ??= $request->getMethod();
        $properties['url'] ??= $request->fullUrl();
        $properties['route'] ??= $request->route()?->getName();

        if (array_key_exists('request', $properties)) {
            return;
        }

        if (! in_array($request->getMethod(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return;
        }

        $data = $request->except($this->auditRedactKeys);

        array_walk_recursive($data, function (&$value) {
            if ($value instanceof UploadedFile) {
                $value = '[file: '.$value->getClientOriginalName().']';
            }
        });

        if (! empty($data)) {
            $properties['request'] = $data;
        }
    }

    /**
     * The module an entry belongs to, which is what `log_name` stores.
     *
     * Taken from the subject's class when there is one, so it can never drift from the
     * record actually touched. Callers with no model to point at, such as a login, pass
     * `module` in the properties.
     *
     * @param  array<string, mixed>  $properties
     */
    private function moduleFor(?Model $subject, array $properties): string
    {
        if ($subject) {
            return class_basename($subject);
        }

        return $properties['module'] ?? 'System';
    }
}
