<?php

namespace SmartCms\ImportExport\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use SmartCms\Core\Models\BaseModel;

class RequiredFieldTemplates extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'name',
        'fields',
    ];

    protected $casts = [
        'fields' => 'array',
    ];
}
