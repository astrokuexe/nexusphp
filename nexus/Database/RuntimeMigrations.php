<?php

namespace Nexus\Database;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use RuntimeException;

final class RuntimeMigrations
{
    public static function ensureAccountRequestsTable(): void
    {
        self::ensureSchema(
            'account_requests',
            null,
            'database/migrations/2026_11_21_000001_create_account_requests_table.php',
            'nexus:migrations:account_requests'
        );
    }

    public static function ensureCategoryParentId(): void
    {
        self::ensureSchema(
            'categories',
            'parent_id',
            'database/migrations/2026_11_21_000000_add_parent_id_to_categories_table.php',
            'nexus:migrations:categories_parent_id'
        );
    }

    private static function ensureSchema(string $table, ?string $column, string $migration, string $lockName): void
    {
        if (self::schemaExists($table, $column)) {
            return;
        }

        self::bootstrapLaravel();
        Cache::lock($lockName, 120)->block(30, function () use ($table, $column, $migration): void {
            if (self::schemaExists($table, $column)) {
                return;
            }

            $kernel = app(ConsoleKernel::class);
            $exitCode = $kernel->call('migrate', [
                '--path' => $migration,
                '--force' => true,
                '--no-interaction' => true,
            ]);

            if ($exitCode !== 0 || !self::schemaExists($table, $column)) {
                $output = trim($kernel->output());
                throw new RuntimeException(
                    sprintf('Unable to apply migration %s.', $migration)
                    . ($output === '' ? '' : ' ' . $output)
                );
            }
        });
    }

    private static function schemaExists(string $table, ?string $column): bool
    {
        return $column === null
            ? NexusDB::hasTable($table)
            : NexusDB::hasColumn($table, $column);
    }

    private static function bootstrapLaravel(): void
    {
        if (Facade::getFacadeApplication() !== null) {
            return;
        }

        $legacyHook = $GLOBALS['hook'] ?? null;
        $legacyPlugin = $GLOBALS['plugin'] ?? null;
        $app = require ROOT_PATH . 'bootstrap/app.php';
        $app->make(ConsoleKernel::class)->bootstrap();

        if ($legacyHook !== null) {
            $GLOBALS['hook'] = $hook = $legacyHook;
        }
        if ($legacyPlugin !== null) {
            $GLOBALS['plugin'] = $plugin = $legacyPlugin;
        }
    }
}
