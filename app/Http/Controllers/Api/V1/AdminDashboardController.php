<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminDashboardController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        return response()->json([
            'data' => [
                'administrator' => $request->user()->only(['id', 'name', 'email']),
                'status' => 'authorized_preview',
                'message' => 'Authorization is enforced. Administrative metrics and actions remain unavailable until their audited domain workflows are implemented.',
            ],
        ]);
    }
}
