<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BusinessAuditEvent;
use App\Models\ThriftGroup;
use App\Models\ThriftGroupMembership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ThriftGroupController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $business = $request->attributes->get('current_business');
        $groups = ThriftGroup::query()->where('business_id', $business->id)
            ->whereHas('memberships', fn ($query) => $query->where('user_id', $request->user()->id)->where('status', 'active'))
            ->withCount(['memberships as active_members_count' => fn ($query) => $query->where('status', 'active')])
            ->orderBy('name')->paginate(25);

        return response()->json(['data' => $groups]);
    }


    public function addMember(Request $request, int $groupId): JsonResponse
    {
        $business = $request->attributes->get('current_business');
        $data = $request->validate(['user_id' => ['required', 'integer', 'exists:users,id']]);
        $group = ThriftGroup::query()->where('business_id', $business->id)->whereKey($groupId)->firstOrFail();
        $businessMember = $business->memberships()->where('user_id', $data['user_id'])->where('status', 'active')->exists();
        if (! $businessMember) {
            return response()->json(['message' => 'Only active members of this business can be added to its thrift groups.'], 422);
        }
        if ($group->status !== 'active') {
            return response()->json(['message' => 'Members cannot be added to an inactive group.'], 409);
        }

        $membership = DB::transaction(function () use ($request, $business, $group, $data) {
            $membership = ThriftGroupMembership::query()
                ->where('thrift_group_id', $group->id)->where('user_id', $data['user_id'])
                ->lockForUpdate()->first();
            if ($membership && $membership->status === 'active') {
                return null;
            }
            if ($membership) {
                $membership->forceFill([
                    'role' => 'member', 'status' => 'active',
                    'added_by_user_id' => $request->user()->id, 'joined_at' => now(),
                ])->save();
            } else {
                $membership = ThriftGroupMembership::query()->create([
                    'thrift_group_id' => $group->id, 'user_id' => $data['user_id'],
                    'role' => 'member', 'status' => 'active',
                    'added_by_user_id' => $request->user()->id, 'joined_at' => now(),
                ]);
            }
            BusinessAuditEvent::query()->create([
                'business_id' => $business->id, 'actor_user_id' => $request->user()->id,
                'action' => 'thrift_group.member_added', 'subject_type' => 'thrift_group_membership', 'subject_id' => $membership->id,
                'metadata' => ['group_id' => $group->id, 'member_user_id' => $membership->user_id],
                'ip_address' => $request->ip(), 'user_agent' => Str::limit((string) $request->userAgent(), 500, ''), 'created_at' => now(),
            ]);
            return $membership;
        });

        if (! $membership) return response()->json(['message' => 'This user is already an active group member.'], 409);
        return response()->json(['data' => $membership, 'message' => 'Member added to thrift group.'], 201);
    }

    public function removeMember(Request $request, int $groupId, int $userId): JsonResponse
    {
        $business = $request->attributes->get('current_business');
        $group = ThriftGroup::query()->where('business_id', $business->id)->whereKey($groupId)->firstOrFail();

        $result = DB::transaction(function () use ($request, $business, $group, $userId) {
            $membership = ThriftGroupMembership::query()->where('thrift_group_id', $group->id)
                ->where('user_id', $userId)->lockForUpdate()->first();
            if (! $membership || $membership->status !== 'active') return 'missing';
            if ($membership->role === 'organizer' || $membership->user_id === $group->created_by_user_id) return 'protected';
            $membership->forceFill(['status' => 'inactive'])->save();
            BusinessAuditEvent::query()->create([
                'business_id' => $business->id, 'actor_user_id' => $request->user()->id,
                'action' => 'thrift_group.member_removed', 'subject_type' => 'thrift_group_membership', 'subject_id' => $membership->id,
                'metadata' => ['group_id' => $group->id, 'member_user_id' => $membership->user_id, 'role' => $membership->role],
                'ip_address' => $request->ip(), 'user_agent' => Str::limit((string) $request->userAgent(), 500, ''), 'created_at' => now(),
            ]);
            return 'removed';
        });

        if ($result === 'missing') return response()->json(['message' => 'Active group membership not found.'], 404);
        if ($result === 'protected') return response()->json(['message' => 'The group organizer cannot be removed through this endpoint.'], 403);
        return response()->json(['message' => 'Group membership deactivated.']);
    }

    public function store(Request $request): JsonResponse
    {
        $business = $request->attributes->get('current_business');
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'currency_code' => ['required', 'string', 'size:3', Rule::in(['NGN', 'GHS'])],
        ]);

        $group = DB::transaction(function () use ($request, $business, $data): ThriftGroup {
            $group = ThriftGroup::query()->create([
                'business_id' => $business->id, 'name' => trim($data['name']),
                'description' => $data['description'] ?? null, 'currency_code' => $data['currency_code'],
                'status' => 'active', 'created_by_user_id' => $request->user()->id,
            ]);
            ThriftGroupMembership::query()->create([
                'thrift_group_id' => $group->id, 'user_id' => $request->user()->id,
                'role' => 'organizer', 'status' => 'active', 'added_by_user_id' => $request->user()->id, 'joined_at' => now(),
            ]);
            BusinessAuditEvent::query()->create([
                'business_id' => $business->id, 'actor_user_id' => $request->user()->id,
                'action' => 'thrift_group.created', 'subject_type' => 'thrift_group', 'subject_id' => $group->id,
                'metadata' => ['name' => $group->name, 'currency_code' => $group->currency_code],
                'ip_address' => $request->ip(), 'user_agent' => Str::limit((string) $request->userAgent(), 500, ''), 'created_at' => now(),
            ]);
            return $group;
        });

        return response()->json(['data' => $group, 'message' => 'Thrift group created.'], 201);
    }
}
