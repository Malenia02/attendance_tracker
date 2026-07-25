<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DepartmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['Active', 'Inactive'])],
        ]);
        $baseQuery = Department::query();
        $departments = Department::query()
            ->withCount(['personnel', 'holidays'])
            ->when($validated['search'] ?? null, function ($query, string $search): void {
                $query->where(function ($query) use ($search): void {
                    $query
                        ->where('department_code', 'like', "%{$search}%")
                        ->orWhere('department_name', 'like', "%{$search}%")
                        ->orWhere('office_location', 'like', "%{$search}%");
                });
            })
            ->when(
                $validated['status'] ?? null,
                fn ($query, string $status) => $query->where('status', $status)
            )
            ->orderBy('department_name')
            ->get();

        return response()->json([
            'data' => $departments->map(fn (Department $department) => $this->formatDepartment($department)),
            'summary' => [
                'total' => (clone $baseQuery)->count(),
                'active' => (clone $baseQuery)->where('status', 'Active')->count(),
                'gps_configured' => (clone $baseQuery)
                    ->whereNotNull('latitude')
                    ->whereNotNull('longitude')
                    ->count(),
                'personnel' => Department::query()->withCount('personnel')->get()->sum('personnel_count'),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $department = Department::create($request->validate($this->rules()));

        return response()->json([
            'message' => 'Department and office location added successfully.',
            'data' => $this->formatDepartment($department->loadCount(['personnel', 'holidays'])),
        ], 201);
    }

    public function update(Request $request, Department $department): JsonResponse
    {
        $department->update($request->validate($this->rules($department)));

        return response()->json([
            'message' => 'Department and office location updated successfully.',
            'data' => $this->formatDepartment($department->loadCount(['personnel', 'holidays'])),
        ]);
    }

    public function destroy(Department $department): JsonResponse
    {
        $department->loadCount(['personnel', 'holidays', 'qrTokens']);

        if ($department->personnel_count || $department->holidays_count || $department->qr_tokens_count) {
            return response()->json([
                'message' => 'This department is already in use. Set it to Inactive instead of deleting it.',
            ], 422);
        }

        $department->delete();

        return response()->json([
            'message' => 'Department removed successfully.',
        ]);
    }

    private function rules(?Department $department = null): array
    {
        return [
            'department_code' => [
                'required',
                'string',
                'max:30',
                Rule::unique('departments', 'department_code')
                    ->ignore($department?->department_id, 'department_id'),
            ],
            'department_name' => ['required', 'string', 'max:150'],
            'office_location' => ['required', 'string', 'max:255'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'allowed_radius_meters' => ['required', 'integer', 'between:25,5000'],
            'status' => ['required', Rule::in(['Active', 'Inactive'])],
        ];
    }

    private function formatDepartment(Department $department): array
    {
        return [
            'department_id' => $department->department_id,
            'department_code' => $department->department_code,
            'department_name' => $department->department_name,
            'office_location' => $department->office_location,
            'latitude' => $department->latitude !== null ? (float) $department->latitude : null,
            'longitude' => $department->longitude !== null ? (float) $department->longitude : null,
            'allowed_radius_meters' => $department->allowed_radius_meters,
            'status' => $department->status,
            'personnel_count' => $department->personnel_count ?? $department->personnel()->count(),
            'holidays_count' => $department->holidays_count ?? $department->holidays()->count(),
            'created_at' => $department->created_at?->toISOString(),
            'updated_at' => $department->updated_at?->toISOString(),
        ];
    }
}
