<?php

namespace App\Models;

class Category extends ApiModel
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'order' => 'integer', 'suggested_groups' => 'array', 'suggested_subgroups' => 'array', 'suggested_keywords' => 'array'];
    }
}
