<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Bulk delete for a controller that also uses LoggableTrait.
 *
 * Each record is deleted through its model, so its `deleting` guard and file
 * cleanup run, inside one transaction: one protected record refuses the whole
 * batch rather than leaving half of it gone. The entry names every record.
 */
trait BulkDeletes
{
    /**
     * The `ids` of a bulk delete request.
     *
     * @return list<string>
     */
    protected function bulkIds(Request $request, int $max = 100): array
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', "max:{$max}"],
            'ids.*' => ['required', 'string', 'distinct'],
        ]);

        return array_values($validated['ids']);
    }

    /**
     * Delete these records, all or none, and log one entry that names them.
     *
     * @template TModel of Model
     *
     * @param  Collection<int, TModel>  $models
     */
    protected function deleteManyOrFail(Collection $models, string $event, string $description, string $module): int
    {
        $context = $this->buildBulkDeleteContext($models);

        DB::transaction(fn () => $models->each->delete());

        $this->logActivity($event, $description, array_merge(['module' => $module], $context));

        return $models->count();
    }
}
