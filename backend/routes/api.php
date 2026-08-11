<?php

use App\Http\Controllers\Api\ActionCenterController;
use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\DtrController;
use App\Http\Controllers\Api\DtrReopenController;
use App\Http\Controllers\Api\HolidayController;
use App\Http\Controllers\Api\LeaveRequestController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OfficeNetworkController;
use App\Http\Controllers\Api\PersonnelController;
use App\Http\Controllers\Api\PersonnelOnboardingController;
use App\Http\Controllers\Api\QrAttendanceController;
use App\Http\Controllers\Api\ScheduleController;
use App\Http\Controllers\Api\SystemUserController;
use Illuminate\Support\Facades\Route;

// These routes are consumed by the first-party React SPA through a reverse
// proxy. Load the web session explicitly so authentication does not depend on
// an intermediary preserving Sanctum's Origin/Referer stateful-domain signal.
// The web group also keeps CSRF validation active for every mutating request.
Route::middleware(['web', 'auth:sanctum', 'session.active', 'throttle:api', 'api.audit'])->group(function (): void {
    // Auth/session: identify the current logged-in user and destroy the session.
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    // Overview queues: role-aware dashboards, action queues, and notifications.
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/action-center', [ActionCenterController::class, 'index'])
        ->middleware('role:Administrator,HR,Supervisor');
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/summary', [NotificationController::class, 'summary']);
    Route::patch('/notifications/read-all', [NotificationController::class, 'markAllRead'])
        ->middleware('throttle:20,1');
    Route::patch('/notifications/{notificationId}/read', [NotificationController::class, 'markRead'])
        ->whereNumber('notificationId')
        ->middleware('throttle:30,1');

    // Daily attendance: list attendance, self time-in/out, verification, and correction workflows.
    Route::get('/attendance', [AttendanceController::class, 'index']);
    Route::get('/attendance/options', [AttendanceController::class, 'options']);
    Route::get('/attendance/correction-requests', [AttendanceController::class, 'correctionRequests']);
    Route::get(
        '/attendance/correction-requests/{correctionRequest}',
        [AttendanceController::class, 'showCorrectionRequest']
    )->whereNumber('correctionRequest')
        ->middleware('role:Administrator,HR');
    Route::post('/attendance/correction-requests', [AttendanceController::class, 'submitCorrectionRequest'])
        ->middleware('throttle:5,1');
    Route::patch(
        '/attendance/correction-requests/{correctionRequest}/review',
        [AttendanceController::class, 'reviewCorrectionRequest']
    )->whereNumber('correctionRequest')
        ->middleware(['role:Administrator,HR', 'throttle:20,1']);
    Route::post('/attendance/time-log', [AttendanceController::class, 'recordTime'])
        ->middleware('throttle:12,1');
    Route::patch('/attendance/{attendance}/verify', [AttendanceController::class, 'verify'])
        ->middleware(['role:Administrator,HR,Supervisor', 'throttle:20,1']);
    Route::post('/attendance/verify-bulk', [AttendanceController::class, 'verifyBulk'])
        ->middleware(['role:Administrator,HR,Supervisor', 'throttle:20,1']);
    Route::post('/attendance/correction', [AttendanceController::class, 'correct'])
        ->middleware(['role:Administrator,HR', 'throttle:20,1']);

    // QR attendance: kiosk/card listing, signed scan challenges, scan submission, and card regeneration.
    Route::get('/qr-attendance', [QrAttendanceController::class, 'index'])
        ->middleware('role:Administrator,HR,Supervisor,Encoder,Personnel');
    Route::get('/qr-attendance/cards', [QrAttendanceController::class, 'cards'])
        ->middleware('role:Administrator,HR');

    Route::post('/qr-attendance/challenge', [QrAttendanceController::class, 'challenge'])
        ->middleware([
            'role:Administrator,HR,Supervisor,Encoder,Personnel',
            'throttle:qr-challenge',
        ]);
    Route::post('/qr-attendance/scan', [QrAttendanceController::class, 'scan'])
        ->middleware([
            'role:Administrator,HR,Supervisor,Encoder,Personnel',
            'throttle:qr-scan',
        ]);

    Route::post('/qr-attendance/personnel/{personnel}/regenerate', [QrAttendanceController::class, 'regenerate'])
        ->middleware(['role:Administrator,HR', 'throttle:20,1']);

    // DTR workflow: monitor cutoff periods, generate documents, submit/certify/return, and reopen.
    Route::get('/dtr', [DtrController::class, 'index']);
    Route::post('/dtr/generate', [DtrController::class, 'generate'])
        ->middleware('throttle:5,1');
    Route::patch('/dtr/{personnel}/status', [DtrController::class, 'updateStatus'])
        ->middleware('throttle:20,1');
    Route::post('/dtr/{personnel}/reopen-requests', [DtrReopenController::class, 'store'])
        ->middleware(['role:Administrator,HR', 'throttle:5,1']);
    Route::patch('/dtr/reopen-requests/{reopenRequest}/review', [DtrReopenController::class, 'review'])
        ->middleware(['role:Administrator', 'throttle:10,1']);

    // Leave and official business: request, review, cancel, and download private attachments.
    Route::get('/leave-requests', [LeaveRequestController::class, 'index']);
    Route::post('/leave-requests', [LeaveRequestController::class, 'store'])
        ->middleware('throttle:5,1');
    Route::get(
        '/leave-requests/{leaveRecord}/document',
        [LeaveRequestController::class, 'document']
    );
    Route::patch(
        '/leave-requests/{leaveRecord}/review',
        [LeaveRequestController::class, 'review']
    )->middleware(['role:Administrator,HR,Supervisor', 'throttle:20,1']);
    Route::patch(
        '/leave-requests/{leaveRecord}/cancel',
        [LeaveRequestController::class, 'cancel']
    )->middleware('throttle:10,1');

    // Shared reference data and private personnel media.
    Route::get('/holidays', [HolidayController::class, 'index']);
    Route::get('/holidays/options', [HolidayController::class, 'options']);
    Route::get('/personnel/{personnel}/photo', [PersonnelController::class, 'photo'])
        ->name('personnel.photo');
    Route::get('/personnel/{personnel}/signature', [PersonnelController::class, 'signature'])
        ->name('personnel.signature');

    Route::middleware('role:Administrator,HR')->group(function (): void {
        // HR setup: onboarding, departments, schedules, holidays, and personnel directory management.
        Route::get('/personnel-onboarding', [PersonnelOnboardingController::class, 'index']);
        Route::patch('/personnel-onboarding/bulk', [PersonnelOnboardingController::class, 'updateBulk'])
            ->middleware('throttle:10,1');
        Route::patch('/personnel-onboarding/{personnel}', [PersonnelOnboardingController::class, 'update'])
            ->middleware('throttle:20,1');

        Route::apiResource('/departments', DepartmentController::class)
            ->except(['show']);

        Route::post('/schedules/assignments', [ScheduleController::class, 'assign'])
            ->middleware('throttle:20,1');
        Route::delete(
            '/schedules/assignments/{personnelSchedule}',
            [ScheduleController::class, 'destroyAssignment']
        );
        Route::apiResource('/schedules', ScheduleController::class)
            ->except(['show']);

        Route::post('/holidays', [HolidayController::class, 'store']);
        Route::match(['put', 'patch'], '/holidays/{holiday}', [HolidayController::class, 'update']);
        Route::delete('/holidays/{holiday}', [HolidayController::class, 'destroy']);

        Route::get('/personnel/options', [PersonnelController::class, 'options']);
        Route::apiResource('/personnel', PersonnelController::class)
            ->except(['show']);
    });

    Route::middleware('role:Administrator')->group(function (): void {
        // Administrator security: immutable activity logs, system users, and office network allowlists.
        Route::get('/activity-logs', [ActivityLogController::class, 'index']);

        Route::get('/system-users/options', [SystemUserController::class, 'options']);
        Route::apiResource('/system-users', SystemUserController::class)
            ->parameters(['system-users' => 'systemUser'])
            ->except(['show']);

        Route::post(
            '/departments/{department}/office-networks',
            [OfficeNetworkController::class, 'store']
        )->middleware('throttle:5,1');
        Route::delete(
            '/departments/{department}/office-networks/{officeNetwork}',
            [OfficeNetworkController::class, 'destroy']
        )->middleware('throttle:10,1');
    });
});
