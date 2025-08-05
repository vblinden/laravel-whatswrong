<?php

namespace Vblinden\WhatsWrong;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Vblinden\WhatsWrong\Commands\WhatsWrongCommand;

class WhatsWrongServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('laravel-whats-wrong')
            ->hasConfigFile()
            ->hasViews()
            ->hasMigration('create_laravel_whats_wrong_table')
            ->hasCommand(WhatsWrongCommand::class);
    }
}
