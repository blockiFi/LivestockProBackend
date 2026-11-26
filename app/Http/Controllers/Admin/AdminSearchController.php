<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\Farm;
use App\Models\Flock;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Global search for the admin command palette. */
class AdminSearchController extends ApiController
{
    public function __invoke(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('q'));

        if (mb_strlen($q) < 2) {
            return $this->sendResponse(['farms' => [], 'users' => [], 'flocks' => []], 'Type at least 2 characters');
        }

        $like = "%{$q}%";
        $id = ctype_digit($q) ? (int) $q : null;

        $farms = Farm::withTrashed()
            ->with('owner:id,name,email')
            ->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('email', 'like', $like)
                ->when($id, fn ($x) => $x->orWhere('id', $id)))
            ->orderByRaw('CASE WHEN name LIKE ? THEN 0 ELSE 1 END', ["{$q}%"])
            ->limit(6)
            ->get(['id', 'name', 'email', 'status', 'created_by', 'deleted_at']);

        $users = User::where(fn ($w) => $w->where('name', 'like', $like)->orWhere('email', 'like', $like)
                ->when($id, fn ($x) => $x->orWhere('id', $id)))
            ->orderByRaw('CASE WHEN email LIKE ? OR name LIKE ? THEN 0 ELSE 1 END', ["{$q}%", "{$q}%"])
            ->limit(6)
            ->get(['id', 'name', 'email', 'is_platform_admin', 'platform_admin_role', 'deactivated_at']);

        $flocks = Flock::with('farm:id,name')
            ->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('batch_number', 'like', $like))
            ->limit(5)
            ->get(['id', 'name', 'batch_number', 'farm_id', 'status']);

        return $this->sendResponse(compact('farms', 'users', 'flocks'), 'Search results');
    }
}
