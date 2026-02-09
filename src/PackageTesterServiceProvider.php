<?php

namespace JobMetric\PackageTester;

use JobMetric\PackageCore\PackageCore;
use JobMetric\PackageCore\PackageCoreServiceProvider;

class PackageTesterServiceProvider extends PackageCoreServiceProvider
{
    /**
     * set configuration package
     *
     * @param PackageCore $package
     *
     * @return void
     */
    public function configuration(PackageCore $package): void
    {
        $package->name('laravel-package-tester')
            ->registerCommand(Commands\RunTesterCommand::class);
    }
}   
