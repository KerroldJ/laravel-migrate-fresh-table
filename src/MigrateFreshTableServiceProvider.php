<?php

declare(strict_types=1);

namespace Kerroldj\MigrateFreshTable;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\ServiceProvider;
use Kerroldj\MigrateFreshTable\Commands\MigrateFreshTableCommand;
use Kerroldj\MigrateFreshTable\Contracts\TableResolver;
use Kerroldj\MigrateFreshTable\Resolvers\MigrationResolver;
use Kerroldj\MigrateFreshTable\Strategies\MigrationStrategy;
use Kerroldj\MigrateFreshTable\Strategies\SchemaStrategy;
use Kerroldj\MigrateFreshTable\Support\StrategyManager;

class MigrateFreshTableServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/migrate-fresh-table.php',
            'migrate-fresh-table',
        );

        $this->app->bind(TableResolver::class, function ($app): MigrationResolver {
            return new MigrationResolver(
                $app->make(Repository::class),
                $app->make(Filesystem::class),
            );
        });

        $this->app->bind(MigrationStrategy::class, function ($app): MigrationStrategy {
            return new MigrationStrategy($app->make(TableResolver::class));
        });

        $this->app->bind(SchemaStrategy::class, function ($app): SchemaStrategy {
            return new SchemaStrategy($app->make(Repository::class));
        });

        $this->app->singleton(StrategyManager::class, function ($app): StrategyManager {
            return new StrategyManager($app);
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/migrate-fresh-table.php' => $this->app->configPath('migrate-fresh-table.php'),
            ], 'migrate-fresh-table-config');

            $this->commands([
                MigrateFreshTableCommand::class,
            ]);
        }
    }
}
