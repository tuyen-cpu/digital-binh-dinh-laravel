<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\SiteSetting;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocationSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_seed_populates_django_locations_and_site_defaults(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('locations', 165);
        $this->assertDatabaseCount('categories', 7);
        $this->assertDatabaseCount('site_settings', 1);
        $this->assertDatabaseHas('locations', ['id' => 'cu-ph-ngc-289962', 'name' => 'Cầu Phụ Ngọc', 'category' => 'tourism']);
        $this->assertDatabaseHas('locations', ['category' => 'lodging']);
        $this->getJson('/api/locations/')->assertOk()->assertJsonPath('count', 165);
        $this->getJson('/api/locations/cu-ph-ngc-289962/')->assertOk()
            ->assertJsonPath('heritageStatus', 'Kiểm kê 2026 theo QĐ 529/QĐ-UBND; đề xuất xếp hạng cấp tỉnh giai đoạn 2030-2035')
            ->assertJsonPath('gallery', []);
    }

    public function test_reseeding_preserves_edits_custom_records_and_site_settings(): void
    {
        $this->seed(DatabaseSeeder::class);
        Location::whereKey('cu-ph-ngc-289962')->update(['name' => 'Tên đã sửa', 'is_active' => false]);
        Location::create(['id' => 'custom', 'name' => 'Địa điểm tự thêm']);
        SiteSetting::solo()->update(['hero_interval_ms' => 9000]);

        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('locations', 166);
        $this->assertDatabaseCount('categories', 7);
        $this->assertDatabaseHas('locations', ['id' => 'cu-ph-ngc-289962', 'name' => 'Tên đã sửa', 'is_active' => false]);
        $this->assertDatabaseHas('locations', ['id' => 'custom', 'name' => 'Địa điểm tự thêm']);
        $this->assertDatabaseHas('site_settings', ['id' => 1, 'hero_interval_ms' => 9000]);
    }
}
