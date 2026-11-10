<?php

use App\Http\Controllers\AccessAuditController;
use App\Http\Controllers\AccessEnrollmentController;
use App\Http\Controllers\AccessGroupController;
use App\Http\Controllers\AccessMatrixController;
use App\Http\Controllers\AccessSyncController;
use App\Http\Controllers\AccessUserController;
use App\Http\Controllers\AccessWizardController;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeviceActivityController;
use App\Http\Controllers\DeviceAlertController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\DoorController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\GlobalSearchController;
use App\Http\Controllers\PersonnelController;
use App\Http\Controllers\SuperFingerprintController;
use App\Http\Controllers\TerminalController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->middleware('throttle:6,1')->name('login.store');
    Route::get('/setup', [AuthController::class, 'setupCreate'])->name('setup.create');
    Route::post('/setup', [AuthController::class, 'setupStore'])->name('setup.store');
});

Route::middleware(['auth', 'auth.session'])->group(function (): void {
    Route::get('/account/password', [AccountController::class, 'edit'])->name('account.password.edit');
    Route::put('/account/password', [AccountController::class, 'update'])->middleware('throttle:6,1')->name('account.password.update');
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');

    // Dashboard
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard/live-data', [DashboardController::class, 'liveData'])->name('dashboard.live-data');

    // Global Search
    Route::get('/search/live', [GlobalSearchController::class, 'search'])->name('search.live');

    // Physical Terminals
    Route::resource('terminals', TerminalController::class)->except(['show'])->middleware('can:devices.manage');

    // Managed departments / access groups
    Route::resource('access-groups', AccessGroupController::class)->except(['show']);

    // Terminal administrator fingerprints
    Route::get('/super-fingerprints', [SuperFingerprintController::class, 'index'])->name('super-fingerprints.index');
    Route::get('/super-fingerprints/records', [SuperFingerprintController::class, 'records'])->name('super-fingerprints.records');
    Route::post('/super-fingerprints', [SuperFingerprintController::class, 'enroll'])->middleware('can:access.manage')->name('super-fingerprints.enroll');

    // Device monitoring: literal paths are registered before the numeric
    // device routes so /devices/alerts and /devices/activity never resolve to
    // a device id.
    Route::get('/devices', [DeviceController::class, 'index'])->name('devices.index');
    Route::get('/devices/statuses', [DeviceController::class, 'statuses'])->middleware('throttle:device-poll')->name('devices.statuses');
    Route::post('/devices/refresh', [DeviceController::class, 'refreshDevices'])->middleware('throttle:device-refresh')->name('devices.refresh.bulk');
    Route::get('/devices/refresh-runs/{run}', [DeviceController::class, 'refreshRun'])->whereNumber('run')->name('devices.refresh.run');
    Route::post('/devices/refresh-runs/{run}/cancel', [DeviceController::class, 'cancelRefresh'])->whereNumber('run')->name('devices.refresh.cancel');
    Route::post('/devices/monitor-settings', [DeviceController::class, 'updateMonitorSettings'])->name('devices.monitor.settings');

    Route::get('/devices/alerts', [DeviceAlertController::class, 'index'])->name('devices.alerts.index');
    Route::post('/devices/alerts/acknowledge-all', [DeviceAlertController::class, 'acknowledgeAll'])->name('devices.alerts.acknowledge-all');
    Route::post('/devices/alerts/{alert}/acknowledge', [DeviceAlertController::class, 'acknowledge'])->whereNumber('alert')->name('devices.alerts.acknowledge');

    Route::get('/devices/activity', [DeviceActivityController::class, 'index'])->name('devices.activity.index');
    Route::get('/devices/activity/export', [DeviceActivityController::class, 'exportCsv'])->name('devices.activity.export');

    Route::post('/devices/{terminal}/refresh', [DeviceController::class, 'refresh'])->whereNumber('terminal')->middleware('throttle:device-refresh')->name('devices.refresh');
    Route::get('/devices/{terminal}/diagnostics', [DeviceController::class, 'diagnostics'])->whereNumber('terminal')->middleware('throttle:device-diagnostics')->name('devices.diagnostics');
    Route::post('/devices/{terminal}/reconnect', [DeviceController::class, 'reconnect'])->whereNumber('terminal')->middleware('throttle:device-refresh')->name('devices.reconnect');

    // Remote door actions (existing behaviour and route names preserved)
    Route::post('/devices/{terminalName}/door/{doorNo}/open', [DeviceController::class, 'openDoor'])->middleware(['can:devices.manage', 'throttle:device-refresh'])->name('devices.door.open');
    Route::post('/devices/{terminalName}/door/{doorNo}/close', [DeviceController::class, 'closeDoor'])->middleware(['can:devices.manage', 'throttle:device-refresh'])->name('devices.door.close');
    Route::get('/devices/{terminalName}/door/{doorNo}/status', [DeviceController::class, 'getDoorStatus'])->name('devices.door.status');

    // Personnel — Import & Sync (must be before resource to avoid route conflicts)
    Route::post('/personnel/import-all', [PersonnelController::class, 'importAll'])->middleware('can:access.manage')->name('personnel.import.all');
    Route::post('/personnel/{terminal}/import', [PersonnelController::class, 'import'])->middleware('can:access.manage')->name('personnel.import');
    Route::post('/personnel/sync-all', [PersonnelController::class, 'syncAll'])->middleware('can:access.manage')->name('personnel.sync.all');
    Route::post('/personnel/{personnel}/sync', [PersonnelController::class, 'sync'])->middleware('can:access.manage')->name('personnel.sync');

    // Personnel — standard CRUD
    Route::resource('personnel', PersonnelController::class)->middlewareFor(['create', 'store', 'edit', 'update', 'destroy'], 'can:access.manage');

    // Personnel — Fingerprint Enrollment
    Route::post('/personnel/prime-enrollment', [PersonnelController::class, 'primeEnrollment'])->middleware('can:access.manage')->name('personnel.prime.enrollment');
    Route::post('/personnel/{personnel}/enroll-fingerprint', [PersonnelController::class, 'enrollFingerprint'])->middleware('can:access.manage')->name('personnel.enroll.fingerprint');

    // Personnel — Exports
    Route::get('/personnel/export/csv', [PersonnelController::class, 'export'])->name('personnel.export');
    Route::get('/personnel/export/word', [PersonnelController::class, 'exportWord'])->name('personnel.export.word');

    // Access control: door-level permissions, onboarding wizard, matrix,
    // synchronization and audit. Existing /personnel routes stay untouched.
    Route::prefix('access')->name('access.')->group(function (): void {
        Route::get('/', [AccessUserController::class, 'index'])->name('index');

        // Downloads & Exports — literal routes registered before user parameter routes
        Route::get('users/export/csv', [AccessUserController::class, 'exportCsv'])->name('users.export.csv');
        Route::get('users/export/pdf', [AccessUserController::class, 'exportPdf'])->name('users.export.pdf');
        Route::get('users/export/word', [AccessUserController::class, 'exportWord'])->name('users.export.word');

        // Onboarding — literal "create" routes are registered before the
        // parameterised user routes.
        Route::get('users/create', [AccessWizardController::class, 'details'])->name('users.create');
        Route::post('users/create', [AccessWizardController::class, 'storeDetails'])->name('users.create.store');
        Route::get('users/create/device', [AccessWizardController::class, 'device'])->name('wizard.device');
        Route::post('users/create/device', [AccessWizardController::class, 'storeDevice'])->name('wizard.device.store');
        Route::get('users/create/enrollment', [AccessWizardController::class, 'capture'])->name('wizard.capture');
        Route::post('users/create/enrollment', [AccessWizardController::class, 'startEnrollment'])->name('wizard.capture.start');
        Route::post('users/create/enrollments/{enrollment}/cancel', [AccessWizardController::class, 'cancelEnrollment'])->whereNumber('enrollment')->name('wizard.capture.cancel');
        Route::post('users/create/enrollments/{enrollment}/retry', [AccessWizardController::class, 'retryEnrollment'])->whereNumber('enrollment')->name('wizard.capture.retry');
        Route::post('users/create/skip-enrollment', [AccessWizardController::class, 'skipEnrollment'])->name('wizard.capture.skip');
        Route::get('users/create/doors', [AccessWizardController::class, 'doors'])->name('wizard.doors');
        Route::post('users/create/doors', [AccessWizardController::class, 'saveDoors'])->name('wizard.doors.store');
        Route::get('users/create/review', [AccessWizardController::class, 'review'])->name('wizard.review');
        Route::post('users/create/review', [AccessWizardController::class, 'deploy'])->name('wizard.deploy');
        Route::delete('users/create', [AccessWizardController::class, 'cancel'])->name('wizard.cancel');

        Route::get('enrollments/{enrollment}', [AccessEnrollmentController::class, 'show'])
            ->whereNumber('enrollment')->middleware('throttle:120,1')->name('enrollments.show');

        Route::get('users/{personnel}/deployed', [AccessWizardController::class, 'deployed'])->whereNumber('personnel')->name('users.deployed');
        Route::get('users/{personnel}/enroll', [AccessUserController::class, 'enrollForm'])->whereNumber('personnel')->name('users.enroll');
        Route::post('users/{personnel}/enroll', [AccessUserController::class, 'startEnrollment'])->whereNumber('personnel')->name('users.enroll.start');
        Route::post('users/{personnel}/enrollments/{enrollment}/cancel', [AccessUserController::class, 'cancelEnrollment'])->whereNumber('personnel')->whereNumber('enrollment')->name('users.enroll.cancel');
        Route::post('users/{personnel}/enrollments/{enrollment}/retry', [AccessUserController::class, 'retryEnrollment'])->whereNumber('personnel')->whereNumber('enrollment')->name('users.enroll.retry');
        Route::get('users/{personnel}/photo', [AccessUserController::class, 'photo'])->whereNumber('personnel')->name('users.photo');
        Route::post('users/{personnel}/resume', [AccessWizardController::class, 'resume'])->whereNumber('personnel')->name('users.resume');
        Route::delete('users/{personnel}/draft', [AccessUserController::class, 'discardDraft'])->whereNumber('personnel')->name('users.discard');
        Route::post('users/{personnel}/doors', [AccessUserController::class, 'grantDoors'])->whereNumber('personnel')->name('users.doors.grant');
        Route::post('users/{personnel}/doors/revoke', [AccessUserController::class, 'revokeDoors'])->whereNumber('personnel')->name('users.doors.revoke');
        Route::post('users/{personnel}/sync', [AccessUserController::class, 'sync'])->whereNumber('personnel')->name('users.sync');
        Route::post('users/{personnel}/suspend', [AccessUserController::class, 'suspend'])->whereNumber('personnel')->name('users.suspend');
        Route::post('users/{personnel}/reactivate', [AccessUserController::class, 'reactivate'])->whereNumber('personnel')->name('users.reactivate');
        Route::get('users/{personnel}/edit', [AccessUserController::class, 'edit'])->whereNumber('personnel')->name('users.edit');
        Route::put('users/{personnel}', [AccessUserController::class, 'update'])->whereNumber('personnel')->name('users.update');
        Route::delete('users/{personnel}', [AccessUserController::class, 'destroy'])->whereNumber('personnel')->name('users.destroy');
        Route::get('users/{personnel}', [AccessUserController::class, 'show'])->whereNumber('personnel')->name('users.show');

        Route::get('doors', [DoorController::class, 'index'])->name('doors.index');
        Route::post('doors/provision', [DoorController::class, 'provision'])->name('doors.provision');
        Route::post('doors/probe/{terminal}', [DoorController::class, 'probe'])->whereNumber('terminal')->name('doors.probe');
        Route::post('doors', [DoorController::class, 'store'])->name('doors.store');
        Route::put('doors/{door}', [DoorController::class, 'update'])->whereNumber('door')->name('doors.update');
        Route::delete('doors/{door}', [DoorController::class, 'destroy'])->whereNumber('door')->name('doors.destroy');

        Route::get('matrix', [AccessMatrixController::class, 'index'])->name('matrix.index');
        Route::get('matrix/export', [AccessMatrixController::class, 'export'])->name('matrix.export');

        Route::get('sync', [AccessSyncController::class, 'index'])->name('sync.index');
        Route::post('sync', [AccessSyncController::class, 'store'])->name('sync.store');
        Route::post('sync/{personnel}', [AccessSyncController::class, 'syncOne'])->whereNumber('personnel')->name('sync.one');

        Route::get('audit', [AccessAuditController::class, 'index'])->name('audit.index');
    });

    // Fingerprint attendance register and operator-confirmed logout records
    Route::get('/attendance', [AttendanceController::class, 'index'])->name('attendance.index');
    Route::get('/attendance/export/csv', [AttendanceController::class, 'exportCsv'])->name('attendance.export.csv');
    Route::get('/attendance/export/pdf', [AttendanceController::class, 'exportPdf'])->name('attendance.export.pdf');
    Route::post('/attendance/{accessEvent}/checkout', [AttendanceController::class, 'checkout'])->name('attendance.checkout');

    // Events / Access Logs / Monitoring
    Route::get('/events', [EventController::class, 'index'])->name('events.index');
    Route::get('/failed-attempts', [EventController::class, 'index'])->name('events.failed');
    Route::post('/events/import', [EventController::class, 'import'])->name('events.import');
    Route::post('/events/import-all', [EventController::class, 'importAll'])->name('events.import.all');
    Route::get('/events/export/pdf', [EventController::class, 'exportPdf'])->name('events.export.pdf');
    Route::get('/events/export/word', [EventController::class, 'exportWord'])->name('events.export.word');
});
