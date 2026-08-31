<?php

namespace App\Services\Admin;

use App\Models\Farm;
use App\Models\FarmTaskInstance;
use App\Models\Flock;
use App\Models\ScheduleImport;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AdminAnalyticsService
{
    public function growth(?string $from = null, ?string $to = null): array
    {
        $end = $to ? Carbon::parse($to)->endOfDay() : Carbon::now()->endOfDay();
        $start = $from ? Carbon::parse($from)->startOfDay() : $end->copy()->subDays(30)->startOfDay();

        $userSignups = $this->dailyCounts(User::query(), 'created_at', $start, $end);
        $farmCreations = $this->dailyCounts(Farm::query(), 'created_at', $start, $end);

        return [
            'period' => [
                'from' => $start->toDateString(),
                'to' => $end->toDateString(),
            ],
            'user_signups' => $userSignups,
            'farm_creations' => $farmCreations,
            'totals' => [
                'users' => array_sum(array_column($userSignups, 'count')),
                'farms' => array_sum(array_column($farmCreations, 'count')),
            ],
        ];
    }

    public function usage(?string $from = null, ?string $to = null): array
    {
        $end = $to ? Carbon::parse($to)->endOfDay() : Carbon::now()->endOfDay();
        $start = $from ? Carbon::parse($from)->startOfDay() : $end->copy()->subDays(30)->startOfDay();

        $aiImports = $this->dailyCounts(ScheduleImport::query(), 'created_at', $start, $end);
        $tasks = $this->dailyCounts(
            FarmTaskInstance::query()->where('status', 'completed'),
            'updated_at',
            $start,
            $end
        );
        $flocks = $this->dailyCounts(Flock::query(), 'created_at', $start, $end);

        return [
            'period' => [
                'from' => $start->toDateString(),
                'to' => $end->toDateString(),
            ],
            'ai_imports' => $aiImports,
            'completed_tasks' => $tasks,
            'new_flocks' => $flocks,
            'feature_totals' => [
                'ai_imports' => ScheduleImport::whereBetween('created_at', [$start, $end])->count(),
                'completed_tasks' => FarmTaskInstance::where('status', 'completed')
                    ->whereBetween('updated_at', [$start, $end])->count(),
                'new_flocks' => Flock::whereBetween('created_at', [$start, $end])->count(),
            ],
        ];
    }

    public function health(): array
    {
        $activeFlocks = Flock::where('status', 'active')->count();
        $overdueTasks = FarmTaskInstance::where('status', 'overdue')->count();
        $failedImports = ScheduleImport::where('status', 'failed')->count();
        $suspendedFarms = Farm::where('status', false)->count();

        return [
            'active_flocks' => $activeFlocks,
            'overdue_tasks' => $overdueTasks,
            'failed_ai_imports' => $failedImports,
            'suspended_farms' => $suspendedFarms,
        ];
    }

    private function dailyCounts($query, string $dateColumn, Carbon $start, Carbon $end): array
    {
        $table = $query->getModel()->getTable();
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            $dateExpr = "date({$table}.{$dateColumn})";
        } else {
            $dateExpr = "DATE({$table}.{$dateColumn})";
        }

        $rows = (clone $query)
            ->whereBetween("{$table}.{$dateColumn}", [$start, $end])
            ->selectRaw("{$dateExpr} as date, COUNT(*) as count")
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return $rows->map(fn ($row) => [
            'date' => $row->date,
            'count' => (int) $row->count,
        ])->values()->all();
    }
}
