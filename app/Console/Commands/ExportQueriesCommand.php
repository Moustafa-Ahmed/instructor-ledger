<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Throwable;

class ExportQueriesCommand extends Command
{
    protected $signature = 'queries:export
                            {--min-time=0 : Minimum execution time in milliseconds to include}';

    protected $description = 'Export captured SELECT queries slower than a threshold to query-log-ai.json';

    private const SOURCE_PATH = 'app/query-log.json';

    private const TARGET_PATH = 'app/query-log-ai.json';

    public function handle(): int
    {
        try {
            $source = storage_path(self::SOURCE_PATH);

            if (! file_exists($source)) {
                $this->warn('No query-log.json found; writing empty export.');
                file_put_contents(storage_path(self::TARGET_PATH), "[]\n", LOCK_EX);

                return self::SUCCESS;
            }

            $contents = file_get_contents($source);
            $queries = json_decode($contents ?: '[]', true) ?: [];
            $minTime = (float) $this->option('min-time');

            $filtered = array_values(array_filter(
                $queries,
                fn (array $query): bool => ($query['time_ms'] ?? 0) >= $minTime
            ));

            file_put_contents(
                storage_path(self::TARGET_PATH),
                json_encode($filtered, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n",
                LOCK_EX
            );

            $this->info('Exported '.count($filtered).' queries to '.self::TARGET_PATH);

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Export failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
