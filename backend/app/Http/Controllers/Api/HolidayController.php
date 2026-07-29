<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Holiday;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class HolidayController extends Controller
{
    private const TYPES = [
        'Regular Holiday',
        'Special Non-Working Holiday',
        'Special Working Holiday',
        'Local Holiday',
        'Office Suspension',
    ];

    private const SCOPES = ['National', 'Regional', 'Provincial', 'Municipal', 'Office'];

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'department_id' => ['nullable', 'integer', 'exists:departments,department_id'],
        ]);
        $year = $validated['year'] ?? now()->year;

        $holidays = Holiday::query()
            ->with([
                'department:department_id,department_code,department_name',
                'creator:user_id,username',
            ])
            ->whereYear('holiday_date', $year)
            ->when(
                $validated['department_id'] ?? null,
                fn ($query, int $departmentId) => $query->where(function ($query) use ($departmentId): void {
                    $query->whereNull('department_id')->orWhere('department_id', $departmentId);
                })
            )
            ->orderBy('holiday_date')
            ->orderBy('holiday_name')
            ->get();

        return response()->json([
            'data' => $holidays->map(fn (Holiday $holiday) => $this->formatHoliday($holiday)),
            'summary' => [
                'year' => $year,
                'total' => $holidays->count(),
                'regular' => $holidays->where('holiday_type', 'Regular Holiday')->count(),
                'special' => $holidays->whereIn('holiday_type', [
                    'Special Non-Working Holiday',
                    'Special Working Holiday',
                ])->count(),
                'local' => $holidays->whereIn('holiday_type', [
                    'Local Holiday',
                    'Office Suspension',
                ])->count(),
            ],
        ]);
    }

    public function options(): JsonResponse
    {
        return response()->json([
            'types' => self::TYPES,
            'scopes' => self::SCOPES,
            'departments' => Department::query()
                ->where('status', 'Active')
                ->orderBy('department_name')
                ->get(['department_id', 'department_code', 'department_name']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules());
        $validated['created_by'] = $request->user()->user_id;

        $holiday = Holiday::create($validated);
        $holiday->load(['department', 'creator']);

        return response()->json([
            'message' => $holiday->holiday_type === 'Special Working Holiday'
                ? 'Working day added to the calendar.'
                : 'Holiday added to the calendar.',
            'data' => $this->formatHoliday($holiday),
        ], 201);
    }

    public function update(Request $request, Holiday $holiday): JsonResponse
    {
        $holiday->update($request->validate($this->rules($holiday)));
        $holiday->load(['department', 'creator']);

        return response()->json([
            'message' => $holiday->holiday_type === 'Special Working Holiday'
                ? 'Working day updated successfully.'
                : 'Holiday updated successfully.',
            'data' => $this->formatHoliday($holiday),
        ]);
    }

    public function destroy(Holiday $holiday): JsonResponse
    {
        $isWorkingDay = $holiday->holiday_type === 'Special Working Holiday';
        $holiday->delete();

        return response()->json([
            'message' => $isWorkingDay
                ? 'Working day removed from the calendar.'
                : 'Holiday removed from the calendar.',
        ]);
    }

    private function rules(?Holiday $holiday = null): array
    {
        return [
            'holiday_date' => ['required', 'date'],
            'holiday_name' => [
                'required',
                'string',
                'max:150',
                Rule::unique('holidays', 'holiday_name')
                    ->where(fn ($query) => $query->where('holiday_date', request('holiday_date')))
                    ->ignore($holiday?->holiday_id, 'holiday_id'),
            ],
            'holiday_type' => [
                'required',
                Rule::in(self::TYPES),
                Rule::unique('holidays', 'holiday_type')
                    ->where(function ($query) {
                        $query->where('holiday_date', request('holiday_date'));

                        return request('scope') === 'National'
                            ? $query->whereNull('department_id')
                            : $query->where('department_id', request('department_id'));
                    })
                    ->ignore($holiday?->holiday_id, 'holiday_id'),
            ],
            'scope' => ['required', Rule::in(self::SCOPES)],
            'department_id' => [
                'nullable',
                'integer',
                'exists:departments,department_id',
                Rule::requiredIf(fn (): bool => request('scope') !== 'National'),
                Rule::prohibitedIf(fn (): bool => request('scope') === 'National'),
            ],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }

    private function formatHoliday(Holiday $holiday): array
    {
        return [
            'holiday_id' => $holiday->holiday_id,
            'holiday_date' => $holiday->holiday_date->format('Y-m-d'),
            'holiday_name' => $holiday->holiday_name,
            'holiday_type' => $holiday->holiday_type,
            'scope' => $holiday->scope,
            'department_id' => $holiday->department_id,
            'description' => $holiday->description,
            'department' => $holiday->department ? [
                'department_id' => $holiday->department->department_id,
                'department_code' => $holiday->department->department_code,
                'department_name' => $holiday->department->department_name,
            ] : null,
            'creator' => $holiday->creator ? [
                'user_id' => $holiday->creator->user_id,
                'username' => $holiday->creator->username,
            ] : null,
            'created_at' => $holiday->created_at?->toISOString(),
        ];
    }
}
