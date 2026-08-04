<?php

namespace App\Services;

use App\Models\Personnel;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

final class PersonnelOnboardingService
{
    private static ?bool $enforcementEnabled = null;

    public function evaluate(Personnel $personnel, Carbon|string|null $referenceDate = null): array
    {
        $date = $referenceDate instanceof Carbon
            ? $referenceDate->copy()->startOfDay()
            : Carbon::parse($referenceDate ?? today())->startOfDay();

        $personnel->loadMissing([
            'department',
            'user',
            'scheduleAssignments.schedule',
        ]);

        $identityComplete = filled($personnel->employee_number)
            && filled($personnel->first_name)
            && filled($personnel->last_name)
            && filled($personnel->personnel_type);
        $departmentComplete = $personnel->department?->status === 'Active';
        $employmentComplete = $personnel->employment_start_date
            && $personnel->employment_start_date->lte($date)
            && (! $personnel->employment_end_date || $personnel->employment_end_date->gte($date));
        $scheduleComplete = $personnel->scheduleAssignments->contains(
            fn ($assignment) => $assignment->effective_from->lte($date)
                && (! $assignment->effective_to || $assignment->effective_to->gte($date))
                && $assignment->schedule?->status === 'Active'
        );
        $accountComplete = $personnel->user?->status === 'Active';
        $credentialComplete = filled($personnel->qr_login_code)
            && $personnel->qr_valid_from
            && $personnel->qr_valid_until
            && $personnel->qr_valid_from->lte($date)
            && $personnel->qr_valid_until->gte($date);

        $requirements = collect([
            $this->step('identity', 'Personnel profile', $identityComplete, 'Complete the required identity fields.'),
            $this->step('department', 'Active department', $departmentComplete, 'Assign an active department or office.'),
            $this->step('employment', 'Current employment period', (bool) $employmentComplete, 'Set a start date covering the activation date.'),
            $this->step('schedule', 'Effective work schedule', $scheduleComplete, 'Assign an active schedule effective on the activation date.'),
            $this->step('account', 'Active system account', $accountComplete, 'Link an active system user account.'),
            $this->step('credential', 'Valid QR credential', (bool) $credentialComplete, 'Configure a currently valid QR card period.'),
        ]);
        $completed = $requirements->where('complete', true)->count();
        $complete = $completed === $requirements->count();
        $draft = $personnel->status === 'Inactive'
            && ! $personnel->department_id
            && ! $personnel->employment_start_date
            && ! $personnel->user
            && $personnel->scheduleAssignments->isEmpty();
        $state = match (true) {
            in_array($personnel->status, ['Completed', 'Terminated'], true) => 'Inactive',
            $complete && $personnel->status === 'Active' => 'Active',
            $complete => 'Ready',
            $draft => 'Draft',
            default => 'Setup Required',
        };

        return [
            'state' => $state,
            'is_ready' => $complete,
            'is_operational' => $complete && $personnel->status === 'Active',
            'progress' => (int) round(($completed / $requirements->count()) * 100),
            'completed_requirements' => $completed,
            'total_requirements' => $requirements->count(),
            'missing' => $requirements->where('complete', false)->pluck('code')->values()->all(),
            'requirements' => $requirements->values()->all(),
            'recommendations' => [
                $this->step('photo', 'Personnel photo', filled($personnel->photo), 'Upload a clear ID photo.'),
                $this->step('signature', 'Signature image', filled($personnel->signature), 'Upload a signature when required for printed records.'),
                $this->step(
                    'office_gps',
                    'Office GPS',
                    (bool) ($personnel->department?->latitude && $personnel->department?->longitude),
                    'Configure office coordinates for QR geofencing.'
                ),
            ],
            'reference_date' => $date->toDateString(),
        ];
    }

    public function operationalBlockReason(Personnel $personnel, Carbon|string|null $date = null): ?string
    {
        if (! $this->enforcementEnabled()) {
            return null;
        }

        $readiness = $this->evaluate($personnel, $date);

        if ($readiness['is_operational']) {
            return null;
        }

        $labels = collect($readiness['requirements'])
            ->where('complete', false)
            ->pluck('label')
            ->take(3)
            ->implode(', ');

        return 'Attendance setup is incomplete for this personnel record. Required: '
            .($labels ?: 'an active personnel status').'. Ask Administrator or HR to finish onboarding.';
    }

