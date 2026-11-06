<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

class TravelHistory extends ApiModel
{
    use HasUuids;

    protected $table = 'travel_history';

    public const CREATED_AT = 'first_seen_at';

    public const UPDATED_AT = 'last_activity_at';

    protected $hidden = ['user_id'];

    protected function casts(): array
    {
        return ['visited_at' => 'datetime', 'view_count' => 'integer', 'route_count' => 'integer'];
    }
}
