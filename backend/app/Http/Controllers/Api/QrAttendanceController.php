<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\QrChallengeRequest;
use App\Http\Requests\QrScanRequest;
use App\Models\AttendanceQrToken;
use App\Models\Holiday;
use App\Models\Personnel;
use App\Models\QrScanLog;
use App\Models\TimeLog;
use App\Services\OfficeNetworkVerifier;
use App\Services\PersonnelOnboardingService;
use App\Support\ClientIp;
use App\Support\PersonnelAccess;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\InputBag;

class QrAttendanceController extends Controller
{
    private const PAGE_ROLES = ['Administrator', 'HR', 'Supervisor', 'Encoder', 'Personnel'];

    private const MULTI_PERSON_SCAN_ROLES = ['Administrator', 'HR'];

    private const CODE_MANAGER_ROLES = ['Administrator', 'HR'];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! in_array($user->user_role, self::PAGE_ROLES, true)) {
            return response()->json(['message' => 'You do not have access to QR attendance.'], 403);
        }

        $canScan = in_array($user->user_role, self::PAGE_ROLES, true);
        $canScanOthers = in_array($user->user_role, self::MULTI_PERSON_SCAN_ROLES, true);
        $canManageCodes = in_array($user->user_role, self::CODE_MANAGER_ROLES, true);
        $canViewCards = true;
        $today = now()->toDateString();
        $visiblePersonnelQuery = PersonnelAccess::scope(
            Personnel::query()->where('status', 'Active'),
            $user
        );
        $visiblePersonnelIds = (clone $visiblePersonnelQuery)->select('personnel_id');
        $todayLogs = QrScanLog::query()
            ->whereIn('personnel_id', clone $visiblePersonnelIds)
            ->whereDate('scanned_at', $today);
        $recentLogs = QrScanLog::query()
            ->with([
                'personnel:personnel_id,employee_number,first_name,middle_name,last_name,suffix,photo',
                'scanner:user_id,username',
            ])
            ->whereIn('personnel_id', clone $visiblePersonnelIds)
            ->orderByDesc('scanned_at')
            ->limit(20)
            ->get()
            ->map(fn (QrScanLog $log) => $this->formatScanLog($log));

        return response()->json([
            'server_time' => now()->toISOString(),
            'timezone' => config('app.timezone'),
            'can_scan' => $canScan,
            'can_scan_others' => $canScanOthers,
            'scan_scope' => $canScanOthers ? 'all_personnel' : 'self',
            'can_view_cards' => $canViewCards,
            'can_manage_codes' => $canManageCodes,
            'summary' => [
                'active_personnel' => (clone $visiblePersonnelQuery)->count(),
                'accepted_today' => (clone $todayLogs)->where('scan_status', 'Accepted')->count(),
                'rejected_today' => (clone $todayLogs)->whereNotIn('scan_status', ['Accepted', 'Duplicate'])->count(),
                'duplicates_today' => (clone $todayLogs)->where('scan_status', 'Duplicate')->count(),
            ],
            'recent_scans' => $recentLogs,
            'personnel' => $canViewCards && ! $canManageCodes
                ? Personnel::query()
                    ->with('department:department_id,department_code,department_name,office_location')
                    ->where('status', 'Active')
                    ->whereKey($user->personnel_id ?? -1)
                    ->orderBy('last_name')
                    ->orderBy('first_name')
                    ->get()
                    ->map(fn (Personnel $person) => $this->formatPersonnelCard($person))
                : [],
        ]);
    }

    public function cards(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! in_array($user->user_role, self::CODE_MANAGER_ROLES, true)) {
            return response()->json([
                'message' => 'Only Administrator and HR accounts can browse personnel QR cards.',
            ], 403);
        }

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:6,48'],
        ]);
        $paginator = Personnel::query()
            ->with('department:department_id,department_code,department_name,office_location')
            ->where('status', 'Active')
            ->tap(fn ($query) => PersonnelAccess::scope($query, $user))
            ->when($validated['search'] ?? null, function ($query, string $search): void {
                $query->where(function ($query) use ($search): void {
                    $query
                        ->where('employee_number', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('middle_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%");
                });
            })
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate(
                $validated['per_page'] ?? 12,
                ['*'],
                'page',
                $validated['page'] ?? 1
            );

        return response()->json([
            'data' => collect($paginator->items())
                ->map(fn (Personnel $person) => $this->formatPersonnelCard($person)),
            'meta' => ['pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ]],
        ]);
    }

    public function regenerate(Request $request, Personnel $personnel): JsonResponse
    {
        if (! in_array($request->user()->user_role, self::CODE_MANAGER_ROLES, true)) {
            return response()->json(['message' => 'Only Administrator and HR accounts can manage QR cards.'], 403);
        }

        $personnel->forceFill([
            'qr_login_code' => hash('sha256', Str::random(64)),
        ])->save();

        return response()->json([
            'message' => 'A new QR card was generated for '.$personnel->full_name.'.',
            'personnel' => $this->formatPersonnelCard($personnel->fresh('department')),
        ]);
    }

    public function challenge(QrChallengeRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! in_array($user->user_role, self::MULTI_PERSON_SCAN_ROLES, true)
            && ! $user->personnel_id) {
            return response()->json([
                'message' => 'Your account must be linked to a personnel record before you can use QR attendance.',
            ], 422);
        }

        $validated = $request->validated();
        $challenge = bin2hex(random_bytes(32));
        $now = now();
        $expiresAt = $now->copy()->addSeconds(90);

        AttendanceQrToken::create([
            'department_id' => $request->user()->personnel?->department_id,
            'token_hash' => $this->challengeHash(
                $challenge,
                $validated['device_identifier']
            ),
            'purpose' => 'Attendance',
            'valid_from' => $now,
            'expires_at' => $expiresAt,
            'used_count' => 0,
            'maximum_uses' => 1,
            'is_active' => true,
            'created_by' => $request->user()->user_id,
        ]);

        return response()->json([
            'challenge' => $challenge,
            'expires_at' => $expiresAt->toISOString(),
        ]);
    }

    public function scan(
        QrScanRequest $request,
        PersonnelOnboardingService $onboarding,
        OfficeNetworkVerifier $networkVerifier
    ): JsonResponse {
        $validated = $request->validated();
        $qrToken = $this->consumeChallenge(
            $request,
            $validated['challenge'],
            $validated['device_identifier']
        );

        if (! $qrToken) {
            return response()->json([
                'message' => 'The kiosk scan challenge is invalid, expired, or has already been used. Scan the card again.',
            ], 409);
        }

        $request->attributes->set('qr_token_id', $qrToken->qr_token_id);
        $now = now();
        $personnel = $this->resolvePersonnel($validated['code']);

        if (! $personnel) {
            $log = $this->createScanLog($request, $validated, null, 'Invalid', 'The QR card is invalid or has been revoked.');

            return response()->json([
                'message' => $log->message,
                'scan' => $this->formatScanLog($log),
            ], 422);
        }

        $user = $request->user();
        $canScanOthers = in_array($user->user_role, self::MULTI_PERSON_SCAN_ROLES, true);

        if (! $canScanOthers && (int) $user->personnel_id !== (int) $personnel->personnel_id) {
            $message = 'You can only record attendance using the QR card linked to your own account. This card belongs to another personnel member.';
            $log = $this->createScanLog($request, $validated, $personnel, 'Rejected', $message);

            return response()->json([
                'message' => $message,
                'scan' => $this->formatScanLog($log),
            ], 403);
        }

        $personnelRateKey = 'qr-personnel:'.$personnel->personnel_id;

        if (RateLimiter::tooManyAttempts($personnelRateKey, 6)) {
            $message = 'Too many scans were attempted for this personnel card. Wait one minute and try again.';
            $log = $this->createScanLog(
                $request,
                $validated,
                $personnel,
                'Rejected',
                $message
            );

            return response()->json([
                'message' => $message,
                'scan' => $this->formatScanLog($log),
            ], 429);
        }

        RateLimiter::hit($personnelRateKey, 60);

        if ($personnel->status !== 'Active') {
            $log = $this->createScanLog($request, $validated, $personnel, 'Inactive Personnel', 'This personnel record is inactive.');

            return response()->json([
                'message' => $log->message,
                'scan' => $this->formatScanLog($log),
                'personnel' => $this->formatPersonnelIdentity($personnel),
            ], 422);
        }

        if ($reason = $onboarding->operationalBlockReason($personnel, $now)) {
            $log = $this->createScanLog($request, $validated, $personnel, 'Setup Incomplete', $reason);

            return response()->json([
                'message' => $reason,
                'scan' => $this->formatScanLog($log),
                'personnel' => $this->formatPersonnelIdentity($personnel),
            ], 422);
        }

        if (! PersonnelAccess::canAccess($request->user(), $personnel)) {
            $message = 'This personnel card belongs to a different DILG office.';
            $log = $this->createScanLog($request, $validated, $personnel, 'Rejected', $message);

            return response()->json([
                'message' => $message,
                'scan' => $this->formatScanLog($log),
            ], 403);
        }

        if (($personnel->employment_start_date && $now->isBefore($personnel->employment_start_date->startOfDay()))
            || ($personnel->employment_end_date && $now->isAfter($personnel->employment_end_date->endOfDay()))) {
            $log = $this->createScanLog($request, $validated, $personnel, 'Outside Contract', 'The current date is outside this personnel contract.');

            return response()->json([
                'message' => $log->message,
                'scan' => $this->formatScanLog($log),
                'personnel' => $this->formatPersonnelIdentity($personnel),
            ], 422);
        }

        if (($personnel->qr_valid_from && $now->isBefore($personnel->qr_valid_from->startOfDay()))
            || ($personnel->qr_valid_until && $now->isAfter($personnel->qr_valid_until->endOfDay()))) {
            $log = $this->createScanLog(
                $request,
                $validated,
                $personnel,
                'Expired Credential',
                'This personnel card is not currently valid. Ask Administrator or HR to review its validity dates.'
            );

            return response()->json([
                'message' => $log->message,
                'scan' => $this->formatScanLog($log),
                'personnel' => $this->formatPersonnelIdentity($personnel),
            ], 422);
        }

        $office = $personnel->department;
        $officeNetwork = $office
            ? $networkVerifier->match($request, (int) $office->department_id)
            : null;

        if (! $office?->latitude || ! $office?->longitude) {
            $message = 'The assigned DILG office does not have GPS coordinates configured.';
            $log = $this->createScanLog($request, $validated, $personnel, 'Rejected', $message);

            return response()->json([
                'message' => $message,
                'scan' => $this->formatScanLog($log),
                'personnel' => $this->formatPersonnelIdentity($personnel),
            ], 422);
        }

        $distance = null;
        $locationMethod = $officeNetwork ? 'Office Network' : 'GPS';
        $maximumAccuracy = (float) config('attendance.maximum_location_accuracy_meters', 100);
        $hasCompleteGps = isset(
            $validated['latitude'],
            $validated['longitude'],
            $validated['accuracy'],
            $validated['position_timestamp']
        );

        if ($officeNetwork) {
            if ($hasCompleteGps) {
                $positionRecordedAt = Carbon::parse($validated['position_timestamp']);
                $gpsIsFresh = ! $positionRecordedAt->isBefore($now->copy()->subMinute())
                    && ! $positionRecordedAt->isAfter($now->copy()->addSeconds(10));
                $gpsIsAccurate = (float) $validated['accuracy'] <= $maximumAccuracy;

                if ($gpsIsFresh && $gpsIsAccurate) {
                    $distance = $this->distanceInMeters(
                        (float) $validated['latitude'],
                        (float) $validated['longitude'],
                        (float) $office->latitude,
                        (float) $office->longitude
                    );
                    $locationMethod = 'Office Network + GPS';
                    $allowedRadius = (int) ($office->allowed_radius_meters ?: 100);

                    if ($distance > $allowedRadius) {
                        $message = 'Location signals conflict: the office network matched, but accurate GPS places this device approximately '
                            .number_format($distance).' meters from '.$office->office_location.'. Disable any VPN and refresh location.';
                        $log = $this->createScanLog(
                            $request,
                            $validated,
                            $personnel,
                            'Outside Location',
                            $message,
                            distance: $distance,
                            officeNetworkId: $officeNetwork->office_network_id,
                            locationMethod: $locationMethod
                        );

                        return response()->json([
                            'message' => $message,
                            'scan' => $this->formatScanLog($log),
                            'personnel' => $this->formatPersonnelIdentity($personnel),
                        ], 422);
                    }
                }
            }
        } elseif (! isset($validated['latitude'], $validated['longitude'])) {
            $message = 'Device location is required. Enable GPS and allow location access, then scan again.';
            $log = $this->createScanLog($request, $validated, $personnel, 'Outside Location', $message);

            return response()->json([
                'message' => $message,
                'scan' => $this->formatScanLog($log),
                'personnel' => $this->formatPersonnelIdentity($personnel),
            ], 422);
        } elseif (! isset($validated['accuracy'], $validated['position_timestamp'])) {
            $message = 'A fresh, accurate GPS position is required. Refresh location and scan again.';
            $log = $this->createScanLog($request, $validated, $personnel, 'Outside Location', $message);

            return response()->json([
                'message' => $message,
                'scan' => $this->formatScanLog($log),
                'personnel' => $this->formatPersonnelIdentity($personnel),
            ], 422);
        } elseif ((float) $validated['accuracy'] > $maximumAccuracy) {
            $message = 'GPS accuracy is too low (±'.number_format((float) $validated['accuracy'])
                .' m). This kiosk requires ±'.number_format($maximumAccuracy)
                .' m or better. Enable precise location, then refresh and scan again.';
            $log = $this->createScanLog($request, $validated, $personnel, 'Outside Location', $message);

            return response()->json([
                'message' => $message,
                'scan' => $this->formatScanLog($log),
                'personnel' => $this->formatPersonnelIdentity($personnel),
            ], 422);
        }

        if (! $officeNetwork) {
            $positionRecordedAt = Carbon::parse($validated['position_timestamp']);

            if (
                $positionRecordedAt->isBefore($now->copy()->subMinute())
                || $positionRecordedAt->isAfter($now->copy()->addSeconds(10))
            ) {
                $message = 'The GPS position is stale. Refresh location and scan again.';
                $log = $this->createScanLog($request, $validated, $personnel, 'Outside Location', $message);

                return response()->json([
                    'message' => $message,
                    'scan' => $this->formatScanLog($log),
                    'personnel' => $this->formatPersonnelIdentity($personnel),
                ], 422);
            }

            $distance = $this->distanceInMeters(
                (float) $validated['latitude'],
                (float) $validated['longitude'],
                (float) $office->latitude,
                (float) $office->longitude
            );
            $allowedRadius = (int) ($office->allowed_radius_meters ?: 100);

            if ($distance > $allowedRadius) {
                $message = 'Outside the allowed '.$allowedRadius.'-meter radius of '.$office->office_location
                    .' (approximately '.number_format($distance).' meters away).';
                $log = $this->createScanLog(
                    $request,
                    $validated,
                    $personnel,
                    'Outside Location',
                    $message,
                    distance: $distance,
                    locationMethod: 'GPS'
                );

                return response()->json([
                    'message' => $message,
                    'scan' => $this->formatScanLog($log),
                    'personnel' => $this->formatPersonnelIdentity($personnel),
                ], 422);
            }
        }

        $holiday = Holiday::query()
            ->whereDate('holiday_date', $now->toDateString())
            ->where('holiday_type', '!=', 'Special Working Holiday')
            ->where(fn ($query) => $query
                ->whereNull('department_id')
                ->orWhere('department_id', $personnel->department_id))
            ->first();

        if ($holiday) {
            $message = 'Attendance is closed for '.$holiday->holiday_name.'.';
            $log = $this->createScanLog(
                $request,
                $validated,
                $personnel,
                'Wrong Schedule',
                $message,
                distance: $distance,
                officeNetworkId: $officeNetwork?->office_network_id,
                locationMethod: $locationMethod
            );

            return response()->json([
                'message' => $message,
                'scan' => $this->formatScanLog($log),
                'personnel' => $this->formatPersonnelIdentity($personnel),
            ], 422);
        }

        $result = DB::transaction(function () use (
            $request,
            $validated,
            $personnel,
            $now,
            $distance,
            $officeNetwork,
            $locationMethod
        ): array {
            $recentAcceptedScan = QrScanLog::query()
                ->where('personnel_id', $personnel->personnel_id)
                ->where('scan_status', 'Accepted')
                ->where('scanned_at', '>=', $now->copy()->subSeconds(5))
                ->lockForUpdate()
                ->first();

            if ($recentAcceptedScan) {
                $message = 'Duplicate scan ignored. Please wait before scanning this card again.';
                $log = $this->createScanLog(
                    $request,
                    $validated,
                    $personnel,
                    'Duplicate',
                    $message,
                    distance: $distance,
                    officeNetworkId: $officeNetwork?->office_network_id,
                    locationMethod: $locationMethod
                );

                return ['status' => 409, 'message' => $message, 'log' => $log];
            }

            $attendanceRequest = Request::create(
                '/api/attendance/time-log',
                'POST',
                [
                    'personnel_id' => $personnel->personnel_id,
                    'device_identifier' => $validated['device_identifier'],
                ],
                [],
                [],
                $request->server->all()
            );
            // A real kiosk request is JSON. Laravel therefore reads its JSON input
            // bag instead of the POST parameter bag populated by Request::create.
            $attendanceRequest->setJson(new InputBag([
                'personnel_id' => $personnel->personnel_id,
                'device_identifier' => $validated['device_identifier'],
            ]));
            $attendanceRequest->setUserResolver(fn () => $request->user());
            $attendanceRequest->attributes->set('trusted_qr_scan', true);
            $attendanceResponse = app(AttendanceController::class)->recordTime($attendanceRequest);
            $attendancePayload = $attendanceResponse->getData(true);

            if ($attendanceResponse->getStatusCode() >= 400) {
                $message = $attendancePayload['message'] ?? 'The attendance scan was rejected.';
                $scanStatus = str_contains(strtolower($message), 'already')
                    || str_contains(strtolower($message), 'complete')
                    ? 'Duplicate'
                    : 'Wrong Schedule';
                $log = $this->createScanLog(
                    $request,
                    $validated,
                    $personnel,
                    $scanStatus,
                    $message,
                    distance: $distance,
                    officeNetworkId: $officeNetwork?->office_network_id,
                    locationMethod: $locationMethod
                );

                return ['status' => 422, 'message' => $message, 'log' => $log];
            }

            $attendanceId = $attendancePayload['data']['attendance_id'];
            $scanAction = $this->scanAction($attendancePayload['action']);
            $log = $this->createScanLog(
                $request,
                $validated,
                $personnel,
                'Accepted',
                $scanAction.' successfully recorded.',
                $scanAction,
                $attendanceId,
                $distance,
                $officeNetwork?->office_network_id,
                $locationMethod
            );
            $timeLog = TimeLog::query()
                ->where('attendance_id', $attendanceId)
                ->where('personnel_id', $personnel->personnel_id)
                ->where('created_by', $request->user()->user_id)
                ->latest('time_log_id')
                ->first();

            $timeLog?->forceFill([
                'qr_scan_id' => $log->qr_scan_id,
                'log_source' => 'QR Code',
            ])->save();

            return [
                'status' => 200,
                'message' => $scanAction.' recorded at '.$now->format('h:i:s A').'.',
                'log' => $log,
                'attendance' => $attendancePayload['data'],
            ];
        });

        return response()->json([
            'message' => $result['message'],
            'scan' => $this->formatScanLog($result['log']->fresh(['personnel', 'scanner'])),
            'attendance' => $result['attendance'] ?? null,
            'personnel' => $this->formatPersonnelIdentity($personnel),
        ], $result['status']);
    }

    private function resolvePersonnel(string $payload): ?Personnel
    {
        $parts = explode(':', trim($payload));

        if (count($parts) !== 4 || $parts[0] !== 'DILGATTEND' || $parts[1] !== 'v1' || ! ctype_digit($parts[2])) {
            return null;
        }

        $personnel = Personnel::query()->with('department')->find((int) $parts[2]);

        if (! $personnel?->qr_login_code) {
            return null;
        }

        $expectedSignature = $this->signature($personnel);

        return hash_equals($expectedSignature, $parts[3]) ? $personnel : null;
    }

    private function payload(Personnel $personnel): ?string
    {
        if (! $personnel->qr_login_code) {
            return null;
        }

        return 'DILGATTEND:v1:'.$personnel->personnel_id.':'.$this->signature($personnel);
    }

    private function signature(Personnel $personnel): string
    {
        return hash_hmac(
            'sha256',
            'DILGATTEND|v1|'.$personnel->personnel_id.'|'.$personnel->qr_login_code,
            (string) config('attendance.qr_signing_key')
        );
    }

    private function consumeChallenge(
        Request $request,
        string $challenge,
        string $deviceIdentifier
    ): ?AttendanceQrToken {
        return DB::transaction(function () use (
            $request,
            $challenge,
            $deviceIdentifier
        ): ?AttendanceQrToken {
            $token = AttendanceQrToken::query()
                ->where('token_hash', $this->challengeHash($challenge, $deviceIdentifier))
                ->where('created_by', $request->user()->user_id)
                ->lockForUpdate()
                ->first();

            if (
                ! $token
                || ! $token->is_active
                || now()->isBefore($token->valid_from)
                || now()->isAfter($token->expires_at)
                || ($token->maximum_uses !== null
                    && $token->used_count >= $token->maximum_uses)
            ) {
                return null;
            }

            $token->used_count++;
            $token->is_active = $token->maximum_uses !== null
                ? $token->used_count < $token->maximum_uses
                : true;
            $token->save();

            return $token;
        });
    }

    private function challengeHash(string $challenge, string $deviceIdentifier): string
    {
        return hash('sha256', $challenge.'|'.$deviceIdentifier);
    }

    private function createScanLog(
        Request $request,
        array $validated,
        ?Personnel $personnel,
        string $status,
        string $message,
        string $action = 'Unknown',
        ?int $attendanceId = null,
        ?float $distance = null,
        ?int $officeNetworkId = null,
        ?string $locationMethod = null
    ): QrScanLog {
        return QrScanLog::create([
            'qr_token_id' => $request->attributes->get('qr_token_id'),
            'personnel_id' => $personnel?->personnel_id,
            'attendance_id' => $attendanceId,
            'scan_action' => $action,
            'scanned_at' => now(),
            'latitude' => $validated['latitude'] ?? null,
            'longitude' => $validated['longitude'] ?? null,
            'location_accuracy_meters' => $validated['accuracy'] ?? null,
            'position_recorded_at' => $validated['position_timestamp'] ?? null,
            'distance_from_office_meters' => $distance,
            'office_network_id' => $officeNetworkId,
            'location_verification_method' => $locationMethod,
            'ip_address' => ClientIp::for($request),
            'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
            'device_identifier' => $validated['device_identifier'],
            'scanned_by' => $request->user()->user_id,
            'scan_status' => $status,
            'message' => Str::limit($message, 255, ''),
        ]);
    }

    private function scanAction(string $action): string
    {
        return match ($action) {
            'Morning In' => 'Morning Time In',
            'Morning Out' => 'Morning Time Out',
            'Afternoon In' => 'Afternoon Time In',
            'Afternoon Out' => 'Afternoon Time Out',
            default => 'Unknown',
        };
    }

    private function formatPersonnelCard(Personnel $personnel): array
    {
        $validFrom = $personnel->qr_valid_from;
        $validUntil = $personnel->qr_valid_until;
        $validityLabel = match (true) {
            $validFrom !== null && $validUntil !== null => $validFrom->format('Y').' - '.$validUntil->format('Y'),
            $validFrom !== null => 'From '.$validFrom->format('Y'),
            $validUntil !== null => 'Until '.$validUntil->format('Y'),
            default => 'Not configured',
        };

        return [
            ...$this->formatPersonnelIdentity($personnel),
            'qr_payload' => $this->payload($personnel),
            'has_qr' => (bool) $personnel->qr_login_code,
            'valid_from' => $validFrom?->format('Y-m-d'),
            'valid_until' => $validUntil?->format('Y-m-d'),
            'validity_label' => $validityLabel,
        ];
    }

    private function formatPersonnelIdentity(Personnel $personnel): array
    {
        return [
            'personnel_id' => $personnel->personnel_id,
            'employee_number' => $personnel->employee_number,
            'full_name' => $personnel->full_name,
            'personnel_type' => $personnel->personnel_type,
            'position_title' => $personnel->position_title,
            'photo_url' => $personnel->photo
                ? route(
                    'personnel.photo',
                    ['personnel' => $personnel],
                    config('app.frontend_deployment') === 'external'
                        && ! config('app.frontend_api_proxy')
                )
                    .'?v='.($personnel->updated_at?->timestamp ?? 0)
                : null,
            'signature_url' => $personnel->signature
                ? route(
                    'personnel.signature',
                    ['personnel' => $personnel],
                    config('app.frontend_deployment') === 'external'
                        && ! config('app.frontend_api_proxy')
                )
                    .'?v='.($personnel->updated_at?->timestamp ?? 0)
                : null,
            'department' => $personnel->department ? [
                'code' => $personnel->department->department_code,
                'name' => $personnel->department->department_name,
                'location' => $personnel->department->office_location,
            ] : null,
        ];
    }

    private function formatScanLog(QrScanLog $log): array
    {
        return [
            'qr_scan_id' => $log->qr_scan_id,
            'personnel_id' => $log->personnel_id,
            'full_name' => $log->personnel?->full_name,
            'employee_number' => $log->personnel?->employee_number,
            'photo_url' => $log->personnel
                ? $this->formatPersonnelIdentity($log->personnel)['photo_url']
                : null,
            'scan_action' => $this->displayScanAction($log),
            'scan_status' => $log->scan_status,
            'message' => $log->message,
            'scanned_at' => $log->scanned_at?->toISOString(),
            'time' => $log->scanned_at?->format('h:i:s A'),
            'scanner' => $log->scanner?->username,
            'device_identifier' => $log->device_identifier,
            'distance_from_office_meters' => $log->distance_from_office_meters !== null
                ? (float) $log->distance_from_office_meters
                : null,
            'location_verification_method' => $log->location_verification_method,
        ];
    }

    private function displayScanAction(QrScanLog $log): string
    {
        if ($log->scan_action !== 'Unknown') {
            return $log->scan_action;
        }

        return match ($log->scan_status) {
            'Outside Location' => 'Location Check',
            'Invalid', 'Expired' => 'QR Validation',
            'Inactive Personnel' => 'Personnel Check',
            'Outside Contract' => 'Contract Check',
            'Wrong Schedule' => 'Schedule Check',
            'Duplicate' => 'Duplicate Check',
            default => 'Scan Validation',
        };
    }

    private function distanceInMeters(
        float $latitude,
        float $longitude,
        float $officeLatitude,
        float $officeLongitude
    ): float {
        $earthRadius = 6371000;
        $latitudeDelta = deg2rad($officeLatitude - $latitude);
        $longitudeDelta = deg2rad($officeLongitude - $longitude);
        $a = sin($latitudeDelta / 2) ** 2
            + cos(deg2rad($latitude))
            * cos(deg2rad($officeLatitude))
            * sin($longitudeDelta / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
