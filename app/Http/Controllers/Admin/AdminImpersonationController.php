<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\User;
use App\Services\Admin\AdminImpersonationService;
use App\Traits\LogsAdminAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminImpersonationController extends ApiController
{
    use LogsAdminAction;

    public function __construct(private readonly AdminImpersonationService $service)
    {
    }

    public function store(Request $request, User $user): JsonResponse
    {
        if ($user->is_platform_admin) {
            return $this->sendError('Cannot impersonate platform admins', [], 403);
        }

        $result = $this->service->createToken($request->user(), $user);

        $this->logAdminAction($request, 'user.impersonate', 'user', $user->id);

        return $this->sendResponse($result, 'Impersonation token created');
    }

    public function destroy(Request $request): JsonResponse
    {
        $this->service->endImpersonation($request->user());

        return $this->sendResponse(null, 'Impersonation ended');
    }
}
