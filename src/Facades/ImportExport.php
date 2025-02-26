<?php

namespace SmartCms\ImportExport\Facades;

use Illuminate\Support\Facades\Facade;

class ImportExport extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \SmartCms\ImportExport\ImportExport::class;
    }
}
