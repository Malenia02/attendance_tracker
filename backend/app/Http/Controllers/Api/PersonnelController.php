<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Personnel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PersonnelController extends Controller
{
    private const TYPES = ['GIP', 'Regular', 'Contractual', 'Job Order', 'Casual', 'Other'];

    private const STATUSES = ['Active', 'Inactive', 'Completed', 'Terminated'];

    private const SEX_OPTIONS = ['Male', 'Female', 'Prefer Not to Say'];

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::in(self::TYPES)],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'department_id' => ['nullable', 'integer', 'exists:departments,department_id'],
        ]);

        $personnel = Personnel::query()
            ->with([
                'department:department_id,department_code,department_name',
                'user:user_id,personnel_id,username,user_role,status',
            ])
            ->when($validated['search'] ?? null, function ($query, string $search): void {
                $query->where(function ($query) use ($search): void {
                    $query
                        ->where('employee_number', 'like', "%{$search}%")
                        ->orWhere('biometric_number', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('middle_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('position_title', 'like', "%{$search}%");
                });
            })
            ->when($validated['type'] ?? null, fn ($query, string $type) => $query->where('personnel_type', $type))
            ->when($validated['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when(
                $validated['department_id'] ?? null,
                fn ($query, int $departmentId) => $query->where('department_id', $departmentId)
            )
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        return response()->json([
            'data' => $personnel->map(fn (Personnel $person) => $this->formatPersonnel($person)),
            'summary' => [
                'total' => Personnel::count(),
                'active' => Personnel::where('status', 'Active')->count(),
                'gip' => Personnel::where('personnel_type', 'GIP')->count(),
                'other_staff' => Personnel::where('personnel_type', '!=', 'GIP')->count(),
            ],
        ]);
    }

    public function options(): JsonResponse
    {
        return response()->json([
            'types' => self::TYPES,
            'statuses' => self::STATUSES,
            'sex_options' => self::SEX_OPTIONS,
            'departments' => Department::query()
                ->where('status', 'Active')
                ->orderBy('department_name')
                ->get(['department_id', 'department_code', 'department_name']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $personnel = Personnel::create($request->validate($this->rules()));
        $personnel->load(['department', 'user']);

        return response()->json([
            'message' => 'Personnel record created successfully.',
            'data' => $this->formatPersonnel($personnel),
        ], 201);
    }

    public function update(Request $request, Personnel $personnel): JsonResponse
    {
        $personnel->update($request->validate($this->rules($personnel)));
        $personnel->load(['department', 'user']);

        return response()->json([
            'message' => 'Personnel record updated successfully.',
            'data' => $this->formatPersonnel($personnel),
        ]);
    }

    public function destroy(Personnel $personnel): JsonResponse
    {
        $linkedRecords = collect([
            'system user' => $personnel->user()->exists(),
            'attendance records' => $personnel->attendanceRecords()->exists(),
            'time logs' => $personnel->timeLogs()->exists(),
            'schedule assignments' => $personnel->scheduleAssignments()->exists(),
            'leave records' => $personnel->leaveRecords()->exists(),
            'DTR certifications' => $personnel->dtrCertifications()->exists(),
        ])->filter()->keys();

        if ($linkedRecords->isNotEmpty()) {
            return response()->json([
                'message' => 'This personnel record cannot be deleted because it has linked '
                    .$linkedRecords->join(', ', ' and ')
                    .'. Set its status to Inactive instead.',
            ], 422);
        }

        $personnel->delete();

        return response()->json([
            'message' => 'Personnel record deleted successfully.',
        ]);
    }

    private function rules(?Personnel $personnel = null): array
    {
        return [
            'employee_number' => [
                'required',
                'string',
                'max:50',
                Rule::unique('personnel', 'employee_number')
                    ->ignore($personnel?->personnel_id, 'personnel_id'),
            ],
            'biometric_number' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('personnel', 'biometric_number')
                    ->ignore($personnel?->personnel_id, 'personnel_id'),
            ],
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'suffix' => ['nullable', 'string', 'max:20'],
            'sex' => ['nullable', Rule::in(self::SEX_OPTIONS)],
            'personnel_type' => ['required', Rule::in(self::TYPES)],
            'position_title' => ['nullable', 'string', 'max:150'],
            'department_id' => ['nullable', 'integer', 'exists:departments,department_id'],
            'employment_start_date' => ['nullable', 'date'],
            'employment_end_date' => ['nullable', 'date', 'after_or_equal:employment_start_date'],
            'email' => [
                'nullable',
                'email:rfc',
                'max:150',
                Rule::unique('personnel', 'email')
                    ->ignore($personnel?->personnel_id, 'personnel_id'),
            ],
            'contact_number' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in(self::STATUSES)],
        ];
    }

    private function formatPersonnel(Personnel $personnel): array
    {
        return [
            'personnel_id' => $personnel->personnel_id,
            'employee_number' => $personnel->employee_number,
            'biometric_number' => $personnel->biometric_number,
            'first_name' => $personnel->first_name,
            'middle_name' => $personnel->middle_name,
            'last_name' => $personnel->last_name,
            'suffix' => $personnel->suffix,
            'full_name' => $personnel->full_name,
            'sex' => $personnel->sex,
            'personnel_type' => $personnel->personnel_type,
            'position_title' => $personnel->position_title,
            'department_id' => $personnel->department_id,
            'department' => $personnel->department ? [
                'department_id' => $personnel->department->department_id,
                'department_code' => $personnel->department->department_code,
                'department_name' => $personnel->department->department_name,
            ] : null,
            'employment_start_date' => $personnel->employment_start_date?->format('Y-m-d'),
            'employment_end_date' => $personnel->employment_end_date?->format('Y-m-d'),
            'email' => $personnel->email,
            'contact_number' => $personnel->contact_number,
            'address' => $personnel->address,
            'status' => $personnel->status,
            'system_user' => $personnel->user ? [
                'user_id' => $personnel->user->user_id,
                'username' => $personnel->user->username,
                'user_role' => $personnel->user->user_role,
                'status' => $personnel->user->status,
            ] : null,
            'created_at' => $personnel->created_at?->toISOString(),
        ];
    }
}
