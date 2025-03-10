<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use SmartCms\ImportExport\Models\ImportTemplate;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(ImportTemplate::getDb(), function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->json('fields');
            $table->string('google_sheets_link')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(ImportTemplate::getDb());
    }
};
