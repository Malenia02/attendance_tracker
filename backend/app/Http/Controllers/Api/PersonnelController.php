<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Personnel;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

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
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:10,100'],
        ]);

        $query = Personnel::query()
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
            ->orderBy('first_name');
        $pagination = null;

        if (isset($validated['per_page'])) {
            $paginator = $query->paginate(
                $validated['per_page'],
                ['*'],
                'page',
                $validated['page'] ?? 1
            );
            $personnel = collect($paginator->items());
            $pagination = [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ];
        } else {
            $personnel = $query->get();
        }

        return response()->json([
            'data' => $personnel->map(fn (Personnel $person) => $this->formatPersonnel($person)),
            'meta' => $pagination ? ['pagination' => $pagination] : null,
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
        $validated = $request->validate($this->rules($request));
        $generateEmployeeNumber = $validated['personnel_type'] === 'GIP'
            || $request->boolean('auto_generate_employee_number');
        unset($validated['remove_photo'], $validated['auto_generate_employee_number']);

        if ($generateEmployeeNumber) {
            $validated['employee_number'] = 'PENDING-'.Str::uuid();
        }

        $photoPath = $this->storePhoto($request);

        if ($photoPath) {
            $validated['photo'] = $photoPath;
        } else {
            unset($validated['photo']);
        }

        $validated = $this->normalizeCredentialValidity($validated);
        $validated['qr_login_code'] = hash('sha256', Str::random(64));

        try {
            $personnel = DB::transaction(function () use ($validated, $generateEmployeeNumber): Personnel {
                $personnel = Personnel::create($validated);

                if ($generateEmployeeNumber) {
                    $personnel->forceFill([
                        'employee_number' => $this->generatedEmployeeNumber($personnel),
                    ])->save();
                }

                return $personnel;
            });
        } catch (Throwable $exception) {
            if ($photoPath) {
                Storage::disk('local')->delete($photoPath);
            }

            throw $exception;
        }

        $personnel->load(['department', 'user']);

        return response()->json([
            'message' => 'Personnel record created successfully.',
            'data' => $this->formatPersonnel($personnel),
        ], 201);
    }

    public function update(Request $request, Personnel $personnel): JsonResponse
    {
        $validated = $request->validate($this->rules($request, $personnel));
        unset($validated['remove_photo'], $validated['auto_generate_employee_number']);
        $validated = $this->normalizeCredentialValidity($validated, $personnel);
        $wasGip = $personnel->personnel_type === 'GIP';
        $willBeGip = $validated['personnel_type'] === 'GIP';

        if ($willBeGip) {
            // Generated GIP numbers are immutable. A department transfer must
            // not silently change the identity printed on existing records.
            unset($validated['employee_number']);
        }

        $oldPhotoPath = $personnel->photo;
        $newPhotoPath = $this->storePhoto($request);

        if ($newPhotoPath) {
            $validated['photo'] = $newPhotoPath;
        } elseif ($request->boolean('remove_photo')) {
            $validated['photo'] = null;
        } else {
            unset($validated['photo']);
        }

        try {
            DB::transaction(function () use ($personnel, $validated, $wasGip, $willBeGip): void {
                $personnel->update($validated);

                if ($willBeGip && ! $wasGip) {
                    $personnel->forceFill([
                        'employee_number' => $this->generatedEmployeeNumber($personnel),
                    ])->save();
                }
            });
        } catch (Throwable $exception) {
            if ($newPhotoPath) {
                Storage::disk('local')->delete($newPhotoPath);
            }

            throw $exception;
        }

        if ($oldPhotoPath && $oldPhotoPath !== $personnel->photo) {
            Storage::disk('local')->delete($oldPhotoPath);
        }

        $personnel->load(['department', 'user']);

        return response()->json([
            'message' => 'Personnel record updated successfully.',
            'data' => $this->formatPersonnel($personnel),
        ]);
    }

    public function destroy(Personnel $personnel): JsonResponse
    {
        return response()->json([
            'message' => 'Personnel records are retained for audit history. Change the status to Inactive, Completed, or Terminated instead.',
        ], 409);
    }

    public function photo(Request $request, Personnel $personnel): BinaryFileResponse|JsonResponse
    {
        Gate::authorize('view', $personnel);

        if (! $personnel->photo || ! Storage::disk('local')->exists($personnel->photo)) {
            return response()->json([
                'message' => 'Personnel photo not found.',
            ], 404);
        }

        return response()->file(
            Storage::disk('local')->path($personnel->photo),
            [
                'Cache-Control' => 'private, max-age=86400',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }

    private function rules(Request $request, ?Personnel $personnel = null): array
    {
        $autoGenerateEmployeeNumber = $request->input('personnel_type') === 'GIP'
            || $request->boolean('auto_generate_employee_number');

        return [
            'auto_generate_employee_number' => ['sometimes', 'boolean'],
            'employee_number' => [
                'nullable',
                Rule::requiredIf(! $autoGenerateEmployeeNumber),
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
            'department_id' => [
                'nullable',
                Rule::requiredIf($autoGenerateEmployeeNumber),
                'integer',
                'exists:departments,department_id',
            ],
            'employment_start_date' => ['nullable', 'date'],
            'employment_end_date' => ['nullable', 'date', 'after_or_equal:employment_start_date'],
            'qr_valid_from' => ['nullable', 'date'],
            'qr_valid_until' => ['nullable', 'date', 'after_or_equal:qr_valid_from'],
            'email' => [
                'nullable',
                'email:rfc',
                'max:150',
                Rule::unique('personnel', 'email')
                    ->ignore($personnel?->personnel_id, 'personnel_id'),
            ],
            'contact_number' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:255'],
            'photo' => [
                'nullable',
                'file',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:3072',
                'dimensions:min_width=128,min_height=128,max_width=4000,max_height=4000',
            ],
            'remove_photo' => ['sometimes', 'boolean'],
            'status' => ['required', Rule::in(self::STATUSES)],
        ];
    }

    private function generatedEmployeeNumber(Personnel $personnel): string
    {
        $departmentCode = (string) Department::query()
            ->whereKey($personnel->department_id)
            ->value('department_code');
        $officeCode = trim(
            (string) preg_replace('/[^A-Z0-9]+/', '-', Str::upper($departmentCode)),
            '-'
        );
        $officeCode = Str::limit($officeCode ?: 'OFFICE', 15, '');
        $year = $personnel->employment_start_date?->year ?? now()->year;
        $prefix = match ($personnel->personnel_type) {
            'GIP' => 'GIP',
            'Regular' => 'REG',
            'Contractual' => 'CON',
            'Job Order' => 'JO',
            'Casual' => 'CAS',
            default => 'OTH',
        };

        return sprintf(
            '%s-%s-%d-%04d',
            $prefix,
            $officeCode,
            $year,
            $personnel->personnel_id
        );
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
            'qr_valid_from' => $personnel->qr_valid_from?->format('Y-m-d'),
            'qr_valid_until' => $personnel->qr_valid_until?->format('Y-m-d'),
            'email' => $personnel->email,
            'contact_number' => $personnel->contact_number,
            'address' => $personnel->address,
            'photo_url' => $personnel->photo
                ? route(
                    'personnel.photo',
                    ['personnel' => $personnel],
                    config('app.frontend_deployment') === 'external'
                        && ! config('app.frontend_api_proxy')
                )
                    .'?v='.($personnel->updated_at?->timestamp ?? 0)
                : null,
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

    private function normalizeCredentialValidity(
        array $validated,
        ?Personnel $personnel = null
    ): array {
        $parseDate = static fn ($value): ?Carbon => filled($value)
            ? Carbon::parse($value)->startOfDay()
            : null;
        $employmentStart = $parseDate(
            array_key_exists('employment_start_date', $validated)
                ? $validated['employment_start_date']
                : $personnel?->employment_start_date
        );
        $employmentEnd = $parseDate(
            array_key_exists('employment_end_date', $validated)
                ? $validated['employment_end_date']
                : $personnel?->employment_end_date
        );
        $today = today();
        $defaultFrom = $employmentStart?->gt($today)
            ? $employmentStart->copy()
            : $today->copy();

        if ($employmentEnd?->lt($defaultFrom)) {
            $defaultFrom = $employmentStart && $employmentStart->lte($employmentEnd)
                ? $employmentStart->copy()
                : $employmentEnd->copy();
        }

        $validFrom = array_key_exists('qr_valid_from', $validated)
            ? ($parseDate($validated['qr_valid_from']) ?? $defaultFrom)
            : ($personnel?->qr_valid_from?->copy() ?? $defaultFrom);
        $defaultUntil = $validFrom->copy()->addYear()->subDay();

        if ($employmentEnd && $employmentEnd->lt($defaultUntil)) {
            $defaultUntil = $employmentEnd->copy();
        }

        $validUntil = array_key_exists('qr_valid_until', $validated)
            ? ($parseDate($validated['qr_valid_until']) ?? $defaultUntil)
            : ($personnel?->qr_valid_until?->copy() ?? $defaultUntil);
        $errors = [];

        if ($validUntil->lt($validFrom)) {
            $errors['qr_valid_until'][] = 'The card expiry date must be on or after its valid-from date.';
        }

        if ($employmentStart && $validFrom->lt($employmentStart)) {
            $errors['qr_valid_from'][] = 'Card validity cannot begin before employment starts.';
        }

        if ($employmentEnd && $validUntil->gt($employmentEnd)) {
            $errors['qr_valid_until'][] = 'Card validity cannot extend beyond the employment end date.';
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $validated['qr_valid_from'] = $validFrom->toDateString();
        $validated['qr_valid_until'] = $validUntil->toDateString();

        return $validated;
    }

    private function storePhoto(Request $request): ?string
    {
        if (! $request->hasFile('photo')) {
            return null;
        }

        $path = $request->file('photo')->store('personnel-photos', 'local');

        abort_if(! $path, 500, 'The personnel photo could not be stored.');

        return $path;
    }
}
