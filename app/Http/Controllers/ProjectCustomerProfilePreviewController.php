<?php

namespace App\Http\Controllers;

use App\Actions\SyncProjectCustomerProfile;
use App\Http\Requests\PreviewProjectCustomerProfileRequest;
use Illuminate\Http\JsonResponse;

class ProjectCustomerProfilePreviewController extends Controller
{
    public function __invoke(
        PreviewProjectCustomerProfileRequest $request,
        SyncProjectCustomerProfile $customerProfile,
    ): JsonResponse {
        return response()->json($customerProfile->preview($request->profile()));
    }
}
