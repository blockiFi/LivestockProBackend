<?php

namespace App\Console\Commands;

use App\Services\ExpenditureClassificationService;
use Illuminate\Console\Command;

class ReclassifyFlockExpenditures extends Command
{
    protected $signature = 'expenditures:reclassify
                            {--dry-run : Preview changes without saving}
                            {--farm= : Limit to a single farm ID}';

    protected $description = 'Reclassify legacy flock expenditures into the expanded category set and enrich auto-generated descriptions';

    public function handle(ExpenditureClassificationService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $farmId = $this->option('farm') !== null ? (int) $this->option('farm') : null;

        if ($dryRun) {
            $this->warn('Dry run — no database changes will be saved.');
        }

        $stats = $service->reclassifyAll($dryRun, $farmId);

        foreach ($stats['changes'] as $change) {
            $parts = ["#{$change['id']}"];

            if ($change['category_changed']) {
                $parts[] = "{$change['from_category']} → {$change['to_category']}";
            }

            if ($change['description_changed']) {
                $parts[] = 'description updated';
            }

            $this->line(implode(' | ', $parts));
        }

        $this->newLine();
        $this->info("Scanned: {$stats['scanned']}");
        $this->info("Categories updated: {$stats['category_updated']}");
        $this->info("Descriptions updated: {$stats['description_updated']}");
        $this->info("Unchanged: {$stats['unchanged']}");

        return self::SUCCESS;
    }
}
