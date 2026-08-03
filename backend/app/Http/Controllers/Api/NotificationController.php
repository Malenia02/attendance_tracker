<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\NotificationIndexRequest;
use App\Http\Resources\UserNotificationResource;
use App\Models\UserNotification;
use App\Services\NotificationSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class NotificationController extends Controller
{
    public function __construct(
        private readonly NotificationSyncService $syncService
    ) {}

    public function index(NotificationIndexRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $this->syncService->syncFor($request->user());
        $query = $this->inboxQuery($request)
            ->when(
                ($validated['status'] ?? 'all') === 'unread',
                fn ($query) => $query->whereNull('read_at')
            );
        $paginator = $query->paginate(
            $validated['per_page'] ?? 15,
            ['*'],
            'page',
            $validated['page'] ?? 1
        );

        return $this->privateResponse([
            'data' => UserNotificationResource::collection($paginator->items())->resolve(),
            'unread_count' => $this->unreadCount($request),
            'meta' => ['pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ]],
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        $this->syncService->syncFor($request->user());
        $latest = $this->inboxQuery($request)->limit(6)->get();

        return $this->privateResponse([
            'unread_count' => $this->unreadCount($request),
            'data' => UserNotificationResource::collection($latest)->resolve(),
        ]);
    }

    public function markRead(Request $request, int $notificationId): JsonResponse
    {
        $notification = $this->ownedNotification($request, $notificationId);

        if ($notification->read_at === null) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        return $this->privateResponse([
            'message' => 'Notification marked as read.',
            'notification' => UserNotificationResource::make($notification->fresh())->resolve(),
            'unread_count' => $this->unreadCount($request),
        ]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $updated = $this->inboxQuery($request)
            ->whereNull('read_at')
            ->update(['read_at' => now(), 'updated_at' => now()]);

        return $this->privateResponse([
            'message' => 'All notifications were marked as read.',
            'updated' => $updated,
            'unread_count' => 0,
        ]);
    }

    private function inboxQuery(Request $request)
    {
        return UserNotification::query()
            ->where('user_id', $request->user()->user_id)
            ->whereNull('resolved_at')
            ->latest('created_at')
            ->latest('notification_id');
    }

    private function unreadCount(Request $request): int
    {
        return $this->inboxQuery($request)->whereNull('read_at')->count();
    }

    private function ownedNotification(Request $request, int $notificationId): UserNotification
    {
        return UserNotification::query()
            ->where('user_id', $request->user()->user_id)
            ->whereNull('resolved_at')
            ->whereKey($notificationId)
            ->firstOrFail();
    }

    private function privateResponse(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status)->withHeaders([
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }
}
