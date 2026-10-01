<?php

namespace Tests\Unit;

use App\Services\DtrCutoffService;
use Carbon\Carbon;
use Tests\TestCase;

class DtrCutoffServiceTest extends TestCase
{
    public function test_gip_and_job_order_use_semi_monthly_cutoffs_and_other_types_use_monthly_cutoffs(): void
    {
        $service = app(DtrCutoffService::class);
        $date = Carbon::parse('2026-08-05', 'Asia/Manila');

        $gip = $service->currentContext('GIP', $date);
        $jobOrder = $service->currentContext('Job Order', $date);
        $regular = $service->currentContext('Regular', $date);

        $this->assertSame('first_half', $gip['period']);
        $this->assertSame('2026-08-15', $gip['cutoff']->toDateString());
        $this->assertSame('first_half', $jobOrder['period']);
        $this->assertSame('2026-08-15', $jobOrder['cutoff']->toDateString());
        $this->assertSame('full_month', $regular['period']);
        $this->assertSame('2026-08-31', $regular['cutoff']->toDateString());
    }

    public function test_latest_due_period_and_timeline_are_deterministic(): void
    {
        config()->set('attendance.dtr_submission_grace_days', 2);
        $service = app(DtrCutoffService::class);
        $today = Carbon::parse('2026-08-05', 'Asia/Manila');
        $context = $service->latestDueContext('GIP', $today);
        $jobOrderContext = $service->latestDueContext('Job Order', $today);

        $this->assertSame('2026-07', $context['month']);
        $this->assertSame('second_half', $context['period']);
        $this->assertSame('2026-08-02', $context['deadline']->toDateString());
        $this->assertSame('2026-07', $jobOrderContext['month']);
        $this->assertSame('second_half', $jobOrderContext['period']);
        $this->assertSame('2026-08-02', $jobOrderContext['deadline']->toDateString());
        $this->assertSame('overdue', $service->timeline($context, null, $today)['state']);
        $this->assertSame('submitted', $service->timeline($context, 'Submitted', $today)['state']);
        $this->assertSame('submitted_late', $service->timeline($context, 'Submitted Late', $today)['state']);
        $this->assertSame('Submitted Late', $service->timeline($context, 'Submitted Late', $today)['label']);
    }
}
