<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

/**
 * App-owned activity model so audit rows carry ULID primary keys like every
 * other table we own (config/activitylog.php points activity_model here).
 * Spatie's activity() pipeline creates rows through this model, so HasUlids
 * mints the id on insert exactly as it does for domain models.
 */
class Activity extends SpatieActivity
{
    use HasUlids;
}
