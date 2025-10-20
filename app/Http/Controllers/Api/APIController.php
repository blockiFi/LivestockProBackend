<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
}
