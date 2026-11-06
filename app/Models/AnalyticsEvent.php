<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

class AnalyticsEvent extends ApiModel
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['rating' => 'integer'];
    }
}
