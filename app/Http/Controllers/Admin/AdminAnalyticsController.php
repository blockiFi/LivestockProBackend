<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Services\Admin\AdminAnalyticsService;
use App\Services\Admin\AdminOperationsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminAnalyticsController extends ApiController
{
    public function __construct(
        private readonly AdminAnalyticsService $service,
        private readonly AdminOperationsService $operations,
    ) {
    }

    public function growth(Request $request): JsonResponse
    {
        return $this->sendResponse(
            $this->service->growth($request->from, $request->to),
            'Growth analytics retrieved'
        );
    }

    public function usage(Request $request): JsonResponse
    {
        return $this->sendResponse(
            $this->service->usage($request->from, $request->to),
            'Usage analytics retrieved'
        );
    }

    public function health(): JsonResponse
    {
        return $this->sendResponse($this->service->health(), 'Health analytics retrieved');
    }

    public function operationsOverview(Request $request): JsonResponse
    {
        return $this->sendResponse(
            $this->operations->overview($request->from, $request->to, $request->integer('farm_id') ?: null),
            'Operations overview retrieved'
        );
    }

    public function operationsSchedules(Request $request): JsonResponse
    {
        return $this->sendResponse(
            $this->operations->schedules($request->from, $request->to, $request->integer('farm_id') ?: null),
            'Schedule analytics retrieved'
        );
    }

    public function operationsFeeds(Request $request): JsonResponse
    {
        return $this->sendResponse(
            $this->operations->feeds($request->from, $request->to, $request->integer('farm_id') ?: null),
            'Feed analytics retrieved'
        );
    }

    public function operationsFinancial(Request $request): JsonResponse
    {
        return $this->sendResponse(
            $this->operations->financial($request->from, $request->to, $request->integer('farm_id') ?: null),
            'Financial analytics retrieved'
        );
    }
}
