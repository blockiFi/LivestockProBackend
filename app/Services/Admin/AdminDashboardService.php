<?php

namespace App\Services\Admin;

use App\Models\Farm;
use App\Models\FarmTaskInstance;
use App\Models\Flock;
use App\Models\ScheduleImport;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AdminDashboardService
{
    public function getKpis(): array
    {
        $now = Carbon::now();
        $sevenDaysAgo = $now->copy()->subDays(7);
        $thirtyDaysAgo = $now->copy()->subDays(30);

        $totalFarms = Farm::withTrashed()->count();
        $activeFarms = Farm::where('status', true)->count();
        $suspendedFarms = Farm::where('status', false)->count();

        return [
            'farms' => [
                'total' => $totalFarms,
                'active' => $activeFarms,
                'suspended' => $suspendedFarms,
                'new_7d' => Farm::where('created_at', '>=', $sevenDaysAgo)->count(),
                'new_30d' => Farm::where('created_at', '>=', $thirtyDaysAgo)->count(),
            ],
            'users' => [
                'total' => User::count(),
                'platform_admins' => User::where('is_platform_admin', true)->count(),
                'new_7d' => User::where('created_at', '>=', $sevenDaysAgo)->count(),
                'new_30d' => User::where('created_at', '>=', $thirtyDaysAgo)->count(),
            ],
            'flocks' => [
                'total' => Flock::count(),
                'active' => Flock::where('status', 'active')->count(),
            ],
            'birds' => [
                'total_active' => (int) Flock::where('status', 'active')->sum('quantity'),
            ],
            'ai_imports' => [
                'total' => ScheduleImport::count(),
                'failed' => ScheduleImport::where('status', 'failed')->count(),
                'pending' => ScheduleImport::whereIn('status', ['pending', 'extracting'])->count(),
            ],
            'tasks' => [
                'overdue' => FarmTaskInstance::where('status', 'overdue')->count(),
                'pending' => FarmTaskInstance::where('status', 'pending')->count(),
            ],
            'subscriptions' => app(AdminSubscriptionService::class)->kpis(),
            'system' => [
                'failed_jobs' => $this->failedJobsCount(),
            ],
        ];
    }

    private function failedJobsCount(): int
    {
        if (! DB::getSchemaBuilder()->hasTable('failed_jobs')) {
            return 0;
        }

        return (int) DB::table('failed_jobs')->count();
    }
}
