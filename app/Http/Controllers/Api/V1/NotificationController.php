<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\PerPage;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;

/**
 * The signed-in person's own notices: the bell. Nobody reads anybody else's,
 * Admins included, so there is no permission to hold: the query is always
 * `$request->user()->notifications()`.
 */
#[Group('Notifications', 'The bell: your own notices.', weight: 16)]
class NotificationController extends Controller
{
    #[QueryParameter('filter[unread]', 'Only notices not yet read.', type: 'boolean')]
    public function index(Request $request): JsonResponse
    {
        $page = $this->visible($request)
            ->when($request->boolean('filter.unread'), fn ($q) => $q->whereNull('read_at'))
            ->paginate(PerPage::from($request, 10, 50))
            ->appends($request->query());

        return response()->json([
            'data' => $this->presentAll(collect($page->items())),
            'meta' => [
                'total' => $page->total(),
                'per_page' => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
            ],
            'unread_count' => $this->visible($request)->whereNull('read_at')->count(),
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['unread_count' => $this->visible($request)->whereNull('read_at')->count()]);
    }

    /**
     * Read, once or twice: a notice already read, or removed in another tab, is not an error. The
     * bell marks a notice read as it scrolls into view, so the same id can arrive more than once.
     */
    public function markRead(Request $request, string $id): JsonResponse
    {
        $request->user()->notifications()->whereKey($id)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['unread_count' => $this->visible($request)->whereNull('read_at')->count()]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['message' => 'All caught up.', 'unread_count' => 0]);
    }

    /** Removed, once or twice: a second press (or another tab) finds it gone, which is what was asked. */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $request->user()->notifications()->whereKey($id)->delete();

        return response()->json(['message' => 'Notification removed.']);
    }

    /**
     * The person's notices, newest first.
     *
     * @return Builder<DatabaseNotification>
     */
    private function visible(Request $request): Builder
    {
        // The id breaks ties: notices written in the same second (a busy sale) otherwise swap
        // places between pages, and Load more shows one twice and skips one.
        return $request->user()->notifications()->getQuery()->reorder()->orderByDesc('created_at')->orderByDesc('id');
    }

    /**
     * @param  Collection<int, DatabaseNotification>  $items
     * @return Collection<int, array<string, mixed>>
     */
    private function presentAll(Collection $items): Collection
    {
        return $items->map(fn (DatabaseNotification $n) => $this->present($n))->values();
    }

    /** @return array<string, mixed> */
    private function present(DatabaseNotification $n): array
    {
        return [
            'id' => $n->id,
            ...$n->data,
            'read_at' => $n->read_at,
            'created_at' => $n->created_at,
        ];
    }
}
