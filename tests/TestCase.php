<?php

declare(strict_types=1);

namespace Kerroldj\MigrateFreshTable\Tests;

use Dotenv\Dotenv;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kerroldj\MigrateFreshTable\MigrateFreshTableServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    private static bool $dotenvLoaded = false;

    protected function setUp(): void
    {
        // Testbench boots against its own skeleton, so the package's .env isn't
        // loaded automatically. Load it here (once) so each developer can point
        // the suite at their own database via .env without touching code.
        $this->loadPackageEnvironment();

        parent::setUp();

        // Server-backed drivers (pgsql/mysql) persist between tests, so reset
        // the isolated schema/database for each connection. In-memory SQLite is
        // naturally fresh per test.
        $this->resetConnections();
    }

    protected function loadPackageEnvironment(): void
    {
        if (self::$dotenvLoaded) {
            return;
        }

        self::$dotenvLoaded = true;

        $root = dirname(__DIR__);

        if (is_file($root.'/.env')) {
            // Immutable: real environment variables (e.g. CI) win over .env.
            Dotenv::createImmutable($root)->safeLoad();
        }
    }

    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            MigrateFreshTableServiceProvider::class,
        ];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $driver = $this->resolveDriver();

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', $this->connectionConfig($driver, 'testing'));
        $app['config']->set('database.connections.secondary', $this->connectionConfig($driver, 'secondary'));

        $app['config']->set('migrate-fresh-table.migration_paths', [
            __DIR__.'/database/migrations',
        ]);

        $app['config']->set('migrate-fresh-table.schema', [
            'widgets' => function (Blueprint $table): void {
                $table->id();
                $table->string('label');
                $table->timestamps();
            },
        ]);
    }

    /**
     * Resolve the driver from the developer's environment, accepting either
     * DB_DRIVER or the more common DB_CONNECTION, and normalising aliases
     * (e.g. "postgresql" -> "pgsql") so a typical .env "just works".
     */
    protected function resolveDriver(): string
    {
        $driver = (string) env('DB_DRIVER', env('DB_CONNECTION', 'sqlite'));

        return match (strtolower($driver)) {
            'pgsql', 'postgres', 'postgresql' => 'pgsql',
            'mysql' => 'mysql',
            'mariadb' => 'mariadb',
            'sqlsrv', 'mssql', 'sqlserver' => 'sqlsrv',
            default => 'sqlite',
        };
    }

    /**
     * Build a connection config for the given driver, isolating the two test
     * connections from each other (separate SQLite memory DBs, separate
     * PostgreSQL schemas, or separate MySQL/SQL Server databases).
     *
     * @return array<string, mixed>
     */
    protected function connectionConfig(string $driver, string $name): array
    {
        return match ($driver) {
            'pgsql' => [
                'driver' => 'pgsql',
                'host' => (string) env('DB_HOST', '127.0.0.1'),
                'port' => (string) env('DB_PORT', '5432'),
                'database' => (string) env('DB_DATABASE', 'testing'),
                'username' => (string) env('DB_USERNAME', 'testing'),
                'password' => (string) env('DB_PASSWORD', ''),
                'charset' => 'utf8',
                'prefix' => '',
                'search_path' => 'mft_'.$name,
                'sslmode' => 'prefer',
            ],
            'mysql', 'mariadb' => [
                'driver' => $driver,
                'host' => (string) env('DB_HOST', '127.0.0.1'),
                'port' => (string) env('DB_PORT', '3306'),
                'database' => 'mft_'.$name,
                'username' => (string) env('DB_USERNAME', 'root'),
                'password' => (string) env('DB_PASSWORD', ''),
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
            ],
            'sqlsrv' => [
                'driver' => 'sqlsrv',
                'host' => (string) env('DB_HOST', '127.0.0.1'),
                'port' => (string) env('DB_PORT', '1433'),
                'database' => 'mft_'.$name,
                'username' => (string) env('DB_USERNAME', 'sa'),
                'password' => (string) env('DB_PASSWORD', ''),
                'prefix' => '',
                'trust_server_certificate' => true,
            ],
            default => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        };
    }

    /**
     * Run the package's test migrations against the given connection.
     */
    protected function migrateConnection(string $connection): void
    {
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection($connection);

        try {
            foreach (glob(__DIR__.'/database/migrations/*.php') as $file) {
                $migration = require $file;
                $migration->up();
            }
        } finally {
            DB::setDefaultConnection($original);
        }
    }

    protected function resetConnections(): void
    {
        foreach (['testing', 'secondary'] as $connection) {
            $conn = DB::connection($connection);
            $driver = $conn->getDriverName();

            if ($driver === 'pgsql') {
                $schema = 'mft_'.$connection;
                $conn->statement("DROP SCHEMA IF EXISTS \"{$schema}\" CASCADE");
                $conn->statement("CREATE SCHEMA \"{$schema}\"");
            } elseif (in_array($driver, ['mysql', 'mariadb', 'sqlsrv'], true)) {
                Schema::connection($connection)->dropAllTables();
            }
            // SQLite :memory: is fresh per test; nothing to reset.
        }
    }
}
