<?php

namespace App\Http\Controllers;

use App\Models\SiteSetting;
use App\Services\Access;
use App\Services\MediaLifecycle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SiteConfigController extends ApiController
{
    public function show(): JsonResponse
    {
        return response()->json(SiteSetting::solo())->header('Cache-Control', 'no-store, no-cache, must-revalidate')->header('Pragma', 'no-cache');
    }

    public function update(Request $request, MediaLifecycle $media): JsonResponse
    {
        Access::admin($request->user());
        $rules = ['heroSlides' => 'sometimes|array', 'heroSlides.*' => 'array', 'heroSlides.*.image' => ['sometimes', 'nullable', 'string', $media->notBlob()], 'heroIntervalMs' => 'sometimes|integer|min:1000', 'footer' => 'sometimes|array'];
        foreach (['orgName', 'description', 'address', 'phone', 'email', 'workingHours', 'copyright', 'logoUrl'] as $f) {
            $rules['footer.'.$f] = ['sometimes', 'nullable', 'string', 'max:500', $media->notBlob()];
        }
        $data = $request->validate($rules);

        return DB::transaction(function () use ($data, $media) {
            $id = SiteSetting::solo()->id;
            $site = SiteSetting::whereKey($id)->lockForUpdate()->firstOrFail();
            $old = [$site->hero_slides, $site->footer];
            $media->attach($data);
            if (array_key_exists('heroSlides', $data)) {
                $site->hero_slides = $data['heroSlides'];
            }
            if (array_key_exists('heroIntervalMs', $data)) {
                $site->hero_interval_ms = $data['heroIntervalMs'];
            }
            if (array_key_exists('footer', $data)) {
                $site->footer = array_merge($site->footer ?? [], array_intersect_key($data['footer'], SiteSetting::defaults()['footer']));
            }
            $site->save();
            $media->replace($old, [$site->hero_slides, $site->footer]);

            return response()->json($site);
        });
    }

    public function reset(Request $request, MediaLifecycle $media): JsonResponse
    {
        Access::admin($request->user());

        return DB::transaction(function () use ($media) {
            $site = SiteSetting::solo();
            $old = [$site->hero_slides, $site->footer];
            $site->update(SiteSetting::defaults());
            $media->replace($old, [$site->hero_slides, $site->footer]);

            return response()->json($site);
        });
    }
}
