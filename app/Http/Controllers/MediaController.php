<?php

namespace App\Http\Controllers;

use App\Models\Location;
use App\Models\MediaAsset;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\Access;
use App\Services\MediaLifecycle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class MediaController extends ApiController
{
    public function upload(Request $request, MediaLifecycle $media): JsonResponse
    {
        abort_unless($media->configured(), 503, 'Chưa cấu hình Cloudflare R2.');
        $data = $request->validate(['file' => 'required|file', 'folder' => 'sometimes|in:locations,gallery,panoramas,videos,slides,logos,avatars']);
        $folder = $data['folder'] ?? 'locations';
        $role = $request->user()->role;
        abort_if((in_array($folder, ['slides', 'logos']) && $role !== 'admin') || ($folder !== 'avatars' && ! in_array($role, ['admin', 'manager'])), 403, 'Bạn không có quyền upload vào thư mục này.');
        $file = $request->file('file');
        $mime = $file->getMimeType();
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/ogg' => 'ogv'];
        abort_unless(isset($extensions[$mime]), 400, 'Loại file không được phép.');
        $max = str_starts_with($mime, 'video/') ? config('tourism.video_max_bytes') : config('tourism.image_max_bytes');
        abort_if($file->getSize() > $max, 400, 'File vượt quá giới hạn cho phép.');
        $key = $folder.'/'.bin2hex(random_bytes(16)).'.'.$extensions[$mime];
        $url = rtrim(config('filesystems.disks.r2.url'), '/').'/'.$key;
        MediaAsset::create(['key' => $key, 'url' => $url, 'uploaded_by' => $request->user()->id, 'status' => 'pending']);
        $stream = fopen($file->getRealPath(), 'rb');
        try {
            if (! Storage::disk('r2')->put($key, $stream, ['ContentType' => $mime, 'CacheControl' => 'public, max-age=31536000'])) {
                throw new \RuntimeException('Upload failed');
            }
        } catch (\Throwable $e) {
            report($e);
            abort(502, 'Upload thất bại. Vui lòng thử lại.');
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return response()->json(compact('url', 'key'), 201);
    }

    public function discard(Request $request, MediaLifecycle $media): JsonResponse
    {
        $data = $request->validate(['urls' => 'required|array|max:100', 'urls.*' => 'string']);
        abort_unless($media->configured(), 503, 'Chưa cấu hình Cloudflare R2.');
        $ids = MediaAsset::whereIn('url', $data['urls'])->where('uploaded_by', $request->user()->id)->where('status', 'pending')->pluck('id');
        $deleted = 0;
        try {
            foreach ($ids as $id) {
                $deleted += (int) $media->discard($id, 'pending', $request->user()->id);
            }
        } catch (\Throwable $e) {
            report($e);
            abort(502, 'Chưa dọn được ảnh. Hệ thống sẽ thử lại sau.');
        }

        return response()->json(['deleted' => $deleted]);
    }

    public function destroy(Request $request, MediaLifecycle $media): JsonResponse
    {
        Access::manager($request->user());
        abort_unless($media->configured(), 503, 'Chưa cấu hình Cloudflare R2.');
        $data = $request->validate(['key' => 'sometimes|nullable|string', 'url' => 'sometimes|nullable|string']);
        $base = rtrim(config('filesystems.disks.r2.url'), '/');
        $key = $data['key'] ?? '';
        if (! $key && str_starts_with($data['url'] ?? '', $base.'/')) {
            $key = substr($data['url'], strlen($base) + 1);
        }
        abort_unless(preg_match('~^(locations|gallery|panoramas|videos|slides|logos|avatars)/[a-f0-9]{32}\.[a-z0-9]+$~D', $key), 400, 'Key hoặc URL file không hợp lệ.');
        $url = $base.'/'.$key;
        if ($request->user()->role !== 'admin') {
            $references = [];
            foreach (Location::cursor() as $l) {
                if (in_array($url, $media->locationUrls($l), true)) {
                    $references[] = $l;
                }
            }
            $siteReference = false;
            foreach (SiteSetting::cursor() as $s) {
                if (in_array($url, $media->urls([$s->hero_slides, $s->footer]), true)) {
                    $siteReference = true;
                }
            }
            abort_if(! $references || $siteReference || User::where('avatar', $url)->exists(), 403, 'Bạn không có quyền xóa file này.');
            foreach ($references as $l) {
                abort_unless(Access::location($request->user(), $l), 403, 'Bạn không có quyền xóa file này.');
            }
        }
        try {
            if (! Storage::disk('r2')->delete($key)) {
                throw new \RuntimeException('Delete failed');
            }
        } catch (\Throwable $e) {
            report($e);
            abort(502, 'Xóa file thất bại.');
        }
        MediaAsset::where('key', $key)->update(['status' => 'deleted']);

        return $this->success();
    }
}
