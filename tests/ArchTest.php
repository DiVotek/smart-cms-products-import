<?php

arch('it will not use debugging functions')
    ->expect(['dd', 'dump', 'ray'])
    ->each->not->toBeUsed();

arch('models should extend BaseModel')
    ->expect('\SmartCms\ImportExport\Models')
    ->toExtend('\SmartCms\Core\Models\BaseModel');

arch('models should use HasFactory trait')
    ->expect('\SmartCms\ImportExport\Models')
    ->toUseTrait('\Illuminate\Database\Eloquent\Factories\HasFactory');

arch('models should has suffix Model')
    ->expect('\SmartCms\ImportExport\Models')
    ->toHaveSuffix('Model');

arch('services should has service suffix')
    ->expect('\SmartCms\ImportExport\Services')
    ->toHaveSuffix('Service');

arch('commands should has Command suffix')
    ->expect('\SmartCms\ImportExport\Commands')
    ->toHaveSuffix('Command');

arch('events should has Event suffix')
    ->expect('\SmartCms\ImportExport\Events')
    ->toHaveSuffix('Event');

arch('events should be invokable')
    ->expect('\SmartCms\ImportExport\Events')
    ->toHaveMethod('__invoke');
