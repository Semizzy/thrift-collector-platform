<?php

use App\Domain\Country\CountryConfiguration;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BusinessController;
use App\Http\Controllers\Api\V1\BusinessMembershipController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\AdminDashboardController;
use App\Http\Controllers\Api\V1\ThriftGroupController;
use App\Http\Controllers\Api\V1\ContributionRecordController;
use App\Http\Controllers\Api\V1\ContributionScheduleController;
use App\Http\Controllers\Api\V1\ContributionAdjustmentController;
use App\Http\Controllers\Api\V1\ReconciliationController;
use App\Http\Controllers\Api\V1\PlatformAdminController;
use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\EnsureVerifiedEmail;
use App\Http\Middleware\RequireBusinessMembership;
use App\Http\Middleware\RequireBusinessPermission;
use App\Http\Middleware\RequirePlatformAdmin;
use App\Http\Middleware\RequirePlatformPermission;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', fn () => response()->json([
        'status' => 'ok',
        'service' => 'thrift-collector-platform-api',
        'api_version' => 'v1',
    ]));

    Route::get('/countries', fn () => response()->json([
        'data' => array_map(
            static fn (CountryConfiguration $country): array => $country->toArray(),
            CountryConfiguration::supported(),
        ),
    ]));

    Route::middleware(AuthenticateApiToken::class)->group(function (): void {
        Route::get('/dashboard/summary', [DashboardController::class, 'summary']);

        Route::middleware(RequirePlatformAdmin::class)->prefix('admin')->group(function (): void {
            Route::get('/dashboard', [AdminDashboardController::class, 'summary'])
                ->middleware(RequirePlatformPermission::class.':platform.users.view');
            Route::post('/users/{userId}/provision', [PlatformAdminController::class, 'provision'])
                ->whereNumber('userId')
                ->middleware(RequirePlatformPermission::class.':platform.admins.manage');
            Route::get('/audit-events', [PlatformAdminController::class, 'auditEvents'])
                ->middleware(RequirePlatformPermission::class.':platform.audit.view');
        });
    });

    Route::prefix('auth')->group(function (): void {
        Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
        Route::post('/password/forgot', [AuthController::class, 'forgotPassword'])->middleware('throttle:3,1');
        Route::post('/password/reset', [AuthController::class, 'resetPassword'])->middleware('throttle:5,1');
        Route::get('/email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
            ->middleware('signed')
            ->name('verification.verify');

        Route::middleware(AuthenticateApiToken::class)->group(function (): void {
            Route::get('/me', [AuthController::class, 'me']);
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::post('/email/verification-notification', [AuthController::class, 'resendVerification'])->middleware('throttle:3,1');
        });
    });

    Route::middleware([AuthenticateApiToken::class, EnsureVerifiedEmail::class])->group(function (): void {
        Route::get('/businesses', [BusinessController::class, 'index']);
        Route::post('/businesses', [BusinessController::class, 'store'])->middleware('throttle:10,1');
        Route::post('/business/invitations/accept', [BusinessMembershipController::class, 'accept'])->middleware('throttle:10,1');

        Route::middleware(RequireBusinessMembership::class)->prefix('business')->group(function (): void {
            Route::get('/current', [BusinessController::class, 'current']);
            Route::get('/reconciliation/batches', [ReconciliationController::class, 'index'])
                ->middleware(RequireBusinessPermission::class.':reconciliation.manage');
            Route::post('/reconciliation/batches', [ReconciliationController::class, 'store'])
                ->middleware([RequireBusinessPermission::class.':reconciliation.manage', 'throttle:10,1']);
            Route::post('/reconciliation/entries/{entryId}/decision', [ReconciliationController::class, 'match'])
                ->whereNumber('entryId')
                ->middleware(RequireBusinessPermission::class.':reconciliation.manage');
            Route::get('/groups', [ThriftGroupController::class, 'index'])
                ->middleware(RequireBusinessPermission::class.':arrangements.view');
            Route::post('/groups', [ThriftGroupController::class, 'store'])
                ->middleware([RequireBusinessPermission::class.':arrangements.manage', 'throttle:10,1']);
            Route::post('/groups/{groupId}/members', [ThriftGroupController::class, 'addMember'])
                ->whereNumber('groupId')
                ->middleware([RequireBusinessPermission::class.':members.manage', 'throttle:20,1']);
            Route::delete('/groups/{groupId}/members/{userId}', [ThriftGroupController::class, 'removeMember'])
                ->whereNumber('groupId')->whereNumber('userId')
                ->middleware(RequireBusinessPermission::class.':members.manage');
            Route::get('/groups/{groupId}/schedules', [ContributionScheduleController::class, 'index'])
                ->whereNumber('groupId')
                ->middleware(RequireBusinessPermission::class.':arrangements.view');
            Route::post('/groups/{groupId}/schedules', [ContributionScheduleController::class, 'store'])
                ->whereNumber('groupId')
                ->middleware([RequireBusinessPermission::class.':arrangements.manage', 'throttle:10,1']);
            Route::post('/groups/{groupId}/schedules/{scheduleId}/generate-cycles', [ContributionScheduleController::class, 'generateCycles'])
                ->whereNumber('groupId')->whereNumber('scheduleId')
                ->middleware([RequireBusinessPermission::class.':arrangements.manage', 'throttle:10,1']);
            Route::get('/groups/{groupId}/cycles', [ContributionScheduleController::class, 'cycles'])
                ->whereNumber('groupId')
                ->middleware(RequireBusinessPermission::class.':arrangements.view');
            Route::get('/groups/{groupId}/adjustments', [ContributionAdjustmentController::class, 'index'])
                ->whereNumber('groupId')
                ->middleware(RequireBusinessPermission::class.':disputes.review');
            Route::post('/groups/{groupId}/contributions/{recordId}/adjustments', [ContributionAdjustmentController::class, 'request'])
                ->whereNumber('groupId')->whereNumber('recordId')
                ->middleware([RequireBusinessPermission::class.':contributions.record', 'throttle:10,1']);
            Route::post('/groups/{groupId}/adjustments/{adjustmentId}/decide', [ContributionAdjustmentController::class, 'decide'])
                ->whereNumber('groupId')->whereNumber('adjustmentId')
                ->middleware(RequireBusinessPermission::class.':disputes.review');
            Route::get('/groups/{groupId}/contributions', [ContributionRecordController::class, 'index'])
                ->whereNumber('groupId')
                ->middleware(RequireBusinessPermission::class.':arrangements.view');
            Route::post('/groups/{groupId}/contributions', [ContributionRecordController::class, 'store'])
                ->whereNumber('groupId')
                ->middleware([RequireBusinessPermission::class.':contributions.record', 'throttle:20,1']);
            Route::post('/groups/{groupId}/contributions/{recordId}/confirm', [ContributionRecordController::class, 'confirm'])
                ->whereNumber('groupId')->whereNumber('recordId')
                ->middleware(RequireBusinessPermission::class.':collections.confirm');

            Route::get('/members', [BusinessMembershipController::class, 'index'])
                ->middleware(RequireBusinessPermission::class.':members.view');
            Route::get('/audit-events', [BusinessMembershipController::class, 'auditEvents'])
                ->middleware(RequireBusinessPermission::class.':members.view');
            Route::get('/invitations', [BusinessMembershipController::class, 'invitations'])
                ->middleware(RequireBusinessPermission::class.':members.manage');
            Route::post('/invitations', [BusinessMembershipController::class, 'invite'])
                ->middleware([RequireBusinessPermission::class.':members.manage', 'throttle:10,1']);
            Route::delete('/invitations/{invitationId}', [BusinessMembershipController::class, 'revokeInvitation'])
                ->whereNumber('invitationId')
                ->middleware(RequireBusinessPermission::class.':members.manage');
            Route::patch('/members/{membershipId}/role', [BusinessMembershipController::class, 'changeRole'])
                ->whereNumber('membershipId')
                ->middleware(RequireBusinessPermission::class.':members.manage');
            Route::delete('/members/{membershipId}', [BusinessMembershipController::class, 'remove'])
                ->whereNumber('membershipId')
                ->middleware(RequireBusinessPermission::class.':members.manage');
        });
    });
});
