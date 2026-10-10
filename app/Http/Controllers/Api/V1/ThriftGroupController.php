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
