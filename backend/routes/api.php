<?php

use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\DtrController;
use App\Http\Controllers\Api\DtrReopenController;
use App\Http\Controllers\Api\HolidayController;
use App\Http\Controllers\Api\PersonnelController;
use App\Http\Controllers\Api\QrAttendanceController;
use App\Http\Controllers\Api\ScheduleController;
use App\Http\Controllers\Api\SystemUserController;
use Illuminate\Support\Facades\Route;

// These routes are consumed by the first-party React SPA through a reverse
// proxy. Load the web session explicitly so authentication does not depend on
// an intermediary preserving Sanctum's Origin/Referer stateful-domain signal.
// The web group also keeps CSRF validation active for every mutating request.
Route::middleware(['web', 'auth:sanctum', 'session.active', 'throttle:api', 'api.audit'])->group(function (): void {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    Route::get('/dashboard', [DashboardController::class, 'index']);

    Route::get('/attendance', [AttendanceController::class, 'index']);
    Route::get('/attendance/options', [AttendanceController::class, 'options']);
    Route::get('/attendance/correction-requests', [AttendanceController::class, 'correctionRequests']);
    Route::post('/attendance/correction-requests', [AttendanceController::class, 'submitCorrectionRequest'])
        ->middleware('throttle:5,1');
    Route::patch(
        '/attendance/correction-requests/{correctionRequest}/review',
        [AttendanceController::class, 'reviewCorrectionRequest']
    )->middleware(['role:Administrator,HR', 'throttle:20,1']);
    Route::post('/attendance/time-log', [AttendanceController::class, 'recordTime'])
        ->middleware('throttle:12,1');
    Route::patch('/attendance/{attendance}/verify', [AttendanceController::class, 'verify'])
        ->middleware(['role:Administrator,HR,Supervisor', 'throttle:20,1']);
    Route::post('/attendance/verify-bulk', [AttendanceController::class, 'verifyBulk'])
        ->middleware(['role:Administrator,HR,Supervisor', 'throttle:20,1']);
    Route::post('/attendance/correction', [AttendanceController::class, 'correct'])
        ->middleware(['role:Administrator,HR', 'throttle:20,1']);

    Route::get('/qr-attendance', [QrAttendanceController::class, 'index'])
        ->middleware('role:Administrator,HR,Supervisor,Encoder,Personnel');

    Route::middleware('role:Administrator,HR,Supervisor,Encoder,Personnel')->group(function (): void {
        Route::post('/qr-attendance/challenge', [QrAttendanceController::class, 'challenge'])
            ->middleware('throttle:qr-challenge');
        Route::post('/qr-attendance/scan', [QrAttendanceController::class, 'scan'])
            ->middleware('throttle:qr-scan');
    });

    Route::post('/qr-attendance/personnel/{personnel}/regenerate', [QrAttendanceController::class, 'regenerate'])
        ->middleware(['role:Administrator,HR', 'throttle:20,1']);

    Route::get('/dtr', [DtrController::class, 'index']);
    Route::post('/dtr/generate', [DtrController::class, 'generate'])
        ->middleware('throttle:5,1');
    Route::patch('/dtr/{personnel}/status', [DtrController::class, 'updateStatus'])
        ->middleware('throttle:20,1');
    Route::post('/dtr/{personnel}/reopen-requests', [DtrReopenController::class, 'store'])
        ->middleware(['role:Administrator,HR', 'throttle:5,1']);
    Route::patch('/dtr/reopen-requests/{reopenRequest}/review', [DtrReopenController::class, 'review'])
        ->middleware(['role:Administrator', 'throttle:10,1']);

    Route::get('/holidays', [HolidayController::class, 'index']);
    Route::get('/holidays/options', [HolidayController::class, 'options']);
    Route::get('/personnel/{personnel}/photo', [PersonnelController::class, 'photo'])
        ->name('personnel.photo');

    Route::middleware('role:Administrator,HR')->group(function (): void {
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
        Route::get('/activity-logs', [ActivityLogController::class, 'index']);

        Route::get('/system-users/options', [SystemUserController::class, 'options']);
        Route::apiResource('/system-users', SystemUserController::class)
            ->parameters(['system-users' => 'systemUser'])
            ->except(['show']);
    });
});
