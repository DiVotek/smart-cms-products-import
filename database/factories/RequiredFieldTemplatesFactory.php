<?php

namespace SmartCms\ImportExport\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use SmartCms\ImportExport\Models\RequiredFieldTemplates;

class RequiredFieldTemplatesFactory extends Factory
{
    protected $model = RequiredFieldTemplates::class;

    public function definition()
    {
        return [
            'name' => $this->faker->word.' Template',
            'fields' => json_encode([
                'name' => 'Product Name',
                'price' => 'Price',
                'sku' => 'SKU',
            ]),
        ];
    }
}
