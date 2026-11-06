<?php

namespace App\Console\Commands;

use App\Models\MediaAsset;
use App\Services\MediaLifecycle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupMedia extends Command
{
    protected $signature = 'media:cleanup {--hours=24} {--dry-run}';

    protected $description = 'Dọn media pending quá hạn và thử lại media orphaned';

    public function handle(MediaLifecycle $media): int
    {
        if (! is_numeric($this->option('hours')) || (float) $this->option('hours') < 0) {
            $this->error('--hours phải là số không âm.');

            return self::FAILURE;
        }
        $query = MediaAsset::where(fn ($q) => $q->where('status', 'orphaned')->orWhere(fn ($q) => $q->where('status', 'pending')->where('created_at', '<=', now()->subSeconds((int) ((float) $this->option('hours') * 3600)))));
        if ($this->option('dry-run')) {
            $this->info('Eligible: '.$query->count());

            return self::SUCCESS;
        }
        if (! $media->configured()) {
            $this->error('Chưa cấu hình R2.');

            return self::FAILURE;
        }
        $deleted = 0;
        $failed = 0;
        foreach ($query->cursor() as $asset) {
            try {
                $deleted += (int) $media->discard($asset->id, $asset->status);
            } catch (\Throwable $e) {
                report($e);
                $failed++;
            }
        }
        DB::table('token_sessions')->where('expires_at', '<', now())->delete();
        $this->info("Deleted: $deleted; failed: $failed");

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
