<?php

namespace SmartCms\ImportExport\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use SmartCms\Core\Models\BaseModel;

class ImportTemplate extends BaseModel
{
   use HasFactory;

   protected $guarded = [];

   protected $casts = [
      'fields' => 'array',
   ];
}
