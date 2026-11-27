<?php

namespace App\Services\Admin;

use App\Models\AdminAuditLog;
use App\Models\Farm;
use App\Models\FarmSubscription;
use App\Models\ScheduleImport;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The extra data the rebuilt admin dashboard shows: period-over-period
 * trends, daily series, what needs attention, and recent activity.
 */
class AdminInsightsService
{
    public function __construct(private readonly AdminInventoryAlertService $inventoryAlerts)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboard(int $days = 30): array
    {
        $days = max(7, min(365, $days));
        $end = Carbon::now()->endOfDay();
        $start = $end->copy()->subDays($days - 1)->startOfDay();
        $prevEnd = $start->copy()->subSecond();
        $prevStart = $prevEnd->copy()->subDays($days - 1)->startOfDay();

        $farmsNow = Farm::whereBetween('created_at', [$start, $end])->count();
        $farmsPrev = Farm::whereBetween('created_at', [$prevStart, $prevEnd])->count();
        $usersNow = User::whereBetween('created_at', [$start, $end])->count();
        $usersPrev = User::whereBetween('created_at', [$prevStart, $prevEnd])->count();
        $activeNow = User::whereBetween('last_login_at', [$start, $end])->count();
        $activePrev = User::whereBetween('last_login_at', [$prevStart, $prevEnd])->count();
        $revenueNow = $this->revenueKobo($start, $end);
        $revenuePrev = $this->revenueKobo($prevStart, $prevEnd);

        return [
            'period' => ['days' => $days, 'from' => $start->toDateString(), 'to' => $end->toDateString()],
            'trends' => [
                'new_farms' => $this->trend($farmsNow, $farmsPrev),
                'new_users' => $this->trend($usersNow, $usersPrev),
                'active_users' => $this->trend($activeNow, $activePrev),
                'revenue' => $this->trend($revenueNow / 100, $revenuePrev / 100),
            ],
            'series' => $this->series($start, $end),
            'attention' => $this->attention(),
            'activity' => $this->recentActivity(12),
        ];
    }

    /** @return array{current: float|int, previous: float|int, change_pct: ?float} */
    private function trend(float|int $current, float|int $previous): array
    {
        return [
            'current' => $current,
            'previous' => $previous,
            'change_pct' => $previous > 0 ? round((($current - $previous) / $previous) * 100, 1) : null,
        ];
    }

    private function revenueKobo(Carbon $from, Carbon $to): int
    {
        return (int) SubscriptionTransaction::where('event', 'charge.success')
            ->whereIn('status', ['success', 'successful'])
            ->whereBetween('created_at', [$from, $to])
            ->sum('amount_kobo');
    }

    /**
     * Daily new farms, new users and revenue, with every day present (zeros
     * filled) so charts do not skip quiet days.
     *
     * @return list<array{date: string, farms: int, users: int, revenue: float}>
     */
    public function series(Carbon $start, Carbon $end): array
    {
        $date = DB::connection()->getDriverName() === 'sqlite' ? 'date(created_at)' : 'DATE(created_at)';

        $farms = Farm::whereBetween('created_at', [$start, $end])
            ->selectRaw("{$date} as d, count(*) as c")->groupBy('d')->pluck('c', 'd');
        $users = User::whereBetween('created_at', [$start, $end])
            ->selectRaw("{$date} as d, count(*) as c")->groupBy('d')->pluck('c', 'd');
        $revenue = SubscriptionTransaction::where('event', 'charge.success')
            ->whereIn('status', ['success', 'successful'])
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw("{$date} as d, sum(amount_kobo) as c")->groupBy('d')->pluck('c', 'd');

        $rows = [];
        foreach (CarbonPeriod::create($start->copy()->startOfDay(), '1 day', $end->copy()->startOfDay()) as $day) {
            $key = $day->toDateString();
            $rows[] = [
                'date' => $key,
                'farms' => (int) ($farms[$key] ?? 0),
                'users' => (int) ($users[$key] ?? 0),
                'revenue' => ((int) ($revenue[$key] ?? 0)) / 100,
            ];
        }

        return $rows;
    }

    /**
     * Things an admin should look at, most serious first. Each item links to
     * the page that deals with it.
     *
     * @return list<array{key: string, severity: string, title: string, count: int, link: string}>
     */
    public function attention(): array
    {
        $items = [];
        $failedJobs = DB::getSchemaBuilder()->hasTable('failed_jobs') ? (int) DB::table('failed_jobs')->count() : 0;
        $pastDue = FarmSubscription::whereIn('status', [FarmSubscription::STATUS_PAST_DUE, FarmSubscription::STATUS_GRACE])->count();
        $readOnly = FarmSubscription::where('status', FarmSubscription::STATUS_READ_ONLY)->count();
        $trialsEnding = FarmSubscription::where('status', FarmSubscription::STATUS_TRIALING)
            ->whereBetween('trial_ends_at', [now(), now()->addDays(7)])->count();
        $failedImports = ScheduleImport::where('status', 'failed')->where('created_at', '>=', now()->subDays(7))->count();
        $suspended = Farm::where('status', false)->count();
        // The stock check scans every farm's inventory, so it is cached briefly.
        $stockAlerts = (int) Cache::remember('admin.attention.stock', 300, fn () => $this->inventoryAlerts->summary()['total'] ?? 0);

        $add = function (string $key, string $severity, string $title, int $count, string $link) use (&$items) {
            if ($count > 0) {
                $items[] = compact('key', 'severity', 'title', 'count', 'link');
            }
        };

        $add('failed_jobs', 'critical', 'Background jobs failed', $failedJobs, '/system?tab=jobs');
        $add('past_due', 'warning', 'Farms with a failed payment', $pastDue, '/revenue?status=past_due');
        $add('read_only', 'warning', 'Farms locked to read-only', $readOnly, '/revenue?status=read_only');
        $add('trials_ending', 'info', 'Trials ending in the next 7 days', $trialsEnding, '/revenue?status=trialing&ending=7');
        $add('failed_imports', 'warning', 'AI imports failed this week', $failedImports, '/data/ai-imports');
        $add('stock_alerts', 'warning', 'Low stock or expiring vaccines', $stockAlerts, '/inventory-alerts');
        $add('suspended', 'info', 'Suspended farms', $suspended, '/farms?status=suspended');

        return $items;
    }

    public function recentActivity(int $limit = 12): array
    {
        $audit = AdminAuditLog::with('adminUser:id,name,email')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (AdminAuditLog $log) => [
                'type' => 'admin',
                'at' => $log->created_at?->toIso8601String(),
                'actor' => $log->adminUser?->name,
                'action' => $log->action,
                'resource_type' => $log->resource_type,
                'resource_id' => $log->resource_id,
            ]);

        $farms = Farm::with('owner:id,name')->latest()->limit($limit)->get(['id', 'name', 'created_by', 'created_at'])
            ->map(fn (Farm $f) => [
                'type' => 'farm_created',
                'at' => $f->created_at?->toIso8601String(),
                'actor' => $f->owner?->name,
                'action' => 'farm.created',
                'resource_type' => 'farm',
                'resource_id' => $f->id,
                'label' => $f->name,
            ]);

        $users = User::latest()->limit($limit)->get(['id', 'name', 'created_at'])
            ->map(fn (User $u) => [
                'type' => 'user_joined',
                'at' => $u->created_at?->toIso8601String(),
                'actor' => $u->name,
                'action' => 'user.joined',
                'resource_type' => 'user',
                'resource_id' => $u->id,
            ]);

        return $audit->concat($farms)->concat($users)
            ->sortByDesc('at')
            ->take($limit)
            ->values()
            ->all();
    }
}
