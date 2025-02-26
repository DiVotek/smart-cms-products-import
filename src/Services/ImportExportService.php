<?php

namespace SmartCms\ImportExport\Services;

use Illuminate\Support\Facades\Storage;
use SmartCms\ImportExport\Models\RequiredFieldTemplates;

class ImportExportService
{
   public function export(array $products, int $templateId): string
   {
       $template = RequiredFieldTemplates::find($templateId);

       if (!$template) {
           throw new \Exception('Template not found');
       }

       $fields = $template->fields;

       $filename = 'products_export_' . now()->timestamp . '.csv';
       $filePath = storage_path("app/public/{$filename}");

       $file = fopen($filePath, 'w');

       if (!$file) {
           throw new \Exception('Unable to open file for writing');
       }

       fputcsv($file, array_values($fields));

       foreach ($products as $product) {
           $row = [];
           foreach ($fields as $field => $label) {
               $row[] = $product[$field] ?? '';
           }
           fputcsv($file, $row);
       }

       fclose($file);

       return Storage::url($filename);
   }

   public function import(string $filePath): array
   {
       if (!Storage::disk('public')->exists($filePath)) {
           throw new \Exception('CSV file not found');
       }

       $fullPath = storage_path("app/public/{$filePath}");

       $file = fopen($fullPath, 'r');

       if (!$file) {
           throw new \Exception('Unable to open file for reading');
       }

       $products = [];
       $headers = fgetcsv($file);

       while (($row = fgetcsv($file)) !== false) {
           $products[] = array_combine($headers, $row);
       }

       fclose($file);

       return $products;
   }
}
