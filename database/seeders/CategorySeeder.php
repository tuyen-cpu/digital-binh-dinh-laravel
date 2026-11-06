<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (json_decode(file_get_contents(__DIR__.'/categories.json'), true, flags: JSON_THROW_ON_ERROR) as $data) {
            Category::firstOrCreate(['key' => $data['key']], $data + ['is_active' => true]);
        }
    }
}
