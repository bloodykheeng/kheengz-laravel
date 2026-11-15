<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityLogResource;
use App\Models\Activity;
use App\Support\BusinessDate;
use App\Traits\LoggableTrait;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedInclude;
use Spatie\QueryBuilder\QueryBuilder;

#[Group('Activity Logs', 'Browse and manage system activity log entries.', weight: 96)]
class ActivityLogsController extends Controller
{
    use LoggableTrait;

    /**
     * List all activity logs.
     *
     * Returns a paginated, filterable, sortable list of system activity log entries.
     */
    #[QueryParameter('filter[log_name]', 'Filter by module (log name), e.g. District.', type: 'string', example: 'District')]
    #[QueryParameter('filter[search]', 'Search within the description field.', type: 'string', example: 'user created')]
    #[QueryParameter('filter[event]', 'Filter by event type.', type: 'string', example: 'user_created')]
    #[QueryParameter('filter[causer_id]', 'Filter by the ID of the user who caused the action.', type: 'string', example: '01JZK8QP7M3XN4YRB6WV2TCDEF')]
    #[QueryParameter('filter[subject_type]', 'Filter by the affected model, by short or full class name.', type: 'string', example: 'District')]
    #[QueryParameter('filter[subject_id]', 'Every entry touching one specific record.', type: 'string', example: '01JZK8QP7M3XN4YRB6WV2TCDEF')]
    #[QueryParameter('filter[outcome]', 'Filter by outcome (success or failed).', type: 'string', example: 'failed')]
    #[QueryParameter('filter[start_date]', 'Filter logs on or after this date (Y-m-d).', type: 'string', example: '2025-01-01')]
    #[QueryParameter('filter[end_date]', 'Filter logs on or before this date (Y-m-d).', type: 'string', example: '2025-12-31')]
    #[QueryParameter('sort', 'Sort field. Prefix with `-` for descending.', type: 'string', example: '-created_at')]
    #[QueryParameter('include', 'Relationships to include (causer).', type: 'string', example: 'causer')]
    #[QueryParameter('per_page', 'Results per page (default 15).', type: 'integer', example: '15')]
    #[QueryParameter('page', 'Page number.', type: 'integer', example: '1')]
    #[QueryParameter('all', 'Return all records without pagination.', type: 'boolean', example: 'true')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = QueryBuilder::for(Activity::class)
            ->allowedFilters(
                AllowedFilter::exact('log_name'),
                AllowedFilter::exact('event'),
                AllowedFilter::exact('causer_id'),
                AllowedFilter::exact('subject_id'),
                AllowedFilter::partial('search', 'description'),
                // The column holds the FQCN. Accepting the short name too means the UI can
                // filter with what it already displays in the Module column.
                AllowedFilter::callback('subject_type', fn ($q, $v) => $q->where(
                    'subject_type',
                    str_contains((string) $v, '\\') ? '=' : 'like',
                    str_contains((string) $v, '\\') ? $v : '%\\'.$v
                )),
                AllowedFilter::callback('outcome', fn ($q, $v) => $q->where('properties->outcome', $v)),
                AllowedFilter::callback('start_date', fn ($q, $v) => $q->where('created_at', '>=', BusinessDate::rangeStartUtc((string) $v))),
                AllowedFilter::callback('end_date', fn ($q, $v) => $q->where('created_at', '<=', BusinessDate::rangeEndUtc((string) $v))),
            )
            ->allowedSorts('log_name', 'event', 'created_at')
            ->allowedIncludes(
                // `causer.roles`, NOT `causer`. `UserResource` renders `role`,
                // which is an accessor over the `roles` relation, so loading the
                // causer alone lazy-loads on every row and
                // `Model::preventLazyLoading()` turns the whole screen into a
                // 500. It stayed hidden while the log was empty; the moment the
                // import wrote thousands of rows the audit trail stopped opening.
                //
                // `whenLoaded('causer')` is unaffected: loading the nested
                // relation loads its parent.
                AllowedInclude::relationship('causer', 'causer.roles'),
            )
            ->defaultSort('-created_at')
            ->orderBy('id', 'desc');

        if ($request->boolean('all')) {
            return ActivityLogResource::collection($query->get());
        }

        return ActivityLogResource::collection(
            $query->paginate($request->integer('per_page', 15))->appends($request->query())
        );
    }

    /**
     * Delete multiple activity log entries.
     *
     * Permanently removes activity log records by ID.
     */
    #[BodyParameter('ids', 'Array of activity log IDs to delete.', required: true, type: 'array', example: '[1, 2, 3]')]
    public function bulkDestroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['string', 'exists:activity_log,id'],
        ]);

        $ids = $validated['ids'];

        Activity::destroy($ids);

        $this->logActivity('activity_logs_bulk_deleted', count($ids).' activity log entries were deleted.', ['activity_log_ids' => $ids, 'module' => 'AuditTrail']);

        return response()->json(null, 204);
    }
}
