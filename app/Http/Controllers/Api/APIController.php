<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Farm;
use App\Models\Flock;
use App\Services\FarmEntitlementService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class APIController extends Controller
{
    //
    public function sendResponse($data ,  string $message, int $code = 200) : JsonResponse
    {
        $response = [
            'success' => true,
            'data' => $data,
            'message'=> $message,
        ];
        return response()->json($response , $code);
    }
    public function sendError(string $error ,array $errorMessages = [] ,  int $code = 404)
    {
        $response = [
            'success' => false,
            'message' => $error,
        ];
        if(!empty($errorMessages)){
            $response['errors'] = $errorMessages;
        }
        return response()->json($response , $code);

    }

    public function sendValidationError(string $error, array $errorMessages = []): JsonResponse
    {
        return $this->sendError($error, $errorMessages, 422);
    }

    public function sendUnauthorizedError(string $error = 'Unauthorized'): JsonResponse
    {
        return $this->sendError($error, [], 403);
    }

     public function sendNotFoundError(string $error = 'Resource not found'): JsonResponse
    {
        return $this->sendError($error, [], 404);
    }

    /**
     * Guard a subscription-limited action. Returns null when the farm's plan
     * allows it, otherwise the error response to send back.
     *
     * @param  string  $action  One of the FarmEntitlementService::ACTION_* values.
     */
    protected function ensureEntitled(Farm $farm, string $action): ?JsonResponse
    {
        $denial = app(FarmEntitlementService::class)->denialFor($farm, $action);

        if ($denial === null) {
            return null;
        }

        return response()->json([
            'success' => false,
            'message' => $denial['message'],
            'code' => $denial['code'],
            'upgrade_url' => config('subscription.billing_url'),
        ], $denial['status']);
    }

    protected function ensureFlockIsActive(Flock $flock): ?JsonResponse
    {
        if (! $flock->isActive()) {
            return $this->sendError('This batch has ended. No further updates are allowed.', [], 403);
        }

        return null;
    }

    /**
     * @return array{0: ?Flock, 1: ?JsonResponse}
     */
    protected function activeFlockForFarm(int $flockId, int $farmId): array
    {
        $flock = Flock::where('id', $flockId)->where('farm_id', $farmId)->first();

        if (! $flock) {
            return [null, $this->sendError('Flock not found in this farm', [], 404)];
        }

        if ($response = $this->ensureFlockIsActive($flock)) {
            return [null, $response];
        }

        return [$flock, null];
    }
}
