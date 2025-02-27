<?php

use Illuminate\Support\Facades\Route;
use SmartCms\ImportExport\Admin\Resources\ImportTemplateResource\ManageImportTemplates;

Route::middleware([
   'web'
])->group(function () {
   Route::get('/admin/import-template/{record}/export', [ManageImportTemplates::class, 'export'])->name('admin.import-template.export');
});
