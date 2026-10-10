<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PlatformAuditEvent;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PlatformAdminController extends Controller
{
    private const GRANTABLE_PERMISSIONS = ['platform.admins.manage', 'platform.audit.view', 'platform.users.view', 'platform.security.manage'];

    public function provision(Request $request, int $userId): JsonResponse
    {
        $data = $request->validate([
            'platform_role' => ['required', Rule::in(['member', 'admin'])],
            'permissions' => ['sometimes', 'array', 'max:10'],
            'permissions.*' => ['string', Rule::in(self::GRANTABLE_PERMISSIONS)],
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);
        if ($request->user()->id === $userId) {
            return response()->json(['message' => 'Administrators cannot change their own platform role or permissions.'], 403);
        }

        $subject = User::query()->find($userId);
        if (! $subject) return response()->json(['message' => 'User not found.'], 404);
        $permissions = array_values(array_unique($data['permissions'] ?? []));
        if ($data['platform_role'] !== 'admin' && $permissions !== []) {
            return response()->json(['message' => 'Platform permissions can only be assigned to platform administrators.'], 422);
        }

        DB::transaction(function () use ($request, $subject, $data, $permissions): void {
            $lockedSubject = User::query()->whereKey($subject->id)->lockForUpdate()->firstOrFail();
            $before = ['platform_role' => $lockedSubject->platform_role, 'permissions' => $lockedSubject->platform_permissions ?? []];
            $lockedSubject->forceFill([
                'platform_role' => $data['platform_role'],
                'platform_permissions' => $data['platform_role'] === 'admin' ? $permissions : null,
            ])->save();
            PlatformAuditEvent::query()->create([
                'actor_user_id' => $request->user()->id, 'subject_user_id' => $lockedSubject->id,
                'action' => 'platform_admin.provisioned', 'metadata' => [
                    'before' => $before,
                    'after' => ['platform_role' => $lockedSubject->platform_role, 'permissions' => $lockedSubject->platform_permissions ?? []],
                    'reason' => $data['reason'],
                ],
                'ip_address' => $request->ip(), 'user_agent' => Str::limit((string) $request->userAgent(), 500, ''), 'created_at' => now(),
            ]);
        });

        return response()->json(['message' => 'Platform role and permissions updated and audited.']);
    }

    public function securitySettings(): JsonResponse
    {
        $hours = (int) (DB::table('platform_settings')->where('key', 'login_lockout_hours')->value('value') ?? 1);
        return response()->json(['data' => [
            'failed_login_attempt_limit' => 3,
            'login_lockout_hours' => max(1, min(720, $hours)),
            'transaction_pin_failed_attempt_limit' => 3,
            'transaction_pin_lockout_minutes' => 15,
        ]]);
    }

    public function updateSecuritySettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'login_lockout_hours' => ['required', 'integer', 'min:1', 'max:720'],
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);

        DB::transaction(function () use ($request, $data): void {
            DB::table('platform_settings')->updateOrInsert(
                ['key' => 'login_lockout_hours'],
                ['value' => (string) $data['login_lockout_hours'], 'updated_by_user_id' => $request->user()->id, 'updated_at' => now()]
            );
            PlatformAuditEvent::query()->create([
                'actor_user_id' => $request->user()->id, 'subject_user_id' => $request->user()->id,
                'action' => 'platform.security.login_lockout_updated',
                'metadata' => ['login_lockout_hours' => (int) $data['login_lockout_hours'], 'reason' => trim($data['reason'])],
                'ip_address' => $request->ip(), 'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
                'created_at' => now(),
            ]);
        });

        return response()->json(['data' => [
            'failed_login_attempt_limit' => 3,
            'login_lockout_hours' => (int) $data['login_lockout_hours'],
            'transaction_pin_failed_attempt_limit' => 3,
            'transaction_pin_lockout_minutes' => 15,
        ], 'message' => 'Login lockout policy updated and audited.']);
    }

    public function auditEvents(): JsonResponse
    {
        return response()->json(['data' => PlatformAuditEvent::query()
            ->with(['actor:id,name,email', 'subject:id,name,email'])
            ->orderByDesc('created_at')->paginate(50)]);
    }
}
