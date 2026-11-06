<?php

namespace App\Models;

class SiteSetting extends ApiModel
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['hero_slides' => 'array', 'footer' => 'array', 'hero_interval_ms' => 'integer'];
    }

    public static function defaults(): array
    {
        return json_decode(file_get_contents(database_path('seeders/site-defaults.json')), true);
    }

    public static function solo(): self
    {
        return static::firstOrCreate(['id' => 1], static::defaults());
    }

    public function toArray(): array
    {
        return ['heroSlides' => $this->hero_slides, 'heroIntervalMs' => $this->hero_interval_ms, 'footer' => array_merge(static::defaults()['footer'], $this->footer ?? [])];
    }
}
