<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Personnel;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        ]);

        $users = User::query()
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
            ->orderBy('username')
            ->get();

        return response()->json([
            'data' => $users->map(fn (User $user) => $this->formatUser($user)),
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

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules());

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

    public function update(Request $request, User $systemUser): JsonResponse
    {
        $validated = $request->validate($this->rules($systemUser));

        if ($this->wouldRemoveLastActiveAdministrator($systemUser, $validated)) {
            return response()->json([
                'message' => 'The final active administrator cannot be deactivated or assigned another role.',
                'errors' => [
                    'user_role' => ['At least one active administrator is required.'],
                ],
            ], 422);
        }

        $systemUser->fill([
            'personnel_id' => $validated['personnel_id'] ?? null,
            'username' => $validated['username'],
            'user_role' => $validated['user_role'],
            'status' => $validated['status'],
        ]);

        if (! empty($validated['password'])) {
            $systemUser->password_hash = Hash::make($validated['password']);
        }

        if ($validated['status'] === 'Active') {
            $systemUser->failed_login_attempts = 0;
            $systemUser->locked_until = null;
        }

        $systemUser->save();
        $systemUser->load('personnel');

        return response()->json([
            'message' => 'System user updated successfully.',
            'data' => $this->formatUser($systemUser),
        ]);
    }

    public function destroy(User $systemUser): JsonResponse
    {
        if (
            $systemUser->user_role === 'Administrator'
            && $systemUser->status === 'Active'
            && User::where('user_role', 'Administrator')->where('status', 'Active')->count() <= 1
        ) {
            return response()->json([
                'message' => 'The final active administrator cannot be deleted.',
            ], 422);
        }

        $systemUser->delete();

        return response()->json([
            'message' => 'System user deleted successfully.',
        ]);
    }

    private function rules(?User $user = null): array
    {
        return [
            'personnel_id' => [
                'nullable',
                'integer',
                'exists:personnel,personnel_id',
                Rule::unique('system_users', 'personnel_id')->ignore($user?->user_id, 'user_id'),
            ],
            'username' => [
                'required',
                'string',
                'min:3',
                'max:100',
                'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique('system_users', 'username')->ignore($user?->user_id, 'user_id'),
            ],
            'password' => [
                $user ? 'nullable' : 'required',
                'string',
                'min:8',
                'max:72',
                'confirmed',
            ],
            'user_role' => ['required', Rule::in(self::ROLES)],
            'status' => ['required', Rule::in(self::STATUSES)],
        ];
    }

    private function wouldRemoveLastActiveAdministrator(User $user, array $validated): bool
    {
        if ($user->user_role !== 'Administrator' || $user->status !== 'Active') {
            return false;
        }

        $remainsActiveAdministrator = $validated['user_role'] === 'Administrator'
            && $validated['status'] === 'Active';

        return ! $remainsActiveAdministrator
            && User::where('user_role', 'Administrator')->where('status', 'Active')->count() <= 1;
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
