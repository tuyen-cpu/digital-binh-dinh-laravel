<?php

return [
    'admin_username' => env('ADMIN_USERNAME', 'admin'),
    'admin_password' => env('ADMIN_PASSWORD', ''),
    'admin_display_name' => env('ADMIN_DISPLAY_NAME', 'Quản trị viên Bình Định'),
    'reset_password' => env('DEFAULT_RESET_PASSWORD', ''),
    'access_minutes' => (int) env('JWT_ACCESS_TOKEN_LIFETIME_MINUTES', 60),
    'refresh_days' => (int) env('JWT_REFRESH_TOKEN_LIFETIME_DAYS', 7),
    'seed_locations' => env('LOCATIONS_SEED_PATH', database_path('seeders/locations.json')),
    'image_max_bytes' => 10 * 1024 * 1024,
    'video_max_bytes' => 40 * 1024 * 1024,
];
