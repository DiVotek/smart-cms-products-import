<?php

namespace SmartCms\ImportExport;

use Illuminate\Support\ServiceProvider;
use SmartCms\Core\SmartCmsPanelManager;
use SmartCms\ImportExport\Events\AdminNavigationResources;

class ImportExportServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'import_export');
        $this->loadRoutesFrom(__DIR__.'/Routes/web.php');
        SmartCmsPanelManager::registerHook('navigation.resources', AdminNavigationResources::class);
    }

    public function boot() {}
}
