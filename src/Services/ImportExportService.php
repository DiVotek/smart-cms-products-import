<?php

namespace SmartCms\ImportExport\Services;

use Carbon\Carbon;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Google_Client;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use SmartCms\Core\Models\Language;
use SmartCms\ImportExport\Models\ImportTemplate;
use SmartCms\Store\Models\AttributeValue;
use SmartCms\Store\Models\Category;
use SmartCms\Store\Models\Product;
use SmartCms\Store\Models\StockStatus;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Google\Service\Sheets;
use Google\Service\Sheets\Spreadsheet;
use Google\Service\Sheets\ClearValuesRequest;
use Google\Service\Sheets\ValueRange;
use Google\Service\Sheets\BatchUpdateSpreadsheetRequest;
use Google\Service\Drive;
use SmartCms\Core\Models\Admin;

class ImportExportService
{
    protected $template;

    public function __construct(ImportTemplate $template)
    {
        $this->template = $template;
    }

    public function export(?int $categoryId = null): StreamedResponse
    {
        $query = Product::query();

        if ($categoryId) {
            $query->whereHas('category', function ($query) use ($categoryId) {
                $query->where('category_id', $categoryId);
            });
        }

        $products = $query->get()->map(function ($product) {
            return $this->transformProduct($product);
        });
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $this->template->name . '_' . now()->toDateTimeString() . '.csv"',
        ];
        $callback = function () use ($products) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, $this->template->fields, ';');

            foreach ($products as $product) {
                $row = [];
                foreach ($this->template->fields as $field) {
                    $row[] = $product[$field] ?? '';
                }
                fputcsv($handle, $row, ';');
            }

            fclose($handle);
        };

        return new StreamedResponse($callback, 200, $headers);
    }

    public function transformProduct(Product $product): array
    {
        $data = [];

        $originalFields = $this->template->fields;
        $orderedFields = [];
        $orderedFields[] = 'id';
        $orderedFields[] = 'name';
        $orderedFields[] = 'category_id';
        $orderedFields[] = 'origin_price';

        foreach ($originalFields as $field) {
            if (!in_array($field, $orderedFields)) {
                $orderedFields[] = $field;
            }
        }
        $this->template->fields = $orderedFields;

        foreach ($this->template->fields as $field) {
            $value = null;
            switch ($field) {
                case 'id':
                    $value = $product->id;
                    break;
                case 'name':
                    $value = $product->name;
                    break;
                case 'sku':
                    $value = $product->sku;
                    break;
                case 'category_id':
                    if ($product->category) {
                        $value = $product->category->name;
                    } else {
                        $value = '';
                    }
                    break;
                case 'categories':
                    $value = $product->categories()->where('id', '!=', $product->category_id ?? 0)->pluck('name')->implode(', ');
                    break;
                case 'stock_status_id':
                    if ($product->stock_status) {
                        $value = $product->stock_status->name;
                    }
                    break;
                case 'origin_price':
                    $value = $product->origin_price;
                    break;
                case 'sorting':
                    $value = $product->sorting;
                    break;
                case 'status':
                    $value = $product->status;
                    break;
                case 'images':
                    $images = [];
                    foreach ($product->images ?? [] as $image) {
                        $images[] = validateImage($image);
                    }
                    $value = implode(',', $images);
                    break;
                case 'is_index':
                    $value = $product->is_index ?? false;
                    break;
                case 'is_merchant':
                    $value = $product->is_merchant ?? false;
                    break;
                case 'created_at':
                    $value = $product->created_at->format('Y-m-d H:i:s');
                    break;
                case str_contains($field, 'name_'):
                    $lang = str_replace('name_', '', $field);
                    $language = Language::query()->where('slug', $lang)->first();
                    if ($language) {
                        $value = $product->translatable()->where('language_id', $language->id)->first()->value ?? '';
                    }
                    break;
                case str_contains($field, 'title_'):
                    $lang = str_replace('title_', '', $field);
                    $language = Language::query()->where('slug', $lang)->first();
                    if ($language) {
                        $value = $product->seo()->where('language_id', $language->id)->first()->title ?? '';
                    }
                    break;
                case str_contains($field, 'heading_'):
                    $lang = str_replace('heading_', '', $field);
                    $language = Language::query()->where('slug', $lang)->first();
                    if ($language) {
                        $value = $product->seo()->where('language_id', $language->id)->first()->heading ?? '';
                    }
                    break;
                case str_contains($field, 'summary_'):
                    $lang = str_replace('summary_', '', $field);
                    $language = Language::query()->where('slug', $lang)->first();
                    if ($language) {
                        $value = $product->seo()->where('language_id', $language->id)->first()->summary ?? '';
                    }
                    break;
                case str_contains($field, 'content_'):
                    $lang = str_replace('content_', '', $field);
                    $language = Language::query()->where('slug', $lang)->first();
                    if ($language) {
                        $value = $product->seo()->where('language_id', $language->id)->first()->content ?? '';
                    }
                    break;
                case str_contains($field, 'description_'):
                    $lang = str_replace('description_', '', $field);
                    $language = Language::query()->where('slug', $lang)->first();
                    if ($language) {
                        $value = $product->seo()->where('language_id', $language->id)->first()->description ?? '';
                    }
                    break;
                case str_contains($field, 'attribute_'):
                    $values = $product->attributeValues()->where('attribute_id', str_replace('attribute_', '', $field))->select('name')->get();
                    $value = $values->pluck('name')->implode(', ');
                    break;
            }
            if ($value == null) {
                Event::dispatch('cms.admin.import-template.product-export', [$field, $product, &$value]);
            }
            $data[$field] = $value ?? '';
        }

        return $data;
    }

    public function import(string $filePath): array
    {
        if (! Storage::disk('public')->exists($filePath)) {
            throw new \Exception('CSV file not found');
        }

        $fullPath = storage_path("app/public/{$filePath}");

        $products = [];
        $file = fopen($fullPath, 'r');
        $headers = fgetcsv($file, separator: ';');
        while (($row = fgetcsv($file, separator: ';')) !== false) {
            $products[] = array_combine($headers, $row);
        }
        fclose($file);
        $errors = 0;
        $success = 0;
        foreach ($products as $product) {
            try {
                if (isset($product['id']) && Product::query()->where('id', $product['id'])->exists()) {
                    $originalProduct = $this->transformProduct(Product::query()->where('id', $product['id'])->first());
                    $id = $product['id'];
                    $this->updateProduct($product, array_diff_assoc($product, $originalProduct));
                } else {
                    $id = $this->createProduct($product);
                }
                $updatedProduct = Product::query()->where('id', $id)->first();
                $this->updateSeo($updatedProduct, $product);
                $this->updateTransates($updatedProduct, $product);
                $this->updateAttributes($updatedProduct, $product);
                $success++;
            } catch (\Exception $e) {
                Log::error($e->getMessage());
                $errors++;
            }
        }

        return [
            'success' => $success,
            'errors' => $errors,
        ];
    }

    private function updateProduct(array $product, array $diff): void
    {
        $product = Product::query()->where('id', $product['id'])->first();
        foreach ($diff as $field => $value) {
            switch ($field) {
                case 'name':
                    $product->name = $value;
                    break;
                case 'sku':
                    $product->sku = $value;
                    break;
                case 'category_id':
                    $category = Category::query()->where('name', $value)->first();
                    if (! $category) {
                        throw new \Exception('Category not found');
                    }
                    $product->category_id = $category->id;
                    break;
                case 'categories':
                    $categories = explode(',', $value);
                    $categoryIds = Category::query()->whereIn('name', $categories)->pluck('id');
                    $product->categories()->sync($categoryIds);
                    break;
                case 'stock_status_id':
                    $stockStatus = StockStatus::query()->where('name', $value)->first();
                    if (! $stockStatus) {
                        throw new \Exception('Stock status not found');
                    }
                    $product->stock_status_id = $stockStatus->id;
                    break;
                case 'origin_price':
                    $product->origin_price = $value;
                    break;
                case 'sorting':
                    $product->sorting = (int) $value;
                    break;
                case 'status':
                    $product->status = (bool) $value;
                    break;
                case 'images':
                    // @todo Download images from urls
                    break;
                case 'is_index':
                    $product->is_index = (bool) $value;
                    break;
                case 'is_merchant':
                    $product->is_merchant = (bool) $value;
                    break;
                case 'created_at':
                    $product->created_at = Carbon::parse($value ?? now());
                    break;
                    // case str_contains($field, 'name_'):
                    //     $lang = str_replace('name_', '', $field);
                    //     $language = Language::query()->where('slug', $lang)->first();
                    //     if ($language) {
                    //         $product->translatable()->updateOrCreate(
                    //             ['language_id' => $language->id],
                    //             ['value' => $value]
                    //         );
                    //     }
                    //     break;
            }
        }
        $product->save();
    }

    private function createProduct(array $product): int
    {
        $category_id = $product['category_id'] ?? null;
        $name = $product['name'] ?? null;
        $price = $product['origin_price'] ?? null;
        if (! $name || $name == '') {
            throw new \Exception('Name is required');
        }
        if (! $price || $price == '') {
            throw new \Exception('Price is required');
        }
        if (! $category_id || $category_id == '') {
            throw new \Exception('Category is required');
        }
        $category = Category::query()->where('name', $category_id)->first();
        if (! $category) {
            throw new \Exception('Category not found');
        }
        $slug = Str::slug($product['name']);
        if (Product::query()->where('slug', $slug)->exists()) {
            do {
                $slug = $slug . '-' . Str::random(5);
            } while (Product::query()->where('slug', $slug)->exists());
        }
        $entity = new Product;
        $entity->name = $product['name'];
        $entity->slug = $slug;
        $entity->sku = $product['sku'] ?? '';
        if (empty($entity->sku)) {
            while (Product::query()->where('sku', $entity->sku)->exists()) {
                $entity->sku = Str::random(10);
            }
        }
        $entity->category_id = $category->id ?? null;
        $entity->stock_status_id = StockStatus::query()->where('name', $product['stock_status_id'] ?? 'In Stock')->first()?->id ?? StockStatus::query()->first()?->id ?? null;
        $entity->origin_price = $product['origin_price'];
        $entity->sorting = $product['sorting'] ?? 0;
        $images = explode(',', $product['images'] ?? '');
        if (! is_array($images)) {
            $images = [];
        }
        $entity->images = $images;
        $entity->is_index = $product['is_index'] ?? false;
        $entity->is_merchant = $product['is_merchant'] ?? false;
        $entity->save();
        $categories = explode(',', $product['categories'] ?? '');
        $categoryIds = Category::query()->whereIn('name', $categories)->pluck('id') ?? [];
        $entity->categories()->sync($categoryIds);

        return $entity->id;
    }

    private function updateSeo(Product $entity, array $data): void
    {
        foreach (get_active_languages() as $lang) {
            $title = null;
            $heading = null;
            $description = null;
            $summary = null;
            $content = null;
            if (isset($data['title_' . $lang->slug])) {
                $title = $data['title_' . $lang->slug];
            }
            if (isset($data['description_' . $lang->slug])) {
                $description = $data['description_' . $lang->slug];
            }
            if (isset($data['summary_' . $lang->slug])) {
                $summary = $data['summary_' . $lang->slug];
            }
            if (isset($data['heading_' . $lang->slug])) {
                $heading = $data['heading_' . $lang->slug];
            }
            if (isset($data['content_' . $lang->slug])) {
                $content = $data['content_' . $lang->slug];
            }
            if ($title) {
                $entity->seo()->updateOrCreate([
                    'language_id' => $lang->id,
                ], [
                    'title' => $title,
                    'heading' => $heading ?? '',
                    'summary' => $summary ?? '',
                    'description' => $description ?? '',
                    'content' => $content ?? '',
                ]);
            }
        }
    }

    private function updateTransates(Product $entity, array $data): void
    {
        foreach (get_active_languages() as $lang) {
            if (isset($data['name_' . $lang->slug])) {
                $entity->translatable()->updateOrCreate([
                    'language_id' => $lang->id,
                ], [
                    'value' => $data['name_' . $lang->slug],
                ]);
            }
        }
    }

    private function updateAttributes(Product $product, array $data): void
    {
        $data = array_filter($data, function ($value, $key) {
            return str_contains($key, 'attribute_');
        }, ARRAY_FILTER_USE_BOTH);
        $attributeValues = [];
        foreach ($data as $key => $value) {
            $attributeValues = array_merge($attributeValues, AttributeValue::query()->whereIn('name', explode(',', $value))->pluck('id')->toArray());
        }
        $product->attributeValues()->sync($attributeValues);
    }

    public function exportToGoogleSheets(?int $categoryId = null)
    {
        if (!setting('import_export.google_sheets_enabled', false)) {
            throw new \Exception('Google Sheets integration is not enabled');
        }

        $serviceAccountJson = setting('import_export.google_sheets_service_account_json');
        if (empty($serviceAccountJson)) {
            throw new \Exception('Google Sheets service account credentials are not configured');
        }

        $spreadsheetId = $this->getOrCreateSpreadsheet();

        $query = Product::query();
        if ($categoryId) {
            $query->whereHas('category', function ($query) use ($categoryId) {
                $query->where('category_id', $categoryId);
            });
        }

        $products = $query->get()->map(function ($product) {
            return $this->transformProduct($product);
        });

        $this->uploadDataToSheet($spreadsheetId, $products);
        $this->template->google_sheets_link = $spreadsheetId;
        $this->template->save();
        $adminEmails = setting('import_export.google_sheets_admin_emails', '');
        if ($adminEmails) {
            foreach (explode(',', $adminEmails) as $email) {
                $this->shareSpreadsheet($spreadsheetId, $email);
            }
        }
        Notification::make()
            ->title(__('import_export::trans.export_successful'))
            ->body(__('import_export::trans.data_exported_to_google_sheets'))
            ->actions([
                Action::make('viewSpreadsheet')
                    ->label(__('import_export::trans.view_spreadsheet'))
                    ->url("https://docs.google.com/spreadsheets/d/{$spreadsheetId}/edit")
                    ->openUrlInNewTab(),
            ])
            ->success()
            ->send();
    }

    private function getOrCreateSpreadsheet(): string
    {
        try {
            if ($this->template->google_sheets_link) {
                return $this->template->google_sheets_link;
            }
            $client = $this->getGoogleClient();
            $service = new Sheets($client);
            $spreadsheetName = $this->template->name . ' - ' . now()->format('Y-m-d');
            $spreadsheet = new Spreadsheet([
                'properties' => [
                    'title' => $spreadsheetName
                ]
            ]);

            $spreadsheet = $service->spreadsheets->create($spreadsheet);
            $spreadsheetId = $spreadsheet->spreadsheetId;

            return $spreadsheetId;
        } catch (\Exception $e) {
            // Log the main error
            Log::error('Failed during spreadsheet creation', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    private function shareSpreadsheet(string $spreadsheetId, string $email): void
    {
        try {
            // Initialize Google Client with Drive access
            $client = $this->getGoogleClient();

            // Create Google Drive service
            $driveService = new \Google\Service\Drive($client);

            // Create permission
            $permission = new \Google\Service\Drive\Permission([
                'type' => 'user',
                'role' => 'writer',
                'emailAddress' => $email
            ]);

            // Add permission to the file
            $driveService->permissions->create($spreadsheetId, $permission, [
                'sendNotificationEmail' => true
            ]);

            Log::info('Spreadsheet shared successfully', [
                'spreadsheet_id' => $spreadsheetId,
                'shared_with' => $email
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to share spreadsheet', [
                'spreadsheet_id' => $spreadsheetId,
                'error' => $e->getMessage()
            ]);
        }
    }

    private function uploadDataToSheet(string $spreadsheetId, $products): void
    {
        $client = $this->getGoogleClient();
        $service = new Sheets($client);
        $values = [];
        $values[] = $this->template->fields;

        foreach ($products as $product) {
            $row = [];
            foreach ($this->template->fields as $field) {
                $row[] = $product[$field] ?? '';
            }
            $values[] = $row;
        }

        // Clear existing data
        $clearRange = 'Sheet1!A1:Z' . (count($values) + 100);
        $clearBody = new ClearValuesRequest();
        $service->spreadsheets_values->clear($spreadsheetId, $clearRange, $clearBody);

        $body = new ValueRange([
            'values' => $values
        ]);

        $params = [
            'valueInputOption' => 'RAW'
        ];

        $range = 'Sheet1!A1';
        $service->spreadsheets_values->update($spreadsheetId, $range, $body, $params);
        $requests = [];
        foreach (range(0, count($this->template->fields) - 1) as $columnIndex) {
            $requests[] = [
                'autoResizeDimensions' => [
                    'dimensions' => [
                        'sheetId' => 0,
                        'dimension' => 'COLUMNS',
                        'startIndex' => $columnIndex,
                        'endIndex' => $columnIndex + 1
                    ]
                ]
            ];
        }

        $batchUpdateRequest = new BatchUpdateSpreadsheetRequest([
            'requests' => $requests
        ]);

        $service->spreadsheets->batchUpdate($spreadsheetId, $batchUpdateRequest);
    }

    private function getGoogleClient(): \Google_Client
    {
        $client = new \Google_Client();
        $client->setApplicationName('ImportExport Service');

        // Get service account JSON from settings
        $serviceAccountJson = setting('import_export.google_sheets_service_account_json');

        // Load the service account credentials
        $serviceAccount = json_decode($serviceAccountJson, true);
        $client->setAuthConfig($serviceAccount);

        // Set scopes
        $client->setScopes([
            Sheets::SPREADSHEETS,
            Drive::DRIVE_FILE
        ]);

        // Make sure we act as the service account
        $client->setSubject($serviceAccount['client_email']);

        return $client;
    }

    /**
     * Import data from Google Sheets
     *
     * @return array Result statistics with success and error counts
     */
    public function importFromGoogleSheets(): array
    {
        // Check if Google Sheets is enabled
        if (!setting('import_export.google_sheets_enabled', false)) {
            throw new \Exception('Google Sheets integration is not enabled');
        }

        // Check if we have a spreadsheet ID
        if (empty($this->template->google_sheets_link)) {
            throw new \Exception('No Google Sheet linked to this template. Please export to Google Sheets first.');
        }

        $spreadsheetId = $this->template->google_sheets_link;

        // Initialize Google client
        $client = $this->getGoogleClient();
        $service = new Sheets($client);

        try {
            // Get spreadsheet data
            $range = 'Sheet1!A1:Z1000'; // Adjust range as needed
            $response = $service->spreadsheets_values->get($spreadsheetId, $range);
            $values = $response->getValues();

            if (empty($values)) {
                throw new \Exception('No data found in the Google Sheet');
            }

            // Process header row
            $headers = array_shift($values);

            // Convert Google Sheets data to the format expected by the import method
            $products = [];
            foreach ($values as $row) {
                $product = [];
                foreach ($headers as $index => $header) {
                    $product[$header] = $row[$index] ?? '';
                }
                $products[] = $product;
            }

            // Process import
            $errors = 0;
            $success = 0;

            foreach ($products as $product) {
                try {
                    if (isset($product['id']) && Product::query()->where('id', $product['id'])->exists()) {
                        $originalProduct = $this->transformProduct(Product::query()->where('id', $product['id'])->first());
                        $id = $product['id'];
                        $this->updateProduct($product, array_diff_assoc($product, $originalProduct));
                    } else {
                        $id = $this->createProduct($product);
                    }

                    $updatedProduct = Product::query()->where('id', $id)->first();
                    $this->updateSeo($updatedProduct, $product);
                    $this->updateTransates($updatedProduct, $product);
                    $this->updateAttributes($updatedProduct, $product);

                    $success++;
                } catch (\Exception $e) {
                    Log::error('Failed to import product from Google Sheets', [
                        'product' => $product,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    $errors++;
                }
            }

            return [
                'success' => $success,
                'errors' => $errors,
                'total' => count($products),
            ];
        } catch (\Exception $e) {
            Log::error('Error importing from Google Sheets', [
                'spreadsheet_id' => $spreadsheetId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw new \Exception('Failed to import from Google Sheets: ' . $e->getMessage());
        }
    }
}
