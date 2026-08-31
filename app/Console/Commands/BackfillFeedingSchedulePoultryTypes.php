<?php

namespace App\Console\Commands;

use App\Models\FeedingSchedule;
use App\Models\PoultryType;
use Illuminate\Console\Command;

class BackfillFeedingSchedulePoultryTypes extends Command
{
    protected $signature = 'feeding-schedules:backfill-poultry-types';

    protected $description = 'Set poultry_type_id on feeding schedules that are missing it (using title match)';

    public function handle(): int
    {
        $types = PoultryType::query()->get(['id', 'name']);
        if ($types->isEmpty()) {
            $this->warn('No poultry types found.');
            return self::SUCCESS;
        }

        $updated = 0;
        $schedules = FeedingSchedule::query()->whereNull('poultry_type_id')->get();

        foreach ($schedules as $schedule) {
            $title = strtolower((string) $schedule->title);
            $matched = $types->first(function (PoultryType $type) use ($title) {
                $name = strtolower($type->name);
                return $name !== '' && str_contains($title, $name);
            });

            if (!$matched) {
                continue;
            }

            $schedule->update(['poultry_type_id' => $matched->id]);
            $updated++;
            $this->line("Schedule #{$schedule->id} \"{$schedule->title}\" → {$matched->name}");
        }

        $this->info("Updated {$updated} feeding schedule(s).");

        return self::SUCCESS;
    }
}
