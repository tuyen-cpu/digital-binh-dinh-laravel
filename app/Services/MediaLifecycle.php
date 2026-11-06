<?php

namespace App\Services;

use App\Models\Location;
use App\Models\MediaAsset;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class MediaLifecycle
{
    public function urls(mixed $value): array
    {
        if (is_string($value)) {
            return [$value];
        }
        $urls = [];
        if (is_array($value)) {
            foreach ($value as $item) {
                $urls = array_merge($urls, $this->urls($item));
            }
        }

        return array_values(array_unique($urls));
    }

    public function locationUrls(Location $l): array
    {
        return $this->urls([$l->image, $l->gallery, $l->panoramas, $l->videos]);
    }

    public function notBlob(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (is_string($value) && str_starts_with(strtolower(trim($value)), 'blob:')) {
                $fail('Upload chưa hoàn tất.');
            }
        };
    }

    public function configured(): bool
    {
        foreach (['key', 'secret', 'bucket', 'endpoint', 'url'] as $key) {
            if (! config('filesystems.disks.r2.'.$key)) {
                return false;
            }
        }

        return true;
    }

    public function attach(mixed $value): void
    {
        $assets = MediaAsset::whereIn('url', $this->urls($value))->orderBy('id')->lockForUpdate()->get();
        if ($assets->contains('status', 'deleted')) {
            throw ValidationException::withMessages(['detail' => ['Ảnh tạm đã được dọn. Vui lòng chọn lại ảnh.']]);
        }
        MediaAsset::whereIn('id', $assets->pluck('id'))->update(['status' => 'attached']);
    }

    public function referenced(string $url): bool
    {
        if (User::where('avatar', $url)->exists()) {
            return true;
        }
        foreach (Location::cursor() as $l) {
            if (in_array($url, $this->locationUrls($l), true)) {
                return true;
            }
        }
        foreach (SiteSetting::cursor() as $s) {
            if (in_array($url, $this->urls([$s->hero_slides, $s->footer]), true)) {
                return true;
            }
        }

        return false;
    }

    public function replace(mixed $old, mixed $new): void
    {
        $base = rtrim(config('filesystems.disks.r2.url') ?? '', '/');
        if (! $base) {
            return;
        }
        $ids = [];
        foreach (array_diff($this->urls($old), $this->urls($new)) as $url) {
            if (! str_starts_with($url, $base.'/')) {
                continue;
            }
            $key = substr($url, strlen($base) + 1);
            if (! preg_match('~^(locations|gallery|panoramas|videos|slides|logos|avatars)/[a-f0-9]{32}\.[a-z0-9]+$~D', $key)) {
                continue;
            }
            $asset = MediaAsset::where('key', $key)->lockForUpdate()->first();
            if (! $asset || $asset->url !== $url || $asset->status === 'deleted') {
                continue;
            }
            $asset->update(['status' => 'orphaned']);
            $ids[] = $asset->id;
        }
        if ($ids) {
            DB::afterCommit(function () use ($ids) {
                if (! $this->configured()) {
                    return;
                }
                foreach ($ids as $id) {
                    try {
                        $this->discard($id, 'orphaned');
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
            });
        }
    }

    public function discard(int $id, string $status = 'pending', ?int $owner = null): bool
    {
        return DB::transaction(function () use ($id, $status, $owner) {
            $asset = MediaAsset::whereKey($id)->lockForUpdate()->first();
            if (! $asset || $asset->status !== $status || ($owner !== null && $asset->uploaded_by !== $owner)) {
                return false;
            }
            if ($this->referenced($asset->url)) {
                $asset->update(['status' => 'attached']);

                return false;
            }
            if (! Storage::disk('r2')->delete($asset->key)) {
                throw new \RuntimeException('Không xóa được media trên R2.');
            }
            $asset->update(['status' => 'deleted']);

            return true;
        });
    }
}
