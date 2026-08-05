<?php

namespace App\Console\Commands;

use App\Services\CatalogRebuildService;
use Illuminate\Console\Command;

class RebuildCatalogFromStorage extends Command
{
    protected $signature = 'nbx:rebuild-catalog-from-storage
        {--dry-run : Preview what would be created without writing anything}
        {--skip-scan : Reuse the most recent storage_inventory_objects rows instead of scanning first}';

    protected $description = 'Recreate media_assets/media_sources rows for NBX-owned Contabo objects with no matching database row (disaster recovery after a lost database)';

    public function handle(CatalogRebuildService $service): int
    {
        if (! $this->option('skip-scan')) {
            $exitCode = $this->call('nbx:storage-inventory-all-targets', ['--sync' => true]);
            if ($exitCode !== self::SUCCESS) {
                $this->error('Storage scan failed; aborting before touching the catalog. Re-run with --skip-scan to reuse the last completed scan instead.');

                return self::FAILURE;
            }
        }

        $dryRun = (bool) $this->option('dry-run');
        $summary = $service->rebuild($dryRun);

        $this->table(
            ['Job ID', 'Bucket', 'Outcome', 'Media Source ID'],
            collect($summary['groups'])->map(fn (array $group): array => [
                $group['job_id'],
                $group['storage_bucket'] ?? '',
                $group['outcome'],
                $group['media_source_id'] ?? '—',
            ])->all(),
        );

        $this->newLine();
        if ($dryRun) {
            $this->info(sprintf(
                'Dry run: %d job(s) already linked, %d would be created.',
                collect($summary['groups'])->where('outcome', 'already_exists')->count(),
                collect($summary['groups'])->where('outcome', 'would_create')->count(),
            ));

            return self::SUCCESS;
        }

        $failures = collect($summary['groups'])->whereIn('outcome', ['error', 'needs_review']);
        if ($failures->isNotEmpty()) {
            $this->newLine();
            $this->warn('Job folders that need attention:');
            $this->table(
                ['Job ID', 'Outcome', 'Reason'],
                $failures->map(fn (array $group): array => [
                    $group['job_id'],
                    $group['outcome'],
                    $group['error'] ?? '(no verified artifact found — see media_sources.failure_reason for this row)',
                ])->all(),
            );
        }

        $this->info(sprintf(
            'Created %d, skipped %d already-linked, %d need review (see table above and/or media_sources.failure_reason).',
            $summary['created'],
            $summary['skipped'],
            $summary['needs_review'],
        ));

        return $summary['needs_review'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
