<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\BusinessMembership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class BusinessController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $businesses = BusinessMembership::query()
            ->with('business:id,name,slug,country_code,status,created_at')
            ->where('user_id', $request->user()->id)
            ->where('status', 'active')
            ->get()
            ->pluck('business');

        return response()->json(['data' => $businesses]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'country_code' => ['required', 'string', 'size:2', Rule::exists('countries', 'code')->where('is_enabled', true)],
        ]);

        $slugBase = Str::slug($data['name']);
        if ($slugBase === '') {
            $slugBase = 'business';
        }

        $slug = $slugBase.'-'.Str::lower(Str::random(8));

        $business = Business::query()->create([
            'name' => trim($data['name']),
            'slug' => $slug,
            'country_code' => Str::upper($data['country_code']),
            'status' => 'pending_review',
            'created_by_user_id' => $request->user()->id,
        ]);

        BusinessMembership::query()->create([
            'business_id' => $business->id,
            'user_id' => $request->user()->id,
            'role' => 'business_owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return response()->json(['data' => $business, 'message' => 'Business created and is pending review.'], 201);
    }

    public function current(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $request->attributes->get('current_business'),
            'membership' => $request->attributes->get('business_membership'),
        ]);
    }
}
