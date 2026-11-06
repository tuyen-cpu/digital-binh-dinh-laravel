<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\MediaAsset;
use App\Models\User;
use App\Services\MediaLifecycle;
use App\Services\Tokens;
use Database\Seeders\CategorySeeder;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TourismApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tourism.admin_password' => 'test-admin-password', 'filesystems.disks.r2.key' => 'test', 'filesystems.disks.r2.secret' => 'test',
            'filesystems.disks.r2.bucket' => 'test', 'filesystems.disks.r2.endpoint' => 'https://r2.example.test', 'filesystems.disks.r2.url' => 'https://media.example.test']);
        $this->seed(CategorySeeder::class);
    }

    private function user(string $role = 'user', array $permissions = []): User
    {
        static $i = 0;
        $i++;

        return User::create(['username' => 'tester'.$i, 'display_name' => 'Test User', 'phone' => '09'.str_pad((string) $i, 8, '0', STR_PAD_LEFT),
            'password' => 'password123', 'role' => $role, 'permissions' => $permissions]);
    }

    private function headers(User $user): array
    {
        return ['Authorization' => 'Bearer '.app(Tokens::class)->issue($user)['access']];
    }

    private function location(string $id = 'place', string $category = 'tourism'): Location
    {
        return Location::create(['id' => $id, 'name' => 'Điểm tham quan', 'category' => $category]);
    }

    private function asset(string $status = 'pending', ?User $owner = null): MediaAsset
    {
        $key = 'locations/'.bin2hex(random_bytes(16)).'.jpg';
        Storage::disk('r2')->put($key, 'image');

        return MediaAsset::create(['key' => $key, 'url' => 'https://media.example.test/'.$key, 'uploaded_by' => $owner?->id, 'status' => $status]);
    }

    public function test_registration_login_session_and_logout(): void
    {
        $response = $this->postJson('/api/auth/register/', ['username' => 'newuser', 'displayName' => 'Người dùng', 'phone' => '0912.345.678', 'password' => 'password123']);
        $response->assertCreated()->assertJsonPath('user.role', 'user')->assertJsonPath('user.phone', '0912345678')->assertJsonStructure(['access', 'refresh', 'user' => ['id', 'permissions', 'mustChangePassword']]);
        $this->assertDatabaseMissing('users', ['password' => 'password123']);
        $this->postJson('/api/auth/login/', ['username' => 'NEWUSER', 'password' => 'password123'])->assertOk();
        $headers = ['Authorization' => 'Bearer '.$response->json('access')];
        $this->getJson('/api/auth/me/', $headers)->assertOk()->assertJsonPath('username', 'newuser');
        $this->postJson('/api/auth/logout/', ['refresh' => $response->json('refresh')], $headers)->assertOk();
        $this->getJson('/api/auth/me/', $headers)->assertUnauthorized();
    }

    public function test_invalid_registration_and_reserved_username(): void
    {
        $this->postJson('/api/auth/register', ['username' => 'ADMIN', 'displayName' => 'Admin', 'phone' => '0912345678', 'password' => '123456'])->assertStatus(400);
        $this->postJson('/api/auth/register', ['username' => 'validuser', 'displayName' => 'Test', 'phone' => 'invalid', 'password' => '123456'])->assertStatus(400)->assertJsonStructure(['phone']);
        $this->postJson('/api/auth/register', ['username' => 'validuser', 'displayName' => 'Test', 'phone' => '0912345678', 'password' => '123456', 'role' => 'admin'])->assertCreated()->assertJsonPath('user.role', 'user');
        $this->postJson('/api/auth/register', ['username' => 'VALIDUSER', 'displayName' => 'Test', 'phone' => '0987654321', 'password' => '123456'])->assertStatus(400);
    }

    public function test_admin_login_and_user_management(): void
    {
        $login = $this->postJson('/api/auth/login', ['username' => 'admin', 'password' => 'test-admin-password'])->assertOk()->assertJsonPath('user.role', 'admin');
        $headers = ['Authorization' => 'Bearer '.$login->json('access')];
        $user = $this->user();
        $this->getJson('/api/admin/users/', $headers)->assertOk()->assertJsonPath('count', 1);
        $this->putJson('/api/admin/users/'.$user->username.'/', ['username' => $user->username, 'displayName' => 'Manager', 'phone' => $user->phone, 'role' => 'manager', 'permissions' => ['categories' => ['food']]], $headers)->assertOk()->assertJsonPath('permissions.categories', ['food']);
        $reset = $this->postJson('/api/admin/users/'.$user->username.'/reset-password/', [], $headers)->assertOk();
        $this->postJson('/api/auth/login', ['username' => $user->username, 'password' => $reset->json('newPassword')])->assertOk()->assertJsonPath('user.mustChangePassword', true);
    }

    public function test_guest_cannot_access_private_routes(): void
    {
        $this->getJson('/api/auth/me/')->assertUnauthorized();
        $this->postJson('/api/locations/', ['name' => 'Forbidden'])->assertUnauthorized();
        $this->getJson('/api/favorites/')->assertUnauthorized();
        $this->getJson('/api/locations/')->assertOk();
    }

    public function test_tokens_expired_tampered_and_refresh_cannot_authenticate(): void
    {
        $pair = app(Tokens::class)->issue($this->user());
        $this->getJson('/api/auth/me', ['Authorization' => 'Bearer '.$pair['refresh']])->assertUnauthorized();
        $parts = explode('.', $pair['access']);
        $parts[1] = rtrim(strtr(base64_encode('{"role":"admin"}'), '+/', '-_'), '=');
        $this->getJson('/api/auth/me', ['Authorization' => 'Bearer '.implode('.', $parts)])->assertUnauthorized();
        $this->travel(61)->minutes();
        JWT::$timestamp = now()->timestamp;
        try {
            $this->getJson('/api/auth/me', ['Authorization' => 'Bearer '.$pair['access']])->assertUnauthorized();
        } finally {
            JWT::$timestamp = null;
        }
    }

    public function test_manager_permissions_and_location_contract(): void
    {
        $manager = $this->user('manager', ['categories' => ['food']]);
        $headers = $this->headers($manager);
        $this->postJson('/api/locations/', ['name' => 'Quán ăn', 'category' => 'food', 'lat' => '', 'lng' => '', 'gallery' => ['https://example.test/a.jpg']], $headers)
            ->assertCreated()->assertJsonPath('lat', null)->assertJsonPath('gallery.0.alt', '')->assertJsonStructure(['id', 'isActive', 'createdAt', 'imageSourceUrl']);
        $this->postJson('/api/locations/', ['name' => 'Di tích', 'category' => 'tourism'], $headers)->assertForbidden();
        $outside = $this->location();
        $this->putJson('/api/locations/'.$outside->id.'/', ['name' => 'Changed'], $headers)->assertForbidden();
        $inside = $this->location('food-place', 'food');
        $this->putJson('/api/locations/'.$inside->id.'/', ['category' => 'tourism'], $headers)->assertForbidden();
        $this->patchJson('/api/locations/'.$inside->id.'/', ['notes' => 'Updated'], $headers)->assertOk()->assertJsonPath('notes', 'Updated');
        $this->getJson('/api/locations/?category=food&q=Quán')->assertOk()->assertJsonPath('count', 1);
        $this->deleteJson('/api/locations/'.$inside->id.'/', [], $headers)->assertOk();
    }

    public function test_group_permission_and_media_validation(): void
    {
        $location = $this->location();
        $location->update(['group' => 'managed']);
        $headers = $this->headers($this->user('manager', ['groups' => ['managed']]));
        $this->putJson('/api/locations/place', ['name' => 'Changed'], $headers)->assertOk();
        $this->putJson('/api/locations/place', ['gallery' => [['url' => 'blob:pending']]], $headers)->assertStatus(400);
        $this->putJson('/api/locations/place', ['category' => 'unknown'], $headers)->assertStatus(400);
        $this->postJson('/api/categories', ['key' => 'new'], $headers)->assertForbidden();
    }

    public function test_review_upsert_moderation_and_replies(): void
    {
        $this->location();
        $user = $this->user();
        $headers = $this->headers($user);
        $review = $this->postJson('/api/locations/place/reviews/', ['rating' => 5, 'comment' => 'Hay'], $headers)->assertCreated()->assertJsonPath('username', $user->username)->json('id');
        $this->postJson('/api/locations/place/reviews/', ['rating' => 4], $headers)->assertOk();
        $this->getJson('/api/locations/place/reviews/')->assertOk()->assertJsonPath('count', 1)->assertJsonPath('results.0.rating', 4);
        $reply = $this->postJson('/api/reviews/'.$review.'/replies/', ['comment' => 'Cảm ơn'], $headers)->assertCreated()->json('id');
        $outsider = $this->headers($this->user());
        $this->deleteJson('/api/reviews/'.$review.'/replies/'.$reply.'/', [], $outsider)->assertForbidden();
        $this->deleteJson('/api/reviews/'.$review.'/replies/'.$reply.'/', [], $headers)->assertOk();
        $this->deleteJson('/api/reviews/'.$review.'/', [], $headers)->assertForbidden();
        $this->deleteJson('/api/reviews/'.$review.'/', [], $this->headers($this->user('manager', ['categories' => ['tourism']])))->assertOk();
    }

    public function test_review_listing_is_scoped_to_manager(): void
    {
        $this->location();
        $this->location('other', 'food');
        $writer = $this->headers($this->user());
        foreach (['place', 'other'] as $id) {
            $this->postJson('/api/locations/'.$id.'/reviews', ['rating' => 5], $writer)->assertCreated();
        }
        $this->getJson('/api/reviews', $this->headers($this->user('manager', ['categories' => ['food']])))->assertOk()->assertJsonPath('count', 1)->assertJsonPath('results.0.locationId', 'other');
        $this->getJson('/api/reviews', $this->headers($this->user('manager')))->assertOk()->assertJsonPath('count', 0);
    }

    public function test_favorites_history_and_reminder_ownership(): void
    {
        $this->location();
        $headers = $this->headers($this->user());
        $other = $this->headers($this->user());
        $this->postJson('/api/favorites/toggle/', ['locationId' => 'place'], $headers)->assertCreated()->assertJsonPath('favorited', true);
        $this->getJson('/api/favorites/', $headers)->assertOk()->assertJsonPath('count', 1);
        $this->getJson('/api/favorites/', $other)->assertOk()->assertJsonPath('count', 0);
        $this->postJson('/api/favorites/toggle/', ['locationId' => 'place'], $headers)->assertOk()->assertJsonPath('favorited', false);
        $history = $this->postJson('/api/travel/history/', ['locationId' => 'place', 'action' => 'view'], $headers)->assertCreated()->assertJsonPath('viewCount', 1)->json('id');
        $this->postJson('/api/travel/history/', ['locationId' => 'place', 'action' => 'view'], $headers)->assertOk()->assertJsonPath('viewCount', 2);
        $this->postJson('/api/travel/history/', ['locationId' => 'place', 'action' => 'visited'], $headers)->assertOk()->assertJsonStructure(['visitedAt']);
        $this->postJson('/api/travel/history/', ['locationId' => 'place', 'action' => 'unvisit'], $headers)->assertOk()->assertJsonPath('visitedAt', null);
        $this->deleteJson('/api/travel/history/'.$history, [], $other)->assertNotFound();
        $reminder = $this->postJson('/api/travel/reminders/', ['locationId' => 'place', 'scheduledAt' => '2026-12-01T10:00:00+07:00', 'note' => 'Đi chơi'], $headers)->assertCreated()->json('id');
        $this->patchJson('/api/travel/reminders/'.$reminder.'/', ['completedAt' => '2026-12-01T11:00:00Z'], $other)->assertNotFound();
        $this->patchJson('/api/travel/reminders/'.$reminder.'/', ['completedAt' => '2026-12-01T11:00:00Z'], $headers)->assertOk();
        $this->getJson('/api/travel/history/', $headers)->assertOk()->assertJsonPath('count', 1);
        $this->getJson('/api/travel/reminders/', $headers)->assertOk()->assertJsonPath('count', 1);
    }

    public function test_analytics_and_site_settings(): void
    {
        $this->location();
        $this->location('food', 'food');
        foreach (['place', 'food'] as $id) {
            $this->postJson('/api/analytics/events/', ['type' => 'location_view', 'visitorId' => 'v', 'sessionId' => 's', 'locationId' => $id])->assertCreated();
        }
        $this->getJson('/api/analytics/events/', $this->headers($this->user('manager', ['categories' => ['food']])))->assertOk()->assertJsonPath('count', 1);
        $admin = $this->headers($this->user('admin'));
        $this->putJson('/api/site-config/', ['heroIntervalMs' => 6000, 'footer' => ['phone' => '0123456789']], $admin)->assertOk()->assertJsonPath('footer.phone', '0123456789')->assertJsonPath('heroIntervalMs', 6000);
        $this->getJson('/api/site-config/')->assertOk()->assertJsonStructure(['heroSlides', 'heroIntervalMs', 'footer' => ['orgName', 'phone']]);
        $this->putJson('/api/site-config/', ['heroIntervalMs' => 500], $admin)->assertStatus(400);
        $this->postJson('/api/site-config/reset/', [], $admin)->assertOk()->assertJsonPath('heroIntervalMs', 5200);
        $this->deleteJson('/api/analytics/events/', [], $admin)->assertOk()->assertJsonPath('deleted', 2);
    }

    public function test_media_discard_keeps_attached_and_checks_owner(): void
    {
        Storage::fake('r2');
        $user = $this->user();
        $headers = $this->headers($user);
        $pending = $this->asset('pending', $user);
        $attached = $this->asset('attached', $user);
        $other = $this->asset('pending', $this->user());
        $this->postJson('/api/media/discard/', ['urls' => [$pending->url, $attached->url, $other->url]], $headers)->assertOk()->assertJsonPath('deleted', 1);
        Storage::disk('r2')->assertMissing($pending->key);
        Storage::disk('r2')->assertExists($attached->key);
        Storage::disk('r2')->assertExists($other->key);
        $this->assertDatabaseHas('media_assets', ['id' => $pending->id, 'status' => 'deleted']);
    }

    public function test_media_replace_only_deletes_unreferenced_after_commit(): void
    {
        Storage::fake('r2');
        $old = $this->asset('attached');
        $new = $this->asset();
        $location = $this->location();
        $location->update(['image' => $old->url]);
        $admin = $this->headers($this->user('admin'));
        $this->putJson('/api/locations/place/', ['image' => $new->url], $admin)->assertOk();
        $this->assertDatabaseHas('media_assets', ['id' => $new->id, 'status' => 'attached']);
        $this->assertDatabaseHas('media_assets', ['id' => $old->id, 'status' => 'deleted']);
        Storage::disk('r2')->assertMissing($old->key);
        $shared = $this->asset('pending');
        $location->update(['image' => $shared->url]);
        $this->assertFalse(app(MediaLifecycle::class)->discard($shared->id));
        $this->assertDatabaseHas('media_assets', ['id' => $shared->id, 'status' => 'attached']);
    }

    public function test_deleted_media_cannot_be_attached(): void
    {
        Storage::fake('r2');
        $asset = $this->asset('deleted');
        $location = $this->location();
        $this->putJson('/api/locations/place/', ['image' => $asset->url], $this->headers($this->user('admin')))->assertStatus(400);
        $this->assertNull($location->fresh()->image);
    }

    public function test_import_and_reset_missing_seed_preserves_data(): void
    {
        config(['tourism.seed_locations' => database_path('seeders/missing-test-seed.json')]);
        $this->location();
        $admin = $this->headers($this->user('admin'));
        $this->postJson('/api/locations/import/', [['id' => 'new', 'name' => 'New', 'category' => 'food'], ['id' => 'bad']], $admin)->assertOk()->assertJsonPath('created', 1)->assertJsonCount(1, 'errors');
        $this->postJson('/api/locations/reset/', [], $admin)->assertNotFound();
        $this->assertDatabaseCount('locations', 2);
    }

    public function test_category_crud_and_seed_are_idempotent(): void
    {
        $headers = $this->headers($this->user('admin'));
        $this->postJson('/api/categories/seed/', [], $headers)->assertOk()->assertJsonPath('created', 0);
        $this->postJson('/api/categories/', ['key' => 'custom', 'label' => 'Custom'], $headers)->assertCreated();
        $this->putJson('/api/categories/custom/', ['shortLabel' => 'Short'], $headers)->assertOk()->assertJsonPath('shortLabel', 'Short');
        $this->getJson('/api/categories/')->assertOk()->assertJsonPath('count', 8);
        $this->deleteJson('/api/categories/custom/', [], $headers)->assertOk();
        $this->location();
        $this->deleteJson('/api/categories/tourism/', [], $headers)->assertStatus(400);
    }

    public function test_profile_and_password_change(): void
    {
        $user = $this->user();
        $headers = $this->headers($user);
        $this->putJson('/api/auth/profile/', ['displayName' => 'Đổi tên', 'phone' => $user->phone], $headers)->assertOk()->assertJsonPath('displayName', 'Đổi tên');
        $this->postJson('/api/auth/change-password/', ['currentPassword' => 'wrong', 'newPassword' => 'newpassword'], $headers)->assertStatus(400);
        $this->postJson('/api/auth/change-password/', ['currentPassword' => 'password123', 'newPassword' => 'newpassword'], $headers)->assertOk();
        $this->postJson('/api/auth/login/', ['username' => $user->username, 'password' => 'newpassword'])->assertOk();
    }

    public function test_cleanup_only_removes_expired_pending_and_orphaned(): void
    {
        Storage::fake('r2');
        $old = $this->asset();
        $old->update(['created_at' => now()->subDays(2)]);
        $recent = $this->asset();
        $orphan = $this->asset('orphaned');
        $attached = $this->asset('attached');
        $this->artisan('media:cleanup --dry-run')->expectsOutput('Eligible: 2')->assertSuccessful();
        $this->artisan('media:cleanup')->assertSuccessful();
        foreach ([$old, $orphan] as $asset) {
            Storage::disk('r2')->assertMissing($asset->key);
        }
        foreach ([$recent, $attached] as $asset) {
            Storage::disk('r2')->assertExists($asset->key);
        }
    }

    public function test_cors_preflight(): void
    {
        $this->options('/api/locations/', [], ['Origin' => 'http://localhost:5173', 'Access-Control-Request-Method' => 'POST', 'Access-Control-Request-Headers' => 'authorization,content-type'])
            ->assertSuccessful()->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173');
    }
}
