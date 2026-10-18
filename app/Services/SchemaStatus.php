<?php

namespace App\Services;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\QueryException;
use Throwable;

class SchemaStatus
{
    public function __construct(private Migrator $migrator) {}

    /**
     * Migration file names that have not been recorded as run.
     *
     * @return list<string>
     */
    public function pending(): array
    {
        $files = $this->files();

        try {
            if (! $this->migrator->repositoryExists()) {
                return array_keys($files);
            }

            $ran = $this->migrator->getRepository()->getRan();
        } catch (QueryException) {
            return array_keys($files);
        }

        return array_values(array_diff(array_keys($files), $ran));
    }

    public function isCurrent(): bool
    {
        return $this->pending() === [];
    }

    /**
     * Run each pending migration in order, stopping on the first failure.
     *
     * @param  (callable(array{name: string, status: string, detail: string|null}): void)|null  $onProgress
     * @return list<array{name: string, status: string, detail: string|null}>
     */
    public function migrate(?callable $onProgress = null): array
    {
        $this->ensureRepository();

        $files = $this->files();
        $report = [];

        foreach ($this->pending() as $name) {
            $path = $files[$name] ?? null;

            if ($path === null) {
                continue;
            }

            if ($onProgress !== null) {
                $onProgress(['name' => $name, 'status' => 'running', 'detail' => null]);
            }

            $started = microtime(true);

            try {
                $this->migrator->runPending([$path], ['step' => true]);
                $row = [
                    'name' => $name,
                    'status' => 'ran',
                    'detail' => (int) round((microtime(true) - $started) * 1000).'ms',
                ];
            } catch (Throwable $e) {
                $row = [
                    'name' => $name,
                    'status' => 'failed',
                    'detail' => $e->getMessage(),
                ];
                $report[] = $row;

                if ($onProgress !== null) {
                    $onProgress($row);
                }

                break;
            }

            $report[] = $row;

            if ($onProgress !== null) {
                $onProgress($row);
            }
        }

        return $report;
    }

    /**
     * @return array<string, string>
     */
    public function files(): array
    {
        return $this->migrator->getMigrationFiles($this->paths());
    }

    /**
     * @return list<string>
     */
    public function paths(): array
    {
        return array_merge($this->migrator->paths(), [database_path('migrations')]);
    }

    private function ensureRepository(): void
    {
        try {
            if ($this->migrator->repositoryExists()) {
                return;
            }
        } catch (QueryException) {
            // Fall through and try to create the repository table.
        }

        $this->migrator->getRepository()->createRepository();
    }
}
