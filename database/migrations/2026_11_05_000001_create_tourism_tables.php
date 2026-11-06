<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('name')->default('')->change();
            $t->string('email')->nullable()->change();
            $t->string('username', 40)->unique();
            $t->string('display_name', 150)->default('');
            $t->string('phone', 20)->nullable()->unique();
            $t->string('role', 10)->default('user');
            $t->text('avatar')->nullable();
            $t->boolean('must_change_password')->default(false);
            $t->timestamp('password_changed_at')->nullable();
            $t->timestamp('password_reset_at')->nullable();
            $t->json('permissions')->nullable();
        });
        Schema::create('categories', function (Blueprint $t) {
            $t->string('key', 50)->primary();
            $t->string('label', 100);
            $t->string('short_label', 50);
            $t->string('emoji', 20)->default('📍');
            $t->text('description')->default('');
            $t->string('marker', 20)->default('#1d4ed8');
            $t->unsignedSmallInteger('order')->default(99);
            $t->boolean('is_active')->default(true);
            foreach (['suggested_groups', 'suggested_subgroups', 'suggested_keywords'] as $field) {
                $t->json($field);
            }
        });
        Schema::create('locations', function (Blueprint $t) {
            $t->string('id', 200)->primary();
            $t->string('name', 300);
            $t->string('category', 50)->default('utility')->index();
            $t->string('group', 200)->default('')->index();
            $t->string('subgroup', 200)->default('')->index();
            $t->text('address')->default('');
            $t->double('lat')->nullable();
            $t->double('lng')->nullable();
            foreach (['phone', 'zalo_url', 'website', 'email', 'facebook', 'hours', 'keywords', 'heritage_status', 'image', 'image_alt', 'image_source_url', 'image_source_name'] as $field) {
                $t->text($field)->nullable();
            }
            $t->text('description')->default('');
            $t->text('notes')->default('');
            foreach (['gallery', 'panoramas', 'videos'] as $field) {
                $t->json($field);
            }
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('reviews', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('location_id', 200);
            $t->foreign('location_id')->references('id')->on('locations')->cascadeOnDelete();
            $t->unsignedTinyInteger('rating');
            $t->text('comment')->default('');
            $t->timestamps();
            $t->unique(['user_id', 'location_id']);
        });
        Schema::create('review_replies', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('review_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->text('comment');
            $t->timestamp('created_at')->useCurrent();
        });
        Schema::create('favorites', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('location_id', 200);
            $t->foreign('location_id')->references('id')->on('locations')->cascadeOnDelete();
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['user_id', 'location_id']);
        });
        Schema::create('travel_history', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('location_id', 200);
            $t->foreign('location_id')->references('id')->on('locations')->cascadeOnDelete();
            $t->timestamp('first_seen_at');
            $t->timestamp('last_activity_at');
            $t->string('last_action', 10)->default('view');
            $t->unsignedInteger('view_count')->default(0);
            $t->unsignedInteger('route_count')->default(0);
            $t->timestamp('visited_at')->nullable();
            $t->unique(['user_id', 'location_id']);
        });
        Schema::create('travel_reminders', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('location_id', 200);
            $t->foreign('location_id')->references('id')->on('locations')->cascadeOnDelete();
            $t->timestamp('scheduled_at');
            $t->text('note')->default('');
            $t->timestamp('created_at')->useCurrent();
            $t->timestamp('completed_at')->nullable();
            $t->timestamp('notified_at')->nullable();
        });
        Schema::create('analytics_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('type', 50)->index();
            $t->string('visitor_id', 100);
            $t->string('session_id', 100);
            $t->string('account', 40)->default('')->index();
            $t->string('role', 10)->default('guest');
            foreach (['location_id' => 200, 'location_name' => 300, 'category' => 50, 'path' => 500, 'section' => 200] as $field => $size) {
                $t->string($field, $size)->nullable();
            }
            $t->integer('rating')->nullable();
            $t->timestamp('created_at')->useCurrent()->index();
        });
        Schema::create('site_settings', function (Blueprint $t) {
            $t->id();
            $t->json('hero_slides');
            $t->unsignedInteger('hero_interval_ms')->default(5200);
            $t->json('footer');
        });
        Schema::create('media_assets', function (Blueprint $t) {
            $t->id();
            $t->string('key', 500)->unique();
            $t->text('url');
            $t->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('status', 10)->default('pending');
            $t->timestamp('created_at')->useCurrent();
            $t->index(['status', 'created_at']);
        });
        Schema::create('token_sessions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->timestamp('expires_at');
            $t->timestamp('revoked_at')->nullable();
        });
    }

    public function down(): void
    {
        foreach (['token_sessions', 'media_assets', 'site_settings', 'analytics_events', 'travel_reminders', 'travel_history', 'favorites', 'review_replies', 'reviews', 'locations', 'categories'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['username', 'display_name', 'phone', 'role', 'avatar', 'must_change_password', 'password_changed_at', 'password_reset_at', 'permissions']));
    }
};
