<?php

namespace App\Services\Admin;

use App\Models\FarmSubscription;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionTransaction;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Subscriptions across all farms and the money they bring in. */
class AdminRevenueService
{
    public function subscriptionsQuery(Request $request): Builder
    {
        $query = FarmSubscription::query()
            ->with(['farm' => fn ($q) => $q->withTrashed()->select('id', 'name', 'created_by', 'status', 'deleted_at'), 'farm.owner:id,name,email', 'plan:id,slug,name,price_kobo,currency'])
            ->whereHas('farm', fn ($q) => $q->withTrashed());

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('plan_slug')) {
            $query->whereHas('plan', fn ($q) => $q->where('slug', $request->plan_slug));
        }
        if ($request->filled('ending')) {
            $days = max(1, min(90, $request->integer('ending')));
            $query->where(function ($q) use ($days) {
                $q->whereBetween('trial_ends_at', [now(), now()->addDays($days)])
                    ->orWhereBetween('current_period_end', [now(), now()->addDays($days)])
                    ->orWhereBetween('waived_until', [now(), now()->addDays($days)]);
            });
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('farm', fn ($q) => $q->withTrashed()->where('name', 'like', "%{$search}%"));
        }

        $sort = in_array($request->input('sort'), ['created_at', 'trial_ends_at', 'current_period_end', 'status'], true)
            ? $request->input('sort') : 'created_at';

        return $query->orderBy($sort, $request->input('direction') === 'asc' ? 'asc' : 'desc')->orderBy('id');
    }

    public function subscriptions(Request $request): LengthAwarePaginator
    {
        return $this->subscriptionsQuery($request)->paginate(min(100, max(5, $request->integer('per_page', 25))));
    }

    /**
     * Revenue by month, plan mix, and payment events for the revenue page.
     */
    public function overview(int $months = 12): array
    {
        $months = max(3, min(36, $months));
        $start = Carbon::now()->startOfMonth()->subMonths($months - 1);
        $monthExpr = DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', created_at)"
            : "DATE_FORMAT(created_at, '%Y-%m')";

        $paid = SubscriptionTransaction::where('event', 'charge.success')
            ->whereIn('status', ['success', 'successful'])
            ->where('created_at', '>=', $start)
            ->selectRaw("{$monthExpr} as m, sum(amount_kobo) as kobo, count(*) as payments, count(distinct farm_id) as farms")
            ->groupBy('m')
            ->get()
            ->keyBy('m');

        $failed = SubscriptionTransaction::whereIn('event', ['invoice.payment_failed', 'subscription.not_renew'])
            ->where('created_at', '>=', $start)
            ->selectRaw("{$monthExpr} as m, count(*) as c")
            ->groupBy('m')
            ->pluck('c', 'm');

        $series = [];
        for ($i = 0; $i < $months; $i++) {
            $key = $start->copy()->addMonths($i)->format('Y-m');
            $row = $paid->get($key);
            $series[] = [
                'month' => $key,
                'revenue' => $row ? ((int) $row->kobo) / 100 : 0,
                'payments' => $row ? (int) $row->payments : 0,
                'paying_farms' => $row ? (int) $row->farms : 0,
                'failed_payments' => (int) ($failed[$key] ?? 0),
            ];
        }

        $plans = SubscriptionPlan::orderBy('sort_order')->get(['id', 'slug', 'name', 'price_kobo', 'currency']);
        $counts = FarmSubscription::selectRaw('subscription_plan_id, status, count(*) as c')
            ->groupBy('subscription_plan_id', 'status')->get();

        $planMix = $plans->map(function (SubscriptionPlan $plan) use ($counts) {
            $rows = $counts->where('subscription_plan_id', $plan->id);
            $paying = (int) $rows->where('status', FarmSubscription::STATUS_ACTIVE)->sum('c');

            return [
                'slug' => $plan->slug,
                'name' => $plan->name,
                'price' => $plan->price_kobo / 100,
                'currency' => $plan->currency,
                'paying' => $paying,
                'trialing' => (int) $rows->where('status', FarmSubscription::STATUS_TRIALING)->sum('c'),
                'waived' => (int) $rows->where('status', FarmSubscription::STATUS_WAIVED)->sum('c'),
                'other' => (int) $rows->whereNotIn('status', [FarmSubscription::STATUS_ACTIVE, FarmSubscription::STATUS_TRIALING, FarmSubscription::STATUS_WAIVED])->sum('c'),
                'mrr' => $paying * $plan->price_kobo / 100,
            ];
        })->values();

        $recent = SubscriptionTransaction::with(['farm' => fn ($q) => $q->withTrashed()->select('id', 'name'), 'plan:id,name', 'performer:id,name'])
            ->latest()
            ->limit(15)
            ->get(['id', 'farm_id', 'subscription_plan_id', 'source', 'event', 'amount_kobo', 'currency', 'status', 'reference', 'performed_by', 'created_at']);

        return [
            'kpis' => app(AdminSubscriptionService::class)->kpis(),
            'series' => $series,
            'plan_mix' => $planMix,
            'recent_transactions' => $recent,
            'trials_ending_7d' => FarmSubscription::where('status', FarmSubscription::STATUS_TRIALING)
                ->whereBetween('trial_ends_at', [now(), now()->addDays(7)])->count(),
        ];
    }
}
