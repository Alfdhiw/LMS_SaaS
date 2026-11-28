<?php

use App\Http\Controllers\Academic\AcademicController;
use App\Http\Controllers\Academic\SchoolProfileController;
use App\Http\Controllers\Auth\ExistingAccountInvitationController;
use App\Http\Controllers\Auth\MemberInvitationController;
use App\Http\Controllers\Auth\MembershipController;
use App\Http\Controllers\Auth\PasswordChangeController;
use App\Http\Controllers\Auth\SchoolAssistedRecoveryController;
use App\Http\Controllers\Auth\SelfEmailRecoveryController;
use App\Http\Controllers\Auth\TenantActivationController;
use App\Http\Controllers\Auth\TenantAuthController;
use App\Http\Middleware\EnsureTenantSession;
use App\Http\Middleware\RequirePasswordChange;
use App\Http\Middleware\RequireSecureIdentityTransport;
use App\Http\Middleware\RequireTenantActivationHost;
use App\Http\Middleware\RequireTenantHost;
use Illuminate\Support\Facades\Route;

Route::middleware(RequireTenantActivationHost::class)->group(function (): void {
    Route::get('/activate/{invitationPublicUlid}/{token}', [TenantActivationController::class, 'show'])
        ->name('tenant.activation.show');
    Route::post('/activate/{invitationPublicUlid}/{token}', [TenantActivationController::class, 'activate'])
        ->name('tenant.activation.activate');
});

Route::middleware(RequireTenantHost::class)->group(function (): void {
    Route::get('/password/forgot', [SelfEmailRecoveryController::class, 'requestForm'])->middleware(RequireSecureIdentityTransport::class)->name('tenant.password.forgot');
    Route::post('/password/forgot', [SelfEmailRecoveryController::class, 'issue'])->middleware('throttle:5,1');
    Route::get('/password/reset', [SelfEmailRecoveryController::class, 'resetForm'])->middleware(RequireSecureIdentityTransport::class)->name('tenant.password.reset');
    Route::post('/password/reset', [SelfEmailRecoveryController::class, 'consume'])->middleware('throttle:5,1');
    Route::get('/invitations/accept', fn () => response()->view('auth.invitation-accept')->header('Cache-Control', 'no-store'))->middleware(RequireSecureIdentityTransport::class);
    Route::post('/invitations/join', [ExistingAccountInvitationController::class, 'accept'])->middleware('throttle:5,1');
    Route::post('/invitations/activate', [MemberInvitationController::class, 'activate'])->middleware('throttle:5,1');
    Route::get('/recovery/school-assisted', [SchoolAssistedRecoveryController::class, 'studentForm'])
        ->middleware(RequireSecureIdentityTransport::class);
    Route::post('/recovery/school-assisted/request', [SchoolAssistedRecoveryController::class, 'requestRecovery'])
        ->middleware([RequireSecureIdentityTransport::class, 'throttle:5,1']);
    Route::post('/recovery/school-assisted', [SchoolAssistedRecoveryController::class, 'consume'])
        ->middleware([RequireSecureIdentityTransport::class, 'throttle:5,1']);
    Route::get('/login', [TenantAuthController::class, 'showLogin'])->name('tenant.login');
    Route::post('/login', [TenantAuthController::class, 'login']);

    Route::middleware([EnsureTenantSession::class, RequirePasswordChange::class])->group(function (): void {
        Route::get('/password/change', [PasswordChangeController::class, 'edit'])
            ->middleware(RequireSecureIdentityTransport::class)->name('tenant.password.change');
        Route::post('/password/change', [PasswordChangeController::class, 'update'])
            ->middleware([RequireSecureIdentityTransport::class, 'throttle:5,1'])->name('tenant.password.change.update');
        Route::get('/members/manage', fn () => response()->view('auth.members-manage')->header('Cache-Control', 'no-store'))
            ->middleware([RequireSecureIdentityTransport::class, 'tenant.permission:members.invite']);
        Route::post('/members/invitations/existing', [ExistingAccountInvitationController::class, 'issue'])
            ->middleware(['tenant.permission:members.invite', 'throttle:10,1']);
        Route::post('/members/invitations', [MemberInvitationController::class, 'invite'])
            ->middleware(['tenant.permission:members.invite', 'throttle:10,1']);
        Route::get('/members/recovery', [SchoolAssistedRecoveryController::class, 'adminForm'])
            ->middleware([RequireSecureIdentityTransport::class, 'tenant.permission:members.assisted_reset']);
        Route::get('/members/recovery/{recoveryId}', [SchoolAssistedRecoveryController::class, 'preview'])
            ->middleware([RequireSecureIdentityTransport::class, 'tenant.permission:members.assisted_reset', 'throttle:10,1']);
        Route::post('/members/recovery/{recoveryId}/approve', [SchoolAssistedRecoveryController::class, 'approve'])
            ->middleware([RequireSecureIdentityTransport::class, 'tenant.permission:members.assisted_reset', 'throttle:5,1']);
        Route::post('/members/{userUlid}/status', [MembershipController::class, 'status'])
            ->middleware('tenant.permission:members.suspend');
        Route::post('/members/{userUlid}/roles', [MembershipController::class, 'roles'])
            ->middleware('tenant.permission:members.assign_roles');
        Route::get('/dashboard', [TenantAuthController::class, 'dashboard'])
            ->middleware('tenant.permission:school.view')
            ->name('tenant.dashboard');
        Route::get('/school/profile', [SchoolProfileController::class, 'edit'])
            ->middleware('tenant.permission:school.view')->name('tenant.school.edit');
        Route::post('/school/profile', [SchoolProfileController::class, 'update'])
            ->middleware('tenant.permission:school.update')->name('tenant.school.update');
        Route::middleware('tenant.permission:academic.manage')->group(function (): void {
            Route::get('/academic/periods', [AcademicController::class, 'periods'])->name('tenant.academic.periods');
            Route::post('/academic/years', [AcademicController::class, 'createYear']);
            Route::post('/academic/years/{yearUlid}', [AcademicController::class, 'updateYear']);
            Route::post('/academic/years/{yearUlid}/semesters', [AcademicController::class, 'createSemester']);
            Route::post('/academic/years/{yearUlid}/status', [AcademicController::class, 'transitionYear']);
            Route::post('/academic/semesters/{semesterUlid}/status', [AcademicController::class, 'transitionSemester']);
            Route::post('/academic/semesters/{semesterUlid}', [AcademicController::class, 'updateSemester']);
            Route::get('/academic/profiles/{type}', [AcademicController::class, 'profiles'])->middleware('tenant.permission:members.view');
            Route::post('/academic/profiles/{type}', [AcademicController::class, 'createProfile'])->middleware('tenant.permission:members.view');
            Route::post('/academic/profiles/{type}/{profileUlid}', [AcademicController::class, 'updateProfile'])->middleware('tenant.permission:members.view');
        });
        Route::middleware('tenant.permission:subjects.manage')->group(function (): void {
            Route::get('/academic/subjects', [AcademicController::class, 'subjects']);
            Route::post('/academic/subjects', [AcademicController::class, 'createSubject']);
            Route::post('/academic/subjects/{subjectUlid}', [AcademicController::class, 'updateSubject']);
        });
        Route::post('/logout', [TenantAuthController::class, 'logout'])->name('tenant.logout');
    });
});
