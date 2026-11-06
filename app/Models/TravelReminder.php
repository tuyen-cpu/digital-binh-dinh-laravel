<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

class TravelReminder extends ApiModel
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $hidden = ['user_id'];

    protected function casts(): array
    {
        return ['scheduled_at' => 'datetime', 'completed_at' => 'datetime', 'notified_at' => 'datetime'];
    }
}
