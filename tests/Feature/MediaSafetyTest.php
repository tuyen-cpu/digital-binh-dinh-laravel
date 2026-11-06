<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\MediaAsset;
use App\Models\User;
use App\Services\MediaLifecycle;
use App\Services\Tokens;
use Database\Seeders\CategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['filesystems.disks.r2.key' => 'test', 'filesystems.disks.r2.secret' => 'test', 'filesystems.disks.r2.bucket' => 'test',
            'filesystems.disks.r2.endpoint' => 'https://r2.example.test', 'filesystems.disks.r2.url' => 'https://media.example.test']);
        Storage::fake('r2');
        $this->seed(CategorySeeder::class);
    }

    private function headers(string $role = 'admin'): array
    {
        $user = User::create(['username' => 'test'.bin2hex(random_bytes(4)), 'display_name' => 'Test', 'password' => 'password123', 'role' => $role, 'permissions' => ['categories' => ['food']]]);

        return ['Authorization' => 'Bearer '.app(Tokens::class)->issue($user)['access']];
    }

    private function asset(string $folder = 'locations', string $status = 'attached'): MediaAsset
    {
        $key = $folder.'/'.bin2hex(random_bytes(16)).'.jpg';
        Storage::disk('r2')->put($key, 'image');

        return MediaAsset::create(['key' => $key, 'url' => 'https://media.example.test/'.$key, 'status' => $status]);
    }

    public function test_upload_uses_detected_mime_and_enforces_role(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jOioAAAAASUVORK5CYII=');
        $user = $this->headers('user');
        $file = UploadedFile::fake()->createWithContent('picture.png', $png);
        $this->postJson('/api/media/upload/', ['file' => $file, 'folder' => 'locations'], $user)->assertForbidden();
        $response = $this->postJson('/api/media/upload/', ['file' => $file, 'folder' => 'avatars'], $user)->assertCreated()->assertJsonStructure(['url', 'key']);
        Storage::disk('r2')->assertExists($response->json('key'));
        $this->assertDatabaseHas('media_assets', ['key' => $response->json('key'), 'status' => 'pending']);
        $path = tempnam(sys_get_temp_dir(), 'media-test-');
        file_put_contents($path, '<?php echo "bad";');
        try {
            $forged = new UploadedFile($path, 'fake.jpg', 'image/jpeg', null, true);
            $this->postJson('/api/media/upload/', ['file' => $forged, 'folder' => 'avatars'], $user)->assertStatus(400);
        } finally {
            unlink($path);
        }
        $this->postJson('/api/media/upload/', ['file' => $file, 'folder' => 'slides'], $this->headers('manager'))->assertForbidden();
        $this->postJson('/api/media/upload/', ['file' => $file, 'folder' => '../private'], $user)->assertStatus(400);
    }

    public function test_shared_image_is_retained_then_deleted_after_last_reference(): void
    {
        $asset = $this->asset();
        Location::create(['id' => 'one', 'name' => 'One', 'image' => $asset->url]);
        Location::create(['id' => 'two', 'name' => 'Two', 'image' => $asset->url]);
        $headers = $this->headers();
        $this->putJson('/api/locations/one/', ['image' => null], $headers)->assertOk();
        Storage::disk('r2')->assertExists($asset->key);
        $this->assertDatabaseHas('media_assets', ['id' => $asset->id, 'status' => 'attached']);
        $this->deleteJson('/api/locations/two/', [], $headers)->assertOk();
        Storage::disk('r2')->assertMissing($asset->key);
    }

    public function test_failed_transaction_never_deletes_old_file_or_attaches_new(): void
    {
        $old = $this->asset();
        $new = $this->asset('locations', 'pending');
        $location = Location::create(['id' => 'one', 'name' => 'One', 'image' => $old->url]);
        try {
            DB::transaction(function () use ($old, $new, $location) {
                $media = app(MediaLifecycle::class);
                $media->attach([$new->url]);
                $location->update(['image' => $new->url]);
                $media->replace([$old->url], [$new->url]);
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException $e) {
            $this->assertSame('rollback', $e->getMessage());
        }
        Storage::disk('r2')->assertExists($old->key);
        $this->assertDatabaseHas('locations', ['id' => 'one', 'image' => $old->url]);
        $this->assertDatabaseHas('media_assets', ['id' => $new->id, 'status' => 'pending']);
        $this->assertDatabaseHas('media_assets', ['id' => $old->id, 'status' => 'attached']);
    }

    public function test_r2_delete_failure_keeps_retry_metadata(): void
    {
        $asset = $this->asset('locations', 'orphaned');
        Storage::shouldReceive('disk')->with('r2')->andReturnSelf();
        Storage::shouldReceive('delete')->with($asset->key)->andThrow(new \RuntimeException('R2 unavailable'));
        try {
            app(MediaLifecycle::class)->discard($asset->id, 'orphaned');
            $this->fail('Expected storage failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('R2 unavailable', $e->getMessage());
        }
        $this->assertDatabaseHas('media_assets', ['id' => $asset->id, 'status' => 'orphaned']);
    }

    public function test_manager_cannot_delete_outside_scope_or_avatar(): void
    {
        $asset = $this->asset();
        Location::create(['id' => 'one', 'name' => 'One', 'category' => 'tourism', 'image' => $asset->url]);
        $manager = $this->headers('manager');
        $this->postJson('/api/media/delete/', ['key' => $asset->key], $manager)->assertForbidden();
        Location::whereKey('one')->update(['category' => 'food']);
        $this->postJson('/api/media/delete/', ['key' => $asset->key], $manager)->assertOk();
        $avatar = $this->asset('avatars');
        $this->postJson('/api/media/delete/', ['key' => $avatar->key], $manager)->assertForbidden();
        $this->postJson('/api/media/delete/', ['url' => 'https://outside.example.test/file.jpg'], $this->headers())->assertStatus(400);
    }

    public function test_missing_r2_configuration_returns_service_unavailable(): void
    {
        config(['filesystems.disks.r2.key' => '']);
        $this->postJson('/api/media/discard/', ['urls' => ['https://example.test/a.jpg']], $this->headers())->assertStatus(503);
    }
}
