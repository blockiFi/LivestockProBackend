<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\FarmSubscription;
use App\Services\Admin\AdminRevenueService;
use App\Support\CsvExport;
use App\Traits\LogsAdminAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminRevenueController extends ApiController
{
    use LogsAdminAction;

    public function __construct(private readonly AdminRevenueService $revenue)
    {
    }

    public function overview(Request $request): JsonResponse
    {
        return $this->sendResponse($this->revenue->overview($request->integer('months', 12)), 'Revenue overview retrieved');
    }

    public function subscriptions(Request $request): JsonResponse
    {
        return $this->sendResponse($this->revenue->subscriptions($request), 'Subscriptions retrieved');
    }

    public function export(Request $request): StreamedResponse
    {
        $this->logAdminAction($request, 'subscription.export', 'subscription', null, null, $request->only(['status', 'plan_slug', 'ending']));

        return CsvExport::stream($this->revenue->subscriptionsQuery($request), [
            'Farm ID' => fn (FarmSubscription $s) => $s->farm_id,
            'Farm' => fn (FarmSubscription $s) => $s->farm?->name,
            'Owner email' => fn (FarmSubscription $s) => $s->farm?->owner?->email,
            'Plan' => fn (FarmSubscription $s) => $s->plan?->name,
            'Price' => fn (FarmSubscription $s) => $s->plan ? $s->plan->price_kobo / 100 : '',
            'Status' => fn (FarmSubscription $s) => $s->status,
            'Trial ends' => fn (FarmSubscription $s) => $s->trial_ends_at,
            'Period ends' => fn (FarmSubscription $s) => $s->current_period_end,
            'Waived until' => fn (FarmSubscription $s) => $s->waived_until,
            'Started' => fn (FarmSubscription $s) => $s->created_at,
        ], 'subscriptions-' . now()->format('Y-m-d') . '.csv');
    }
}
