<?php

use App\Models\Activity;
use Spatie\Activitylog\Actions\CleanActivityLogAction;
use Spatie\Activitylog\Actions\LogActivityAction;

return [

    'enabled' => env('ACTIVITYLOG_ENABLED', true),

    /*
     * THE TRAIL MUST OUTLIVE THE RECORDS IT EXPLAINS.
     *
     * Orders, payouts, commissions and refunds have to be explainable years later (tax,
     * disputes, a vendor asking why a payout was short). Spatie's default of 365 days
     * would delete the explanation while the money it explains lives on. Ten years is
     * longer than any record this database must answer for; lower it only as a decision.
     *
     * If the table grows too big, clean the noisy modules by `log_name` (the subject's
     * class basename), never the whole trail. See docs/audit-trail.md.
     */

    'clean_after_days' => (int) env('ACTIVITYLOG_CLEAN_AFTER_DAYS', 3650),

    'default_log_name' => 'default',

    'default_auth_driver' => null,

    'include_soft_deleted_subjects' => false,

    // Our subclass adds HasUlids so audit rows are ULID-keyed like every other
    // table this app owns. Without it Spatie inserts through its own model,
    // which assumes an auto-incrementing id and fails on the ULID column.
    'activity_model' => Activity::class,

    'default_except_attributes' => [],

    'buffer' => [
        'enabled' => env('ACTIVITYLOG_BUFFER_ENABLED', false),
    ],

    'actions' => [
        'log_activity' => LogActivityAction::class,
        'clean_log' => CleanActivityLogAction::class,
    ],
];
