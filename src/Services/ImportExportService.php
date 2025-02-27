<?php

namespace SmartCms\ImportExport\Services;

use Carbon\Carbon;
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
            'Content-Disposition' => 'attachment; filename="'.$this->template->name.'_'.now()->toDateTimeString().'.csv"',
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
        if (! in_array('id', $this->template->fields)) {
            $newFields = $this->template->fields;
            array_unshift($newFields, 'id');
            $this->template->fields = $newFields;
        }
        if (! in_array('name', $this->template->fields)) {
            $newFields = $this->template->fields;
            array_unshift($newFields, 'name');
            $this->template->fields = $newFields;
        }
        if (! in_array('price', $this->template->fields)) {
            $newFields = $this->template->fields;
            array_unshift($newFields, 'price');
            $this->template->fields = $newFields;
        }
        if (! in_array('category_id', $this->template->fields)) {
            $newFields = $this->template->fields;
            array_unshift($newFields, 'category_id');
            $this->template->fields = $newFields;
        }
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
                    $value = $product->category->name;
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
        $price = $product['price'] ?? null;
        if (!$name || $name == '') {
            throw new \Exception('Name is required');
        }
        if (!$price || $price == '') {
            throw new \Exception('Price is required');
        }
        if (!$category_id || $category_id == '') {
            throw new \Exception('Category is required');
        }
        $category = Category::query()->where('name', $category_id)->first();
        if (!$category) {
            throw new \Exception('Category not found');
        }
        $slug = Str::slug($product['name']);
        if (Product::query()->where('slug', $slug)->exists()) {
            do {
                $slug = $slug.'-'.Str::random(5);
            } while (Product::query()->where('slug', $slug)->exists());
        }
        $entity = new Product;
        $entity->name = $product['name'];
        $entity->slug = $slug;
        $entity->sku = $product['sku'];
        $entity->category_id = $category->id;
        $entity->stock_status_id = StockStatus::query()->where('name', $product['stock_status_id'])->first()?->id ?? StockStatus::query()->first()?->id ?? null;
        $entity->origin_price = $product['origin_price'];
        $entity->sorting = $product['sorting'] ?? 0;
        $images = explode(',', $product['images']);
        if (! is_array($images)) {
            $images = [];
        }
        $entity->images = $images;
        $entity->is_index = $product['is_index'] ?? false;
        $entity->is_merchant = $product['is_merchant'] ?? false;
        $entity->save();
        // foreach (get_active_languages() as $lang) {
        //     if (isset($product['name_' . $lang])) {
        //         $entity->translatable()->create([
        //             'language_id' => $lang->id,
        //             'value' => $product['name_' . $lang],
        //         ]);
        //     }
        //     $title = null;
        //     $heading = null;
        //     $description = null;
        //     $summary = null;
        //     $content = null;
        //     if (isset($product['title_' . $lang])) {
        //         $title = $product['title_' . $lang];
        //     }
        //     if (isset($product['description_' . $lang])) {
        //         $description = $product['description_' . $lang];
        //     }
        //     if (isset($product['summary_' . $lang])) {
        //         $summary = $product['summary_' . $lang];
        //     }
        //     if (isset($product['heading_' . $lang])) {
        //         $heading = $product['heading_' . $lang];
        //     }
        //     if (isset($product['content_' . $lang])) {
        //         $content = $product['content_' . $lang];
        //     }
        //     if ($title) {
        //         $entity->seo()->create([
        //             'language_id' => $lang->id,
        //             'title' => $title,
        //             'heading' => $heading ?? '',
        //             'summary' => $summary ?? '',
        //             'description' => $description ?? '',
        //             'content' => $content ?? '',
        //         ]);
        //     }
        // }
        $categories = explode(',', $product['categories']);
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
            if (isset($data['title_'.$lang])) {
                $title = $data['title_'.$lang];
            }
            if (isset($data['description_'.$lang])) {
                $description = $data['description_'.$lang];
            }
            if (isset($data['summary_'.$lang])) {
                $summary = $data['summary_'.$lang];
            }
            if (isset($data['heading_'.$lang])) {
                $heading = $data['heading_'.$lang];
            }
            if (isset($data['content_'.$lang])) {
                $content = $data['content_'.$lang];
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
            if (isset($data['name_'.$lang])) {
                $entity->translatable()->updateOrCreate([
                    'language_id' => $lang->id,
                ], [
                    'value' => $data['name_'.$lang],
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
}
