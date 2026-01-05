<?php

namespace JobMetric\PackageTester;

use Illuminate\Support\ServiceProvider;

abstract class PackageTesterServiceProvider extends ServiceProvider
{
    /**
     * register provider
     *
     * @return void
     */
    public function register(): void
    {
        $basePath = realpath(__DIR__ . DIRECTORY_SEPARATOR . '..');

        // register config
        $this->mergeConfigFrom($basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'config.php', 'package-tester');
    }

    /**
     * boot provider
     *
     * @return void
     */
    public function boot(): void
    {
        $basePath = realpath(__DIR__ . DIRECTORY_SEPARATOR . '..');

        // publish config
        $this->publishes([
            $basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'config.php' => config_path('package-tester.php'),
        ], 'config');

        // load commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                \JobMetric\PackageTester\Commands\PackageTesterCommand::class,
            ]);
        }
    }
}
