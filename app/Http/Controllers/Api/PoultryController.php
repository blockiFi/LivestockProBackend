<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\Flock;
use App\Models\FlockDailyRecord;
use App\Models\PoultryMortalityReport;
use App\Models\PoultryFeedUsage;
use App\Models\PoultryType;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class PoultryController extends ApiController
{
    
    public function getStatistics(Request $request, $dateParams = null)
    {
        $user = $request->user();
        $farms = $user->farms;
        
        if ($farms->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'User is not associated with any farm'
            ], 400);
        }
        
        $farmId = $request->route('farm');
        $hasFarm = false;
        foreach ($farms as $farm) {
            if ((int) $farm->id === (int) $farmId) {
                $hasFarm = true;
                break;
            }
        }
        if(!$hasFarm){
            return $this->sendError('User does not belong to this farm', [], 403);
        }

        // Check if user has permission to view statistics
        if (!auth()->user()->can('view statistics', 'api', $farmId)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to view poultry statistics'
            ], 403);
        }

        try {
            // Get date range from URL path parameters or request parameters (default to last 360 days)
            $endDate = null;
            $startDate = null;
            
            // First try to get dates from URL path parameters
            if ($dateParams) {
                // Parse the dateParams string to extract start_date and end_date
                $params = [];
                parse_str(str_replace('/', '&', $dateParams), $params);
                
                if (isset($params['start_date']) && isset($params['end_date'])) {
                    $startDate = Carbon::parse(urldecode($params['start_date']));
                    $endDate = Carbon::parse(urldecode($params['end_date']));
                }
            }
            
            // If not found in path, fallback to request parameters
            if (!$startDate || !$endDate) {
                $endDate = $request->get('end_date', Carbon::now()->toDateString());
                $startDate = $request->get('start_date', Carbon::now()->subDays(360)->toDateString());
                
                $endDate = Carbon::parse($endDate);
                $startDate = Carbon::parse($startDate);
            }

            // Validate date range
            if ($startDate->gt($endDate)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Start date cannot be after end date'
                ], 400);
            }

            // Flock inventory counts should reflect the whole farm (not the report date window).
            $allFlocks = Flock::where('farm_id', $farmId)
                ->with(['poultryType', 'flockStage'])
                ->get();

            $activeFlocks = $allFlocks->where('status', 'active');
            $totalBirds = $activeFlocks->sum(function ($flock) {
                return (int) ($flock->actual_quantity ?? $flock->quantity ?? 0);
            });
            $activeBirds = $totalBirds;

            // Get poultry types and their counts
            $poultryTypeStats = $this->getPoultryTypeStatistics($farmId, $allFlocks);

            // Calculate feed consumption statistics
            $feedStats = $this->getFeedConsumptionStatistics($farmId, $startDate, $endDate);

            // Calculate mortality statistics
            $mortalityStats = $this->getMortalityStatistics($farmId, $startDate, $endDate);

            // Calculate egg production statistics (for layers)
            $eggStats = $this->getEggProductionStatistics($farmId, $startDate, $endDate);

            // Calculate weight statistics
            $weightStats = $this->getWeightStatistics($farmId, $startDate, $endDate);

            // Calculate flock performance metrics
            $performanceStats = $this->getPerformanceStatistics($farmId, $allFlocks);

            // Calculate financial metrics
            $financialStats = $this->getFinancialStatistics($farmId, $startDate, $endDate);

            $statistics = [
                'summary' => [
                    'total_birds' => $totalBirds,
                    'total_flocks' => $allFlocks->count(),
                    'active_birds' => $activeBirds,
                    'active_flocks' => $activeFlocks->count(),
                    'completed_flocks' => $allFlocks->where('status', 'completed')->count(),
                    'sold_flocks' => $allFlocks->where('status', 'sold')->count(),
                    'culled_flocks' => $allFlocks->where('status', 'culled')->count(),
                    'date_range' => [
                        'start_date' => $startDate->toDateString(),
                        'end_date' => $endDate->toDateString(),
                        'period_days' => $startDate->diffInDays($endDate) + 1
                    ]
                ],
                'poultry_types' => $poultryTypeStats,
                'feed_consumption' => $feedStats,
                'mortality' => $mortalityStats,
                'egg_production' => $eggStats,
                'weight_metrics' => $weightStats,
                'performance' => $performanceStats,
                'financial' => $financialStats,
                'flock_details' => $allFlocks->map(function ($flock) {
                    return [
                        'id' => $flock->id,
                        'name' => $flock->name,
                        'batch_number' => $flock->batch_number,
                        'poultry_type' => $flock->poultryType->name ?? 'Unknown',
                        'quantity' => $flock->quantity,
                        'actual_quantity' => $flock->actual_quantity,
                        'arrival_date' => $flock->arrival_date,
                        'age_days' => $flock->arrival_date ? Carbon::parse($flock->arrival_date)->diffInDays(now()) : 0,
                        'status' => $flock->status,
                        'stage' => $flock->flockStage->name ?? 'Unknown'
                    ];
                })->values()
            ];

            return response()->json([
                'success' => true,
                'data' => $statistics
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving poultry statistics: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get statistics by poultry type
     */
    private function getPoultryTypeStatistics(int $farmId, $flocks): array
    {
        $typeStats = [];
        
        // Get all poultry types for the farm from flocks
        $poultryTypes = PoultryType::all();
        
        foreach ($poultryTypes as $type) {
            $typeFlocks = $flocks->where('poultry_type_id', $type->id);
            $totalBirds = $typeFlocks->sum('quantity');
            
            $typeStats[] = [
                'type_id' => $type->id,
                'type_name' => $type->name,
                'total_birds' => $totalBirds,
                'flock_count' => $typeFlocks->count(),
                'percentage_of_total' => $totalBirds > 0 ? round(($totalBirds / $flocks->sum('quantity')) * 100, 2) : 0
            ];
        }

        return $typeStats;
    }

    /**
     * Get feed consumption statistics
     */
    private function getFeedConsumptionStatistics(int $farmId, Carbon $startDate, Carbon $endDate): array
    {
        // Get daily feed consumption from FlockDailyRecord
        $dailyFeedConsumption = FlockDailyRecord::where('farm_id', $farmId)
            ->whereBetween('date', [$startDate, $endDate])
            ->select(
                DB::raw('DATE(date) as date'),
                DB::raw('SUM(feed_consumed_kg) as total_feed'),
                DB::raw('COUNT(DISTINCT flock_id) as flocks_count')
            )
            ->groupBy('date')
            ->get();

        // Get feed usage from PoultryFeedUsage
        $feedUsage = PoultryFeedUsage::where('farm_id', $farmId)
            ->whereBetween('usage_date', [$startDate, $endDate])
            ->with(['feedType', 'flock'])
            ->get();

        $totalFeedUsed = $feedUsage->sum('quantity');
        $totalFeedCost = $feedUsage->sum(function ($usage) {
            return $usage->quantity * $usage->unit_cost;
        });

        $averageDailyFeed = $dailyFeedConsumption->avg('total_feed') ?? 0;

        return [
            'total_feed_consumed_kg' => round($totalFeedUsed, 2),
            'total_feed_cost' => round($totalFeedCost, 2),
            'average_daily_feed_kg' => round($averageDailyFeed, 2),
            'average_daily_feed_per_bird_kg' => $this->calculateAverageFeedPerBird($farmId, $startDate, $endDate),
            'feed_conversion_ratio' => $this->calculateFeedConversionRatio($farmId, $startDate, $endDate),
            'daily_breakdown' => $dailyFeedConsumption->map(function ($record) {
                return [
                    'date' => $record->date,
                    'total_feed_kg' => round($record->total_feed, 2),
                    'flocks_count' => $record->flocks_count
                ];
            })
        ];
    }

    /**
     * Get mortality statistics
     */
    private function getMortalityStatistics(int $farmId, Carbon $startDate, Carbon $endDate): array
    {
        // Get daily mortality from FlockDailyRecord
        $dailyMortality = FlockDailyRecord::where('farm_id', $farmId)
            ->whereBetween('date', [$startDate, $endDate])
            ->select(
                DB::raw('DATE(date) as date'),
                DB::raw('SUM(mortality) as total_mortality'),
                DB::raw('COUNT(DISTINCT flock_id) as flocks_count')
            )
            ->groupBy('date')
            ->get();

        // Get mortality reports
        $mortalityReports = PoultryMortalityReport::where('farm_id', $farmId)
            ->whereBetween('date', [$startDate, $endDate])
            ->get();

        $totalMortality = $dailyMortality->sum('total_mortality');
        
        // Calculate total birds from active flocks
        $totalBirds = Flock::where('farm_id', $farmId)
            ->where('status', 'active')
            ->sum('quantity');
        
        $averageDailyMortality = $dailyMortality->avg('total_mortality') ?? 0;
        $averageMortalityRate = $totalBirds > 0 ? ($totalMortality / $totalBirds) * 100 : 0;

        return [
            'total_mortality' => $totalMortality,
            'average_daily_mortality' => round($averageDailyMortality, 2),
            'average_mortality_rate_percent' => round($averageMortalityRate, 2),
            'mortality_reports_count' => $mortalityReports->count(),
            'daily_breakdown' => $dailyMortality->map(function ($record) use ($totalBirds) {
                $mortalityRate = $totalBirds > 0 ? ($record->total_mortality / $totalBirds) * 100 : 0;
                return [
                    'date' => $record->date,
                    'mortality_count' => $record->total_mortality,
                    'total_birds' => $totalBirds,
                    'mortality_rate_percent' => round($mortalityRate, 2),
                    'flocks_count' => $record->flocks_count
                ];
            })
        ];
    }

    /**
     * Get egg production statistics
     */
    private function getEggProductionStatistics(int $farmId, Carbon $startDate, Carbon $endDate): array
    {
        $dailyEggProduction = FlockDailyRecord::where('farm_id', $farmId)
            ->whereBetween('date', [$startDate, $endDate])
            ->whereNotNull('eggs_collected')
            ->select(
                DB::raw('DATE(date) as date'),
                DB::raw('SUM(eggs_collected) as total_eggs'),
                DB::raw('COUNT(DISTINCT flock_id) as flocks_count')
            )
            ->groupBy('date')
            ->get();

        $totalEggs = $dailyEggProduction->sum('total_eggs');
        $averageDailyEggs = $dailyEggProduction->avg('total_eggs') ?? 0;

        return [
            'total_eggs_produced' => round($totalEggs, 0),
            'average_daily_eggs' => round($averageDailyEggs, 2),
            'daily_breakdown' => $dailyEggProduction->map(function ($record) {
                return [
                    'date' => $record->date,
                    'eggs_produced' => round($record->total_eggs, 0),
                    'flocks_count' => $record->flocks_count
                ];
            })
        ];
    }

    /**
     * Get weight statistics
     */
    private function getWeightStatistics(int $farmId, Carbon $startDate, Carbon $endDate): array
    {
        $weightData = FlockDailyRecord::where('farm_id', $farmId)
            ->whereBetween('date', [$startDate, $endDate])
            ->whereNotNull('avg_weight_grams')
            ->select(
                DB::raw('flock_id'),
                DB::raw('MAX(avg_weight_grams) as max_weight'),
                DB::raw('MIN(avg_weight_grams) as min_weight'),
                DB::raw('AVG(avg_weight_grams) as avg_weight')
            )
            ->groupBy('flock_id')
            ->get();

        return [
            'average_weight_grams' => round($weightData->avg('avg_weight'), 2),
            'max_weight_grams' => round($weightData->max('max_weight'), 2),
            'min_weight_grams' => round($weightData->min('min_weight'), 2),
            'weight_gain_grams' => round($weightData->sum('max_weight') - $weightData->sum('min_weight'), 2)
        ];
    }

    /**
     * Get performance statistics
     */
    private function getPerformanceStatistics(int $farmId, $flocks): array
    {
        $activeFlocks = $flocks->where('status', 'active');
        
        return [
            'flock_performance' => [
                'total_flocks' => $flocks->count(),
                'active_flocks' => $activeFlocks->count(),
                'completed_flocks' => $flocks->where('status', 'completed')->count(),
                'average_flock_size' => round($flocks->avg('quantity'), 0),
                'average_flock_age_days' => round($activeFlocks->avg(function ($flock) {
                    return $flock->arrival_date ? Carbon::parse($flock->arrival_date)->diffInDays(now()) : 0;
                }), 0)
            ]
        ];
    }

    /**
     * Get financial statistics
     */
    private function getFinancialStatistics(int $farmId, Carbon $startDate, Carbon $endDate): array
    {
        $feedCost = PoultryFeedUsage::where('farm_id', $farmId)
            ->whereBetween('usage_date', [$startDate, $endDate])
            ->sum(DB::raw('quantity * unit_cost'));

        return [
            'total_feed_cost' => round($feedCost, 2),
            'average_daily_feed_cost' => round($feedCost / ($startDate->diffInDays($endDate) + 1), 2),
            'cost_per_bird' => $this->calculateCostPerBird($farmId, $startDate, $endDate)
        ];
    }

    /**
     * Calculate average feed consumption per bird
     */
    private function calculateAverageFeedPerBird(int $farmId, Carbon $startDate, Carbon $endDate): float
    {
        $totalFeed = FlockDailyRecord::where('farm_id', $farmId)
            ->whereBetween('date', [$startDate, $endDate])
            ->sum('feed_consumed_kg');

        $totalBirds = Flock::where('farm_id', $farmId)
            ->where('status', 'active')
            ->sum('quantity');

        return $totalBirds > 0 ? round($totalFeed / $totalBirds, 4) : 0;
    }

    /**
     * Calculate feed conversion ratio
     */
    private function calculateFeedConversionRatio(int $farmId, Carbon $startDate, Carbon $endDate): float
    {
        $totalFeed = FlockDailyRecord::where('farm_id', $farmId)
            ->whereBetween('date', [$startDate, $endDate])
            ->sum('feed_consumed_kg');

        $weightGain = FlockDailyRecord::where('farm_id', $farmId)
            ->whereBetween('date', [$startDate, $endDate])
            ->select(
                DB::raw('MAX(avg_weight_grams) - MIN(avg_weight_grams) as weight_gain')
            )
            ->first();

        $weightGainValue = $weightGain ? $weightGain->weight_gain : 0;

        return $weightGainValue > 0 ? round($totalFeed / ($weightGainValue / 1000), 2) : 0; // Convert grams to kg
    }

    /**
     * Calculate cost per bird
     */
    private function calculateCostPerBird(int $farmId, Carbon $startDate, Carbon $endDate): float
    {
        $totalCost = PoultryFeedUsage::where('farm_id', $farmId)
            ->whereBetween('usage_date', [$startDate, $endDate])
            ->sum(DB::raw('quantity * unit_cost'));

        $totalBirds = Flock::where('farm_id', $farmId)
            ->where('status', 'active')
            ->sum('quantity');

        return $totalBirds > 0 ? round($totalCost / $totalBirds, 2) : 0;
    }
}
