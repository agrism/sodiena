<?php

namespace Database\Seeders;

use App\Console\Commands\ConsolidateCategoriesCommand;
use App\Models\Category;
use App\Models\CategoryTranslation;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        foreach (ConsolidateCategoriesCommand::CANONICAL_CATEGORIES as $slug => $data) {
            $cat = Category::updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $data['name'],
                    'icon' => $data['icon'],
                    'color' => $data['color'],
                    'description' => $data['description'],
                    'order' => $data['order'],
                ]
            );

            foreach ($data['translations'] as $loc => $transName) {
                CategoryTranslation::updateOrCreate(
                    [
                        'category_id' => $cat->id,
                        'locale' => $loc,
                    ],
                    [
                        'name' => $transName,
                    ]
                );
            }
        }
    }
}
