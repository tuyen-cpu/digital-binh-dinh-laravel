<?php

namespace App\Models;

class Location extends ApiModel
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $attributes = ['category' => 'utility', 'group' => '', 'subgroup' => '', 'address' => '', 'description' => '', 'notes' => '', 'gallery' => '[]', 'panoramas' => '[]', 'videos' => '[]', 'is_active' => true];

    protected function casts(): array
    {
        return ['gallery' => 'array', 'panoramas' => 'array', 'videos' => 'array', 'is_active' => 'boolean', 'lat' => 'float', 'lng' => 'float'];
    }
}
