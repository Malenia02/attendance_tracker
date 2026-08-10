<?php

namespace App\Services;

use App\Models\DtrCertification;
use App\Models\Personnel;
use App\Models\User;
use App\Support\DtrPeriod;
use App\Support\PersonnelAccess;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class DtrCutoffService
{
    public function currentContext(Personnel|string $personnel, ?Carbon $date = null): array
    {
        $date = ($date ?? today())->copy()->startOfDay();
        $month = $date->copy()->startOfMonth();
        $type = $personnel instanceof Personnel ? $personnel->personnel_type : $personnel;
        $period = $this->isGip($type)
            ? ($date->day <= 15 ? DtrPeriod::FIRST_HALF : DtrPeriod::SECOND_HALF)
            : DtrPeriod::FULL_MONTH;

        return $this->context($month, $period);
    }

    public function latestDueContext(Personnel|string $personnel, ?Carbon $date = null): array
    {
        $date = ($date ?? today())->copy()->startOfDay();
        $month = $date->copy()->startOfMonth();
        $type = $personnel instanceof Personnel ? $personnel->personnel_type : $personnel;

        if ($this->isGip($type)) {
            if ($date->day >= $month->daysInMonth) {
                return $this->context($month, DtrPeriod::SECOND_HALF);
            }

            if ($date->day >= 15) {
                return $this->context($month, DtrPeriod::FIRST_HALF);
            }

            return $this->context($month->copy()->subMonth(), DtrPeriod::SECOND_HALF);
        }

        return $date->day >= $month->daysInMonth
            ? $this->context($month, DtrPeriod::FULL_MONTH)
            : $this->context($month->copy()->subMonth(), DtrPeriod::FULL_MONTH);
    }

    public function context(Carbon $month, string $period): array
    {
        $period = DtrPeriod::normalize($period);
        [$start, $end] = DtrPeriod::bounds($month->copy()->startOfMonth(), $period);
        $cutoff = $end->copy()->startOfDay();
        $deadline = $cutoff->copy()
            ->addDays(max(0, (int) config('attendance.dtr_submission_grace_days', 2)))
            ->endOfDay();

        return [
            'month' => $month->format('Y-m'),
            'year' => $month->year,
            'month_number' => $month->month,
            'period' => $period,
            'label' => DtrPeriod::label($month, $period),
            'start' => $start,
            'end' => $end,
            'cutoff' => $cutoff,
            'deadline' => $deadline,
        ];
    }

    public function timeline(array $context, ?string $certificationStatus, ?Carbon $date = null): array
    {
        $today = ($date ?? today())->copy()->startOfDay();
        $status = $certificationStatus ?? 'Draft';

        if ($status === 'Certified') {
            $state = 'certified';
            $message = 'This reporting period is certified and complete.';
        } elseif ($status === 'Submitted') {
            $state = 'submitted';
            $message = 'This reporting period is submitted and awaiting certification.';
        } elseif (in_array($status, ['Returned', 'Reopened'], true)) {
            $state = 'action_required';
            $message = 'Corrections and resubmission are required for this reporting period.';
        } elseif ($today->lessThan($context['cutoff'])) {
            $days = $today->diffInDays($context['cutoff']);
            $reminderDays = max(0, (int) config('attendance.dtr_reminder_days_before', 3));
            $state = $days <= $reminderDays ? 'due_soon' : 'upcoming';
            $message = $state === 'due_soon'
                ? "The cutoff is due in {$days} ".($days === 1 ? 'day' : 'days').'.'
                : 'This reporting period is still open.';
        } elseif ($today->lessThanOrEqualTo($context['deadline'])) {
            $state = 'due';
            $message = 'The cutoff is ready for submission.';
        } else {
            $state = 'overdue';
            $message = 'The DTR submission deadline has passed.';
        }

        return [
            'state' => $state,
            'label' => str($state)->replace('_', ' ')->title()->toString(),
            'cutoff_date' => $context['cutoff']->toDateString(),
            'deadline_date' => $context['deadline']->toDateString(),
            'can_submit' => $today->greaterThanOrEqualTo($context['cutoff']),
            'message' => $message,
        ];
    }

    public function outstandingQuery(User $user, ?string $search = null): Builder
    {
        $gip = $this->latestDueContext('GIP');
        $monthly = $this->latestDueContext('Regular');

        /** @var Builder<Personnel> $query */
        $query = Personnel::query()
            ->with([
                'department:department_id,department_code,department_name',
                'dtrCertifications' => fn (HasMany $query) => $query->where(function (Builder $query) use ($gip, $monthly): void {
                    $this->whereCertificationContext($query, $gip);
                    $query->orWhere(fn (Builder $query) => $this->whereCertificationContext($query, $monthly));
                }),
            ])
            ->where('status', 'Active')
            ->whereNotNull('department_id');

        PersonnelAccess::scope($query, $user);

        $query->when($search, fn (Builder $query, string $search) => $query->where(function (Builder $query) use ($search): void {
            $query->where('employee_number', 'like', "%{$search}%")
                ->orWhere('first_name', 'like', "%{$search}%")
                ->orWhere('middle_name', 'like', "%{$search}%")
                ->orWhere('last_name', 'like', "%{$search}%");
        }))
            ->where(function (Builder $query) use ($gip, $monthly): void {
                $query->where(fn (Builder $query) => $this->whereOutstandingBranch($query, true, $gip))
                    ->orWhere(fn (Builder $query) => $this->whereOutstandingBranch($query, false, $monthly));
            })
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('personnel_id');

        return $query;
    }

    public function certificationFor(Personnel $personnel, array $context): ?DtrCertification
    {
        if ($personnel->relationLoaded('dtrCertifications')) {
            return $personnel->dtrCertifications->first(fn (DtrCertification $certification) => $certification->dtr_year === $context['year']
                && $certification->dtr_month === $context['month_number']
                && $certification->dtr_period === $context['period']
            );
        }

        return $personnel->dtrCertifications()
            ->where('dtr_year', $context['year'])
            ->where('dtr_month', $context['month_number'])
            ->where('dtr_period', $context['period'])
            ->first();
    }

    public function hasScheduleCoverage(Personnel $personnel, array $context): bool
    {
        return $personnel->scheduleAssignments()
            ->whereDate('effective_from', '<=', $context['end'])
            ->where(fn (Builder $query) => $query
                ->whereNull('effective_to')
                ->orWhereDate('effective_to', '>=', $context['start']))
            ->whereHas('schedule', fn (Builder $query) => $query->where('status', 'Active'))
            ->exists();
    }

    public function isGip(?string $personnelType): bool
    {
        return strtoupper(trim((string) $personnelType)) === 'GIP';
    }

    private function whereOutstandingBranch(Builder $query, bool $gip, array $context): void
    {
        if ($gip) {
            $query->where('personnel_type', 'GIP');
        } else {
            $query->where(fn (Builder $query) => $query
                ->whereNull('personnel_type')
                ->orWhere('personnel_type', '!=', 'GIP'));
        }

        $query
            ->whereHas('scheduleAssignments', fn (Builder $query) => $query
                ->whereDate('effective_from', '<=', $context['end'])
                ->where(fn (Builder $query) => $query
                    ->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $context['start']))
                ->whereHas('schedule', fn (Builder $query) => $query->where('status', 'Active')))
            ->whereDoesntHave('dtrCertifications', fn (Builder $query) => $this
                ->whereCertificationContext($query, $context)
                ->whereIn('certification_status', ['Submitted', 'Certified']));
    }

    private function whereCertificationContext(Builder $query, array $context): Builder
    {
        return $query
            ->where('dtr_year', $context['year'])
            ->where('dtr_month', $context['month_number'])
            ->where('dtr_period', $context['period']);
    }
}
