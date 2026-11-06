<?php

namespace Database\Seeders;

use App\Models\Location;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class LocationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $items = json_decode(file_get_contents(__DIR__.'/locations.json'), true, flags: JSON_THROW_ON_ERROR);
        $rules = [
            'id' => 'required|string|max:200',
            'name' => 'required|string|max:300',
            'category' => 'required|in:tourism,lodging,food,entertainment,health,administration,utility',
            'lat' => 'nullable|numeric|between:-90,90',
            'lng' => 'nullable|numeric|between:-180,180',
            'gallery' => 'sometimes|array',
            'panoramas' => 'sometimes|array',
            'videos' => 'sometimes|array',
        ];
        foreach (['group', 'subgroup', 'address', 'description', 'notes', 'phone', 'zaloUrl', 'website', 'email', 'facebook', 'hours', 'keywords', 'heritageStatus', 'image', 'imageAlt', 'imageSourceUrl', 'imageSourceName'] as $field) {
            $rules[$field] = 'sometimes|nullable|string';
        }
        $rows = [];
        foreach ($items as $item) {
            $data = Validator::make($item, $rules)->validate();
            $row = [];
            foreach ($data as $field => $value) {
                if (in_array($field, ['group', 'subgroup', 'address', 'description', 'notes'], true)) {
                    $value ??= '';
                }
                $row[Str::snake($field)] = $value;
            }
            $rows[] = $row;
        }
        $created = DB::transaction(function () use ($rows): int {
            $count = 0;
            foreach ($rows as $row) {
                if (Location::firstOrCreate(['id' => $row['id']], $row)->wasRecentlyCreated) {
                    $count++;
                }
            }

            return $count;
        });
        $this->command?->info('Địa điểm: thêm '.$created.', đã có '.(count($rows) - $created).'.');
    }
}
