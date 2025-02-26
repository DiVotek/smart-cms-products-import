<?php

namespace SmartCms\ImportExport\Tests;

use Illuminate\Database\Eloquent\Factories\Factory;
use Orchestra\Testbench\TestCase as Orchestra;
use SmartCms\ImportExport\ImportExportServiceProvider;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../vendor/smart-cms/store/database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../vendor/smart-cms/core/database/migrations');

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'SmartCms\\ImportExport\\Database\\Factories\\'.class_basename($modelName).'Factory'
        );
    }

    protected function getPackageProviders($app)
    {
        return [
            ImportExportServiceProvider::class,
            \SmartCms\Store\StoreServiceProvider::class,
            \SmartCms\Core\SmartCmsPanelManager::class,
            \SmartCms\Core\SmartCmsServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');
        $app->singleton('_settings', function () {
            return new \SmartCms\Core\Services\Singletone\Settings;
        });

        /*
         foreach (\Illuminate\Support\Facades\File::allFiles(__DIR__ . '/database/migrations') as $migration) {
            (include $migration->getRealPath())->up();
         }
         */
    }
}
