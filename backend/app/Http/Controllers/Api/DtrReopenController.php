<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DtrCertification;
use App\Models\DtrCertificationVersion;
use App\Models\DtrReopenRequest;
use App\Models\DtrStatusLog;
use App\Models\Personnel;
use App\Support\ClientIp;
use App\Support\DtrPeriod;
use App\Support\PersonnelAccess;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class DtrReopenController extends Controller
{
    public function store(Request $request, Personnel $personnel): JsonResponse
    {
        $validated = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'period' => ['nullable', Rule::in(DtrPeriod::values())],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'affected_dates' => ['required', 'array', 'min:1', 'max:31'],
            'affected_dates.*' => ['required', 'date_format:Y-m-d', 'distinct'],
        ]);
        $validated['period'] = DtrPeriod::normalize($validated['period'] ?? null);
        $user = $request->user();

        if (! PersonnelAccess::canAccess($user, $personnel)) {
            return response()->json(['message' => 'This DTR is outside your assigned office scope.'], 403);
        }

        if ($personnel->department_id === null) {
            return response()->json([
                'message' => 'Assign this personnel record to a department before reopening its DTR.',
            ], 422);
        }

        $month = Carbon::createFromFormat('Y-m-d', $validated['month'].'-01')->startOfMonth();
        [$periodStart, $periodEnd] = DtrPeriod::bounds($month, $validated['period']);
        $hasOutsideDate = collect($validated['affected_dates'])->contains(
            fn (string $date) => ! Carbon::createFromFormat('Y-m-d', $date)->betweenIncluded(
                $periodStart,
                $periodEnd
            )
        );

        if ($hasOutsideDate) {
            return response()->json([
                'message' => 'Every affected date must belong to the selected DTR reporting period.',
            ], 422);
        }

        $certification = DtrCertification::query()
            ->where('personnel_id', $personnel->personnel_id)
            ->where('dtr_year', $month->year)
            ->where('dtr_month', $month->month)
            ->where('dtr_period', $validated['period'])
            ->first();

        if (! $certification || $certification->certification_status !== 'Certified') {
            return response()->json([
                'message' => 'Only a currently certified DTR may be requested for reopening.',
            ], 422);
        }

        try {
            $reopenRequest = DtrReopenRequest::create([
                'dtr_certification_id' => $certification->dtr_certification_id,
                'requested_by' => $user->user_id,
                'reason' => trim($validated['reason']),
                'affected_dates' => collect($validated['affected_dates'])->sort()->values()->all(),
                'request_status' => 'Pending',
                'pending_key' => 'dtr:'.$certification->dtr_certification_id,
                'ip_address' => ClientIp::for($request),
                'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
                'request_id' => (string) Str::uuid(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return response()->json([
                'message' => 'A reopening request is already awaiting review for this DTR.',
            ], 409);
        }

        return response()->json([
            'message' => 'The certified DTR reopening request was submitted for independent approval.',
            'reopen_request' => $this->formatRequest(
                $reopenRequest->fresh(['requestedBy:user_id,username', 'reviewedBy:user_id,username'])
            ),
        ], 201);
    }

    public function review(Request $request, DtrReopenRequest $reopenRequest): JsonResponse
    {
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['Approved', 'Rejected'])],
            'review_remarks' => [
                Rule::requiredIf($request->input('decision') === 'Rejected'),
                'nullable',
                'string',
                'max:1000',
            ],
        ]);
        $user = $request->user();

        $result = DB::transaction(function () use ($request, $reopenRequest, $validated, $user): array {
            $lockedRequest = DtrReopenRequest::query()
                ->whereKey($reopenRequest->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedRequest->request_status !== 'Pending') {
                return ['error' => 'This reopening request has already been decided.', 'status' => 409];
            }

            if (
                $user->user_role !== 'Administrator'
                && (int) $lockedRequest->requested_by === (int) $user->user_id
            ) {
                return [
                    'error' => 'You cannot approve or reject your own reopening request.',
                    'status' => 403,
                ];
            }

            $certification = DtrCertification::query()
                ->with('personnel')
                ->whereKey($lockedRequest->dtr_certification_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! PersonnelAccess::canAccess($user, $certification->personnel)) {
                return ['error' => 'This DTR is outside your assigned office scope.', 'status' => 403];
            }

            if ($certification->personnel?->department_id === null) {
                return [
                    'error' => 'Assign this personnel record to a department before reviewing its DTR reopening request.',
                    'status' => 422,
                ];
            }

            if ($validated['decision'] === 'Approved') {
                if ($certification->certification_status !== 'Certified') {
                    return [
                        'error' => 'The DTR is no longer in a certified state and cannot be reopened.',
                        'status' => 409,
                    ];
                }

                if (! $this->hasValidCertifiedSnapshot($certification)) {
                    return [
                        'error' => 'The certified DTR integrity check failed. Reopening was blocked.',
                        'status' => 409,
                    ];
                }

                DtrCertificationVersion::create([
                    'dtr_certification_id' => $certification->dtr_certification_id,
                    'version_number' => $certification->version_number,
                    'prepared_by' => $certification->prepared_by,
                    'certified_by' => $certification->certified_by,
                    'archived_by' => $user->user_id,
                    'prepared_at' => $certification->prepared_at,
                    'certified_at' => $certification->certified_at,
                    'archived_at' => now(),
                    'archive_reason' => $lockedRequest->reason,
                    'certified_snapshot' => $certification->certified_snapshot,
                    'certified_hash' => $certification->certified_hash,
                ]);

                $previousStatus = $certification->certification_status;
                $certification->version_number++;
                $certification->certification_status = 'Reopened';
                $certification->remarks = $lockedRequest->reason;
                $certification->prepared_by = null;
                $certification->prepared_at = null;
                $certification->certified_by = null;
                $certification->certified_at = null;
                $certification->certified_snapshot = null;
                $certification->certified_hash = null;
                $certification->save();

                DtrStatusLog::create([
                    'dtr_certification_id' => $certification->dtr_certification_id,
                    'changed_by' => $user->user_id,
                    'from_status' => $previousStatus,
                    'to_status' => 'Reopened',
                    'remarks' => $lockedRequest->reason,
                    'ip_address' => ClientIp::for($request),
                    'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
                    'request_id' => (string) Str::uuid(),
                ]);
            }

            $lockedRequest->request_status = $validated['decision'];
            $lockedRequest->reviewed_by = $user->user_id;
            $lockedRequest->reviewed_at = now();
            $lockedRequest->review_remarks = trim((string) ($validated['review_remarks'] ?? '')) ?: null;
            $lockedRequest->pending_key = null;
            $lockedRequest->save();

            return ['request' => $lockedRequest];
        });

        if (isset($result['error'])) {
            return response()->json(['message' => $result['error']], $result['status']);
        }

        return response()->json([
            'message' => $validated['decision'] === 'Approved'
                ? 'Reopening approved. The original certified version was archived and Version '
                    .($result['request']->certification->version_number ?? '').' is ready for correction.'
                : 'The DTR reopening request was rejected.',
            'reopen_request' => $this->formatRequest(
                $result['request']->fresh([
                    'requestedBy:user_id,username',
                    'reviewedBy:user_id,username',
                ])
            ),
        ]);
    }

    private function hasValidCertifiedSnapshot(DtrCertification $certification): bool
    {
        if (! $certification->certified_snapshot || ! $certification->certified_hash) {
            return false;
        }

        $encodedSnapshot = json_encode(
            $certification->certified_snapshot,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        $expectedHash = hash_hmac(
            'sha256',
            $encodedSnapshot,
            (string) config('attendance.dtr_signing_key')
        );

        return hash_equals((string) $certification->certified_hash, $expectedHash);
    }

    private function formatRequest(DtrReopenRequest $request): array
    {
        return [
            'id' => $request->dtr_reopen_request_id,
            'status' => $request->request_status,
            'reason' => $request->reason,
            'affected_dates' => $request->affected_dates,
            'requested_by_id' => $request->requested_by,
            'requested_by' => $request->requestedBy?->username ?? 'Former user',
            'requested_at' => $request->created_at?->toISOString(),
            'reviewed_by' => $request->reviewedBy?->username,
            'reviewed_at' => $request->reviewed_at?->toISOString(),
            'review_remarks' => $request->review_remarks,
        ];
    }
}
