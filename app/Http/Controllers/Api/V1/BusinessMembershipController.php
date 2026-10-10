<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BusinessAuditEvent;
use App\Models\BusinessMembership;
use App\Models\BusinessMembershipInvitation;
use App\Notifications\BusinessMembershipInvitationNotification;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class BusinessMembershipController extends Controller
{
    private const INVITABLE_ROLES = ['business_manager', 'field_agent', 'group_organizer', 'auditor', 'contributor'];
    private const MANAGER_INVITABLE_ROLES = ['field_agent', 'group_organizer', 'auditor', 'contributor'];

    public function index(Request $request): JsonResponse
    {
        $business = $request->attributes->get('current_business');
        $members = $business->memberships()
            ->with('user:id,name,email,email_verified_at')
            ->get()
            ->map(static fn ($membership): array => [
                'id' => $membership->id,
                'user' => $membership->user,
                'role' => $membership->role,
                'status' => $membership->status,
                'joined_at' => $membership->joined_at,
            ]);

        return response()->json(['data' => $members]);
    }

    public function invitations(Request $request): JsonResponse
    {
        $business = $request->attributes->get('current_business');
        $invitations = $business->membershipInvitations()
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->orderByDesc('created_at')
            ->get(['id', 'email', 'role', 'invited_by_user_id', 'expires_at', 'created_at']);

        return response()->json(['data' => $invitations]);
    }

    public function invite(Request $request): JsonResponse
    {
        $business = $request->attributes->get('current_business');
        $actor = $request->user();
        $actorMembership = $request->attributes->get('business_membership');
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:254'],
            'role' => ['required', 'string', Rule::in(self::INVITABLE_ROLES)],
            'expires_in_days' => ['sometimes', 'integer', 'min:1', 'max:14'],
        ]);
        $email = Str::lower(trim($data['email']));
        $role = $data['role'];

        if ($actorMembership->role === 'business_manager' && ! in_array($role, self::MANAGER_INVITABLE_ROLES, true)) {
            return response()->json(['message' => 'Managers may invite operational roles only.'], 403);
        }

        $existingUser = \App\Models\User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
        if ($existingUser && $business->memberships()->where('user_id', $existingUser->id)->exists()) {
            return response()->json(['message' => 'This user already has a membership record for this business.'], 409);
        }

        $pending = $business->membershipInvitations()
            ->where('email', $email)->whereNull('accepted_at')->whereNull('revoked_at')
            ->where('expires_at', '>', now())->exists();
        if ($pending) {
            return response()->json(['message' => 'An active invitation already exists for this email.'], 409);
        }

        $plainToken = Str::random(64);
        try {
            $invitation = DB::transaction(function () use ($request, $business, $actor, $email, $role, $data, $plainToken): BusinessMembershipInvitation {
                $invitation = $business->membershipInvitations()->create([
                    'email' => $email,
                    'role' => $role,
                    'token_hash' => hash('sha256', $plainToken),
                    'invited_by_user_id' => $actor->id,
                    'expires_at' => now()->addDays((int) ($data['expires_in_days'] ?? 7)),
                ]);
                $this->audit($request, 'membership.invitation.created', $business->id, 'invitation', $invitation->id, [
                    'email' => $email, 'role' => $role, 'expires_at' => $invitation->expires_at->toISOString(),
                ]);

                return $invitation;
            });
        } catch (QueryException) {
            return response()->json(['message' => 'The invitation could not be created due to a conflicting membership change. Retry safely.'], 409);
        }

        Notification::route('mail', $email)->notify(new BusinessMembershipInvitationNotification($invitation->load('business'), $plainToken));

        return response()->json([
            'data' => ['id' => $invitation->id, 'email' => $invitation->email, 'role' => $invitation->role, 'expires_at' => $invitation->expires_at],
            'message' => 'Invitation created and email notification dispatched.',
        ], 201);
    }

    public function accept(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'min:32', 'max:200']]);
        $user = $request->user();
        $tokenHash = hash('sha256', $data['token']);

        $result = DB::transaction(function () use ($request, $user, $tokenHash): array {
            $invitation = BusinessMembershipInvitation::query()->where('token_hash', $tokenHash)->lockForUpdate()->first();
            if (! $invitation || $invitation->accepted_at || $invitation->revoked_at || $invitation->expires_at->isPast()) {
                return ['status' => 410, 'message' => 'Invitation is invalid, expired, already used, or revoked.'];
            }
            if (! $user->hasVerifiedEmail() || Str::lower($user->email) !== Str::lower($invitation->email)) {
                return ['status' => 403, 'message' => 'A verified account matching the invited email is required.'];
            }

            $membership = BusinessMembership::query()
                ->where('business_id', $invitation->business_id)
                ->where('user_id', $user->id)
                ->lockForUpdate()->first();

            if ($membership && $membership->status === 'active') {
                return ['status' => 409, 'message' => 'You already have active membership in this business.'];
            }

            if ($membership) {
                $membership->forceFill([
                    'role' => $invitation->role, 'status' => 'active',
                    'invited_by_user_id' => $invitation->invited_by_user_id, 'joined_at' => now(),
                ])->save();
            } else {
                $membership = BusinessMembership::query()->create([
                    'business_id' => $invitation->business_id, 'user_id' => $user->id,
                    'role' => $invitation->role, 'status' => 'active',
                    'invited_by_user_id' => $invitation->invited_by_user_id, 'joined_at' => now(),
                ]);
            }

            $invitation->forceFill(['accepted_at' => now(), 'accepted_by_user_id' => $user->id])->save();
            $this->audit($request, 'membership.invitation.accepted', $invitation->business_id, 'membership', $membership->id, [
                'invitation_id' => $invitation->id, 'role' => $membership->role, 'member_user_id' => $user->id,
            ]);

            return ['status' => 200, 'message' => 'Business invitation accepted.', 'business_id' => $invitation->business_id, 'membership_id' => $membership->id];
        });

        $status = $result['status'];
        unset($result['status']);

        return response()->json($result, $status);
    }

    public function revokeInvitation(Request $request, int $invitationId): JsonResponse
    {
        $business = $request->attributes->get('current_business');
        $invitation = $business->membershipInvitations()->whereKey($invitationId)->first();
        if (! $invitation) {
            return response()->json(['message' => 'Invitation not found.'], 404);
        }
        if ($invitation->accepted_at || $invitation->revoked_at) {
            return response()->json(['message' => 'Only pending invitations can be revoked.'], 409);
        }

        DB::transaction(function () use ($request, $business, $invitation): void {
            $invitation->forceFill(['revoked_at' => now()])->save();
            $this->audit($request, 'membership.invitation.revoked', $business->id, 'invitation', $invitation->id, [
                'email' => $invitation->email, 'role' => $invitation->role,
            ]);
        });

        return response()->json(['message' => 'Invitation revoked.']);
    }

    public function changeRole(Request $request, int $membershipId): JsonResponse
    {
        $business = $request->attributes->get('current_business');
        $actorMembership = $request->attributes->get('business_membership');
        $data = $request->validate(['role' => ['required', 'string', Rule::in(self::INVITABLE_ROLES)]]);

        if ($actorMembership->role === 'business_manager' && ! in_array($data['role'], self::MANAGER_INVITABLE_ROLES, true)) {
            return response()->json(['message' => 'Managers may assign operational roles only.'], 403);
        }

        return DB::transaction(function () use ($request, $business, $membershipId, $data, $actorMembership) {
            $target = $business->memberships()->whereKey($membershipId)->lockForUpdate()->first();
            if (! $target || $target->status !== 'active') {
                return response()->json(['message' => 'Active membership not found.'], 404);
            }
            if ($target->id === $actorMembership->id) {
                return response()->json(['message' => 'You cannot change your own role.'], 403);
            }
            if ($target->role === 'business_owner') {
                return response()->json(['message' => 'Owner role changes require a separate ownership-transfer workflow.'], 403);
            }
            if ($actorMembership->role === 'business_manager' && $target->role === 'business_manager') {
                return response()->json(['message' => 'Managers cannot change another manager\'s role.'], 403);
            }

            $oldRole = $target->role;
            $target->forceFill(['role' => $data['role']])->save();
            $this->audit($request, 'membership.role.changed', $business->id, 'membership', $target->id, [
                'member_user_id' => $target->user_id, 'old_role' => $oldRole, 'new_role' => $target->role,
            ]);

            return response()->json(['data' => ['id' => $target->id, 'user_id' => $target->user_id, 'role' => $target->role], 'message' => 'Membership role updated.']);
        });
    }

    public function remove(Request $request, int $membershipId): JsonResponse
    {
        $business = $request->attributes->get('current_business');
        $actorMembership = $request->attributes->get('business_membership');

        return DB::transaction(function () use ($request, $business, $membershipId, $actorMembership) {
            $target = $business->memberships()->whereKey($membershipId)->lockForUpdate()->first();
            if (! $target || $target->status !== 'active') {
                return response()->json(['message' => 'Active membership not found.'], 404);
            }
            if ($target->id === $actorMembership->id) {
                return response()->json(['message' => 'You cannot remove your own membership.'], 403);
            }
            if ($target->role === 'business_owner') {
                return response()->json(['message' => 'Owner membership cannot be removed through this endpoint.'], 403);
            }
            if ($actorMembership->role === 'business_manager' && $target->role === 'business_manager') {
                return response()->json(['message' => 'Managers cannot remove another manager.'], 403);
            }

            $target->forceFill(['status' => 'inactive'])->save();
            $this->audit($request, 'membership.removed', $business->id, 'membership', $target->id, [
                'member_user_id' => $target->user_id, 'role' => $target->role,
            ]);

            return response()->json(['message' => 'Membership deactivated.']);
        });
    }

    private function audit(Request $request, string $action, int $businessId, string $subjectType, ?int $subjectId, array $metadata = []): void
    {
        BusinessAuditEvent::query()->create([
            'business_id' => $businessId,
            'actor_user_id' => $request->user()?->id,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'metadata' => $metadata,
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
            'created_at' => now(),
        ]);
    }
}
