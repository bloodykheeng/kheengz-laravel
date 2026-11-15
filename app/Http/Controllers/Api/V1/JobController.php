<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\FailedJobResource;
use App\Http\Resources\JobResource;
use App\Models\FailedJob;
use App\Models\Job;
use App\Traits\LoggableTrait;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

#[Group('Queue Jobs', 'Inspect and manage queued and failed background jobs.', weight: 95)]
class JobController extends Controller
{
    use LoggableTrait;

    /**
     * List all queued jobs.
     *
     * Returns a paginated, filterable list of jobs currently waiting in the queue database.
     */
    #[QueryParameter('filter[queue]', 'Filter by queue name.', type: 'string', example: 'default')]
    #[QueryParameter('filter[search]', 'Search within job payload.', type: 'string', example: 'SendEmail')]
    #[QueryParameter('sort', 'Sort field. Prefix with `-` for descending. Default: -id.', type: 'string', example: '-id')]
    #[QueryParameter('per_page', 'Results per page (default 15).', type: 'integer', example: '15')]
    #[QueryParameter('page', 'Page number.', type: 'integer', example: '1')]
    #[QueryParameter('all', 'Return all records without pagination.', type: 'boolean', example: 'true')]
    public function getQueuedJobs(Request $request): AnonymousResourceCollection
    {
        $query = QueryBuilder::for(Job::class)
            ->allowedFilters(
                AllowedFilter::exact('queue'),
                AllowedFilter::partial('search', 'payload'),
            )
            ->allowedSorts('id', 'queue', 'attempts', 'available_at', 'created_at')
            ->defaultSort('-id');

        if ($request->boolean('all')) {
            return JobResource::collection($query->get());
        }

        return JobResource::collection(
            $query->paginate($request->integer('per_page', 15))->appends($request->query())
        );
    }

    /**
     * List all failed jobs.
     *
     * Returns a paginated, filterable list of jobs that have failed during processing.
     */
    #[QueryParameter('filter[queue]', 'Filter by queue name.', type: 'string', example: 'default')]
    #[QueryParameter('filter[search]', 'Search within payload or exception.', type: 'string', example: 'SendEmail')]
    #[QueryParameter('sort', 'Sort field. Prefix with `-` for descending. Default: -id.', type: 'string', example: '-id')]
    #[QueryParameter('per_page', 'Results per page (default 15).', type: 'integer', example: '15')]
    #[QueryParameter('page', 'Page number.', type: 'integer', example: '1')]
    #[QueryParameter('all', 'Return all records without pagination.', type: 'boolean', example: 'true')]
    public function getFailedJobs(Request $request): AnonymousResourceCollection
    {
        $query = QueryBuilder::for(FailedJob::class)
            ->allowedFilters(
                AllowedFilter::exact('queue'),
                AllowedFilter::callback('search', function ($q, $value) {
                    $q->where('payload', 'like', '%'.$value.'%')
                        ->orWhere('exception', 'like', '%'.$value.'%');
                }),
            )
            ->allowedSorts('id', 'queue', 'failed_at')
            ->defaultSort('-id');

        if ($request->boolean('all')) {
            return FailedJobResource::collection($query->get());
        }

        return FailedJobResource::collection(
            $query->paginate($request->integer('per_page', 15))->appends($request->query())
        );
    }

    /**
     * Get queue job statistics.
     *
     * Returns total queued and failed job counts, plus a per-queue breakdown.
     */
    public function getJobStats(): JsonResponse
    {
        $byQueue = Job::query()
            ->select('queue')
            ->selectRaw('count(*) as total')
            ->groupBy('queue')
            ->orderByDesc('total')
            ->get();

        return response()->json([
            'total_queued' => Job::count(),
            'total_failed' => FailedJob::count(),
            'by_queue' => $byQueue,
        ]);
    }

    /**
     * Delete multiple queued jobs.
     *
     * Permanently removes jobs from the queue by ID.
     */
    #[BodyParameter('ids', 'Array of job IDs to delete.', required: true, type: 'array', example: '[1, 2, 3]')]
    public function bulkDestroyJobs(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:jobs,id'],
        ]);

        $ids = $validated['ids'];

        Job::destroy($ids);

        $this->logActivity('jobs_bulk_deleted', count($ids).' queued jobs were deleted.', ['job_ids' => $ids, 'module' => 'Queue']);

        return response()->json(null, 204);
    }

    /**
     * Delete multiple failed jobs.
     *
     * Permanently removes entries from the failed_jobs table by ID.
     */
    #[BodyParameter('ids', 'Array of failed job IDs to delete.', required: true, type: 'array', example: '[1, 2, 3]')]
    public function bulkDestroyFailedJobs(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:failed_jobs,id'],
        ]);

        $ids = $validated['ids'];

        FailedJob::destroy($ids);

        $this->logActivity('failed_jobs_bulk_deleted', count($ids).' failed jobs were deleted.', ['failed_job_ids' => $ids, 'module' => 'Queue']);

        return response()->json(null, 204);
    }
}
