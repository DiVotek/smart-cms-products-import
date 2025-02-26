<?php

namespace SmartCms\ImportExport;

use Illuminate\Support\ServiceProvider;

class ImportExportServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'importexports');
        $this->loadRoutesFrom(__DIR__.'/Routes/web.php');
    }

    public function boot()
    {

    }
}
