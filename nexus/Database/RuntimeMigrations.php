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

    public static function ensureInviteRegistrationRequestsTable(): void
    {
        self::ensureSchema(
            'invite_registration_requests',
            null,
            'database/migrations/2026_11_22_000001_create_invite_registration_requests_table.php',
            'nexus:migrations:invite_registration_requests'
        );
    }

    public static function ensureStandaloneForumTables(): void
    {
        $tables = [
            'standalone_forum_groups',
            'standalone_forums',
            'standalone_forum_topics',
            'standalone_forum_posts',
        ];
        if (self::allTablesExist($tables)) {
            return;
        }

        self::bootstrapLaravel();
        Cache::lock('nexus:migrations:standalone_forums', 120)->block(30, function () use ($tables): void {
            if (self::allTablesExist($tables)) {
                return;
            }

            $migration = 'database/migrations/2026_11_26_000000_create_standalone_forum_tables.php';
            $kernel = app(ConsoleKernel::class);
            $exitCode = $kernel->call('migrate', [
                '--path' => $migration,
                '--force' => true,
                '--no-interaction' => true,
            ]);

            if ($exitCode !== 0 || !self::allTablesExist($tables)) {
                $output = trim($kernel->output());
                throw new RuntimeException(
                    sprintf('Unable to apply migration %s.', $migration)
                    . ($output === '' ? '' : ' ' . $output)
                );
            }
        });
    }

    public static function ensureStandaloneForumTorrentsTable(): void
    {
        self::ensureSchema(
            'standalone_forum_torrents',
            null,
            'database/migrations/2026_11_26_000001_create_standalone_forum_torrents_table.php',
            'nexus:migrations:standalone_forum_torrents'
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

    private static function allTablesExist(array $tables): bool
    {
        foreach ($tables as $table) {
            if (!NexusDB::hasTable($table)) {
                return false;
            }
        }

        return true;
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
