<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Review extends ApiModel
{
    use HasUuids;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function replies(): HasMany
    {
        return $this->hasMany(ReviewReply::class)->oldest();
    }

    public function toArray(): array
    {
        return ['id' => $this->id, 'locationId' => $this->location_id,
            'username' => $this->user->username, 'displayName' => $this->user->display_name,
            'rating' => (int) $this->rating, 'comment' => $this->comment, 'replies' => $this->replies->toArray(),
            'createdAt' => $this->created_at->toISOString(), 'updatedAt' => $this->updated_at->toISOString()];
    }
}
