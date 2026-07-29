<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SystemUserRequest;
use App\Models\Personnel;
use App\Models\User;
use App\Services\SessionRevoker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class SystemUserController extends Controller
{
    private const ROLES = [
        'Administrator',
        'HR',
        'Supervisor',
        'Encoder',
        'Personnel',
    ];

    private const STATUSES = [
        'Active',
        'Inactive',
        'Locked',
    ];

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::in(self::ROLES)],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:10,100'],
        ]);

        $query = User::query()
            ->with('personnel:personnel_id,employee_number,first_name,middle_name,last_name,suffix,email')
            ->when($validated['search'] ?? null, function ($query, string $search): void {
                $query->where(function ($query) use ($search): void {
                    $query
                        ->where('username', 'like', "%{$search}%")
                        ->orWhereHas('personnel', function ($query) use ($search): void {
                            $query
                                ->where('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%")
                                ->orWhere('employee_number', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        });
                });
            })
            ->when($validated['role'] ?? null, fn ($query, string $role) => $query->where('user_role', $role))
            ->when($validated['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->orderBy('username');
        $pagination = null;

        if (isset($validated['per_page'])) {
            $paginator = $query->paginate(
                $validated['per_page'],
                ['*'],
                'page',
                $validated['page'] ?? 1
            );
            $users = collect($paginator->items());
            $pagination = [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ];
        } else {
            $users = $query->get();
        }

        return response()->json([
            'data' => $users->map(fn (User $user) => $this->formatUser($user)),
            'meta' => $pagination ? ['pagination' => $pagination] : null,
            'summary' => [
                'total' => User::count(),
                'active' => User::where('status', 'Active')->count(),
                'inactive' => User::where('status', 'Inactive')->count(),
                'locked' => User::where('status', 'Locked')->count(),
            ],
        ]);
    }

    public function options(): JsonResponse
    {
        $personnel = Personnel::query()
            ->with('user:user_id,personnel_id')
            ->select([
                'personnel_id',
                'employee_number',
                'first_name',
                'middle_name',
                'last_name',
                'suffix',
                'email',
                'status',
            ])
            ->where('status', 'Active')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get()
            ->map(fn (Personnel $person) => [
                'personnel_id' => $person->personnel_id,
                'employee_number' => $person->employee_number,
                'full_name' => $person->full_name,
                'email' => $person->email,
                'assigned_user_id' => $person->user?->user_id,
            ]);

        return response()->json([
            'roles' => self::ROLES,
            'statuses' => self::STATUSES,
            'personnel' => $personnel,
        ]);
    }

    public function store(SystemUserRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = User::create([
            'personnel_id' => $validated['personnel_id'] ?? null,
            'username' => $validated['username'],
            'password_hash' => Hash::make($validated['password']),
            'user_role' => $validated['user_role'],
            'status' => $validated['status'],
            'failed_login_attempts' => 0,
            'locked_until' => null,
        ]);

        $user->load('personnel');

        return response()->json([
            'message' => 'System user created successfully.',
            'data' => $this->formatUser($user),
        ], 201);
    }

    public function update(
        SystemUserRequest $request,
        User $systemUser,
        SessionRevoker $sessionRevoker
    ): JsonResponse {
        $validated = $request->validated();

        $result = DB::transaction(function () use (
            $systemUser,
            $validated,
            $sessionRevoker
        ): array {
            $lockedUser = User::query()
                ->whereKey($systemUser->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $activeAdministratorCount = User::query()
                ->where('user_role', 'Administrator')
                ->where('status', 'Active')
                ->lockForUpdate()
                ->get()
                ->count();

            if ($this->wouldRemoveLastActiveAdministrator(
                $lockedUser,
                $validated,
                $activeAdministratorCount
            )) {
                return ['blocked' => true];
            }

            $lockedUser->fill([
                'personnel_id' => $validated['personnel_id'] ?? null,
                'username' => $validated['username'],
                'user_role' => $validated['user_role'],
                'status' => $validated['status'],
            ]);

            if (! empty($validated['password'])) {
                $lockedUser->password_hash = Hash::make($validated['password']);
            }

            if ($validated['status'] === 'Active') {
                $lockedUser->failed_login_attempts = 0;
                $lockedUser->locked_until = null;
            }

            $securityChanged = $lockedUser->isDirty([
                'password_hash',
                'user_role',
                'status',
            ]);
            $lockedUser->save();

            if ($securityChanged) {
                $sessionRevoker->revokeFor($lockedUser);
            }

            return ['blocked' => false, 'user' => $lockedUser];
        });

        if ($result['blocked']) {
            return response()->json([
                'message' => 'The final active administrator cannot be deactivated or assigned another role.',
                'errors' => [
                    'user_role' => ['At least one active administrator is required.'],
                ],
            ], 422);
        }

        $systemUser = $result['user'];
        $systemUser->load('personnel');

        return response()->json([
            'message' => 'System user updated successfully.',
            'data' => $this->formatUser($systemUser),
        ]);
    }

    public function destroy(
        Request $request,
        User $systemUser,
        SessionRevoker $sessionRevoker
    ): JsonResponse {
        if ((int) $request->user()->user_id === (int) $systemUser->user_id) {
            return response()->json([
                'message' => 'You cannot delete the account used by your current session.',
            ], 409);
        }

        $deleted = DB::transaction(function () use ($systemUser, $sessionRevoker): bool {
            $lockedUser = User::query()
                ->whereKey($systemUser->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $activeAdministratorCount = User::query()
                ->where('user_role', 'Administrator')
                ->where('status', 'Active')
                ->lockForUpdate()
                ->get()
                ->count();

            if (
                $lockedUser->user_role === 'Administrator'
                && $lockedUser->status === 'Active'
                && $activeAdministratorCount <= 1
            ) {
                return false;
            }

            $sessionRevoker->revokeFor($lockedUser);
            $lockedUser->delete();

            return true;
        });

        if (! $deleted) {
            return response()->json([
                'message' => 'The final active administrator cannot be deleted.',
            ], 409);
        }

        return response()->json([
            'message' => 'System user deleted successfully.',
        ]);
    }

    private function wouldRemoveLastActiveAdministrator(
        User $user,
        array $validated,
        ?int $activeAdministratorCount = null
    ): bool {
        if ($user->user_role !== 'Administrator' || $user->status !== 'Active') {
            return false;
        }

        $remainsActiveAdministrator = $validated['user_role'] === 'Administrator'
            && $validated['status'] === 'Active';

        return ! $remainsActiveAdministrator
            && ($activeAdministratorCount
                ?? User::where('user_role', 'Administrator')->where('status', 'Active')->count()) <= 1;
    }

    private function formatUser(User $user): array
    {
        return [
            'user_id' => $user->user_id,
            'personnel_id' => $user->personnel_id,
            'username' => $user->username,
            'user_role' => $user->user_role,
            'status' => $user->status,
            'failed_login_attempts' => $user->failed_login_attempts,
            'locked_until' => $user->locked_until?->toISOString(),
            'last_login_at' => $user->last_login_at?->toISOString(),
            'created_at' => $user->created_at?->toISOString(),
            'personnel' => $user->personnel ? [
                'personnel_id' => $user->personnel->personnel_id,
                'employee_number' => $user->personnel->employee_number,
                'full_name' => $user->personnel->full_name,
                'email' => $user->personnel->email,
            ] : null,
        ];
    }
}
