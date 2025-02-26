<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use SmartCms\ImportExport\Models\RequiredFieldTemplates;

return new class extends Migration {
    public function up(): void
    {
        Schema::create(RequiredFieldTemplates::getDb(), function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->json('fields');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(RequiredFieldTemplates::getDb());
    }
};