    public function applyOperationalScope(Builder $query, Carbon|string|null $date = null): Builder
    {
        if (! $this->enforcementEnabled()) {
            return $query;
        }

        return $this->applyCompleteScope($query, $this->date($date))
            ->where('status', 'Active');
    }

    public function applyStateScope(Builder $query, string $state, Carbon|string|null $date = null): Builder
    {
        $date = $this->date($date);

        return match ($state) {
            'Active' => $this->applyCompleteScope($query->where('status', 'Active'), $date),
            'Ready' => $this->applyCompleteScope($query->where('status', 'Inactive'), $date),
            'Inactive' => $query->whereIn('status', ['Completed', 'Terminated']),
            'Draft' => $this->applyDraftScope($query),
            'Setup Required' => $this->applySetupRequiredScope($query, $date),
            default => $query,
        };
    }

    public function enforcementEnabled(): bool
    {
        if (app()->environment('testing')) {
            return Schema::hasTable('personnel_activation_logs');
        }

        return self::$enforcementEnabled ??= Schema::hasTable('personnel_activation_logs');
    }

    private function applyCompleteScope(Builder $query, string $date): Builder
    {
        return $query
            ->whereNotNull('employee_number')
            ->whereNotNull('first_name')
            ->whereNotNull('last_name')
            ->whereNotNull('personnel_type')
            ->whereNotNull('employment_start_date')
            ->whereDate('employment_start_date', '<=', $date)
            ->where(fn (Builder $query) => $query
                ->whereNull('employment_end_date')
                ->orWhereDate('employment_end_date', '>=', $date))
            ->whereNotNull('qr_login_code')
            ->whereDate('qr_valid_from', '<=', $date)
            ->whereDate('qr_valid_until', '>=', $date)
            ->whereHas('department', fn (Builder $query) => $query->where('status', 'Active'))
            ->whereHas('user', fn (Builder $query) => $query->where('status', 'Active'))
            ->whereHas('scheduleAssignments', fn (Builder $query) => $query
                ->whereDate('effective_from', '<=', $date)
                ->where(fn (Builder $query) => $query
                    ->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $date))
                ->whereHas('schedule', fn (Builder $query) => $query->where('status', 'Active')));
    }

    private function applyIncompleteScope(Builder $query, string $date): Builder
    {
        return $query->where(function (Builder $query) use ($date): void {
            $query
                ->whereNull('employee_number')
                ->orWhereNull('first_name')
                ->orWhereNull('last_name')
                ->orWhereNull('personnel_type')
                ->orWhereNull('employment_start_date')
                ->orWhereDate('employment_start_date', '>', $date)
                ->orWhereDate('employment_end_date', '<', $date)
                ->orWhereNull('qr_login_code')
                ->orWhereNull('qr_valid_from')
                ->orWhereDate('qr_valid_from', '>', $date)
                ->orWhereNull('qr_valid_until')
                ->orWhereDate('qr_valid_until', '<', $date)
                ->orWhereDoesntHave('department', fn (Builder $query) => $query->where('status', 'Active'))
                ->orWhereDoesntHave('user', fn (Builder $query) => $query->where('status', 'Active'))
                ->orWhereDoesntHave('scheduleAssignments', fn (Builder $query) => $query
                    ->whereDate('effective_from', '<=', $date)
                    ->where(fn (Builder $query) => $query
                        ->whereNull('effective_to')
                        ->orWhereDate('effective_to', '>=', $date))
                    ->whereHas('schedule', fn (Builder $query) => $query->where('status', 'Active')));
        });
    }

    private function applyDraftScope(Builder $query): Builder
    {
        return $query
            ->where('status', 'Inactive')
            ->whereNull('department_id')
            ->whereNull('employment_start_date')
            ->whereDoesntHave('user')
            ->whereDoesntHave('scheduleAssignments');
    }

    private function applySetupRequiredScope(Builder $query, string $date): Builder
    {
        $this->applyIncompleteScope($query->whereIn('status', ['Active', 'Inactive']), $date);

        return $query->where(function (Builder $query): void {
            $query
                ->where('status', '!=', 'Inactive')
                ->orWhereNotNull('department_id')
                ->orWhereNotNull('employment_start_date')
                ->orWhereHas('user')
                ->orWhereHas('scheduleAssignments');
        });
    }

    private function step(string $code, string $label, bool $complete, string $action): array
    {
        return compact('code', 'label', 'complete', 'action');
    }

    private function date(Carbon|string|null $date): string
    {
        return ($date instanceof Carbon ? $date : Carbon::parse($date ?? today()))
            ->toDateString();
    }
}
