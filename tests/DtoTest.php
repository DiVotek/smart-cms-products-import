<?php

use Illuminate\Support\Facades\Storage;
use SmartCms\ImportExport\Database\Factories\RequiredFieldTemplatesFactory;
use SmartCms\ImportExport\Services\ImportExportService;

it('exports products', function () {
    Storage::fake('public');

    $template = RequiredFieldTemplatesFactory::new()->create();

    $product = \SmartCms\Store\Database\Factories\ProductFactory::new()->state(['status' => 1])->create();

    $exportService = new ImportExportService;

    $fileUrl = $exportService->export([$product->toArray()], $template->id);

    $filePath = storage_path('app/public/'.basename($fileUrl));
    $csvData = array_map('str_getcsv', file($filePath));

    expect($csvData[0])->toBe(['Product Name', 'Price', 'SKU']);

    expect($csvData[1])->toBe(['Test Product', '199.99', 'TP123']);
});
