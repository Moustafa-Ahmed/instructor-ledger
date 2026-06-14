<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Throwable;

class QueryLoggingServiceProvider extends ServiceProvider
{
    private const LOG_PATH = 'app/query-log.json';

    public function boot(): void
    {
        if (! $this->loggingEnabled()) {
            return;
        }

        $this->ensureLogFileExists();

        DB::listen(function (QueryExecuted $query): void {
            if (! $this->isSelect($query->sql)) {
                return;
            }

            $this->appendRecord([
                'timestamp' => now()->toDateTimeString(),
                'sql' => $query->sql,
                'bindings' => $query->bindings,
                'time_ms' => $query->time,
                'explain' => $this->runExplain($query),
            ]);
        });
    }

    private function loggingEnabled(): bool
    {
        return (bool) env('QUERY_LOG_ENABLED', false);
    }

    private function ensureLogFileExists(): void
    {
        try {
            $path = storage_path(self::LOG_PATH);
            $dir = dirname($path);

            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            if (! file_exists($path)) {
                file_put_contents($path, "[]\n", LOCK_EX);
            }
        } catch (Throwable) {
            // Silently ignore so the request flow is never interrupted.
        }
    }

    private function isSelect(string $sql): bool
    {
        return str_starts_with(strtoupper(ltrim($sql)), 'SELECT');
    }

    private function runExplain(QueryExecuted $query): array
    {
        try {
            $grammar = $query->connection->getQueryGrammar();
            $sqlWithBindings = $grammar->substituteBindingsIntoRawSql($query->sql, $query->bindings);

            $rows = $query->connection->select("EXPLAIN {$sqlWithBindings}");

            return array_map(fn ($row) => (array) $row, $rows);
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    private function appendRecord(array $record): void
    {
        $handle = null;

        try {
            $path = storage_path(self::LOG_PATH);
            $handle = fopen($path, 'c+');

            if ($handle === false) {
                return;
            }

            if (! flock($handle, LOCK_EX)) {
                fclose($handle);

                return;
            }

            $contents = stream_get_contents($handle);
            $logs = json_decode($contents ?: '[]', true) ?: [];
            $logs[] = $record;

            ftruncate($handle, 0);
            rewind($handle);
            fwrite(
                $handle,
                json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            );
            fflush($handle);
            flock($handle, LOCK_UN);
        } catch (Throwable) {
            // Fail silently so query logging never breaks the application.
        } finally {
            if ($handle !== null) {
                fclose($handle);
            }
        }
    }
}
