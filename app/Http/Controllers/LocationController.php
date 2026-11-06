<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Location;
use App\Services\Access;
use App\Services\MediaLifecycle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class LocationController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $q = Location::where('is_active', true)->orderBy('name');
        if ($request->filled('category')) {
            $q->where('category', $request->input('category'));
        }
        if ($request->filled('q')) {
            $q->where(function ($q) use ($request) {
                foreach (['name', 'address', 'phone', 'keywords', 'group', 'subgroup'] as $f) {
                    $q->orWhere($f, 'like', '%'.$request->input('q').'%');
                }
            });
        }
        $items = $q->get()->map(fn ($l) => collect($l->toArray())->except(['imageSourceUrl', 'imageSourceName', 'isActive', 'createdAt', 'updatedAt'])->all());

        return response()->json(['count' => $items->count(), 'results' => $items]);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(Location::where('is_active', true)->findOrFail($id));
    }

    private function data(array $input, bool $create): array
    {
        $media = app(MediaLifecycle::class);
        if ($create && empty($input['id'])) {
            $input['id'] = Str::slug($input['name'] ?? 'dia-diem').'-'.Str::lower(Str::random(10));
        }
        $rules = ['name' => ($create ? 'required' : 'sometimes|required').'|string|max:300', 'category' => ['sometimes', 'required', 'string', 'max:50'],
            'group' => 'sometimes|nullable|string|max:200', 'subgroup' => 'sometimes|nullable|string|max:200', 'address' => 'sometimes|nullable|string',
            'lat' => 'sometimes|nullable|numeric|between:-90,90', 'lng' => 'sometimes|nullable|numeric|between:-180,180',
            'description' => 'sometimes|nullable|string', 'notes' => 'sometimes|nullable|string', 'isActive' => 'sometimes|boolean',
            'image' => ['sometimes', 'nullable', 'string', $media->notBlob()]];
        if ($create) {
            $rules['id'] = 'required|string|max:200';
        }
        $categories = Category::where('is_active', true)->pluck('key')->all();
        if ($categories) {
            $rules['category'][] = Rule::in($categories);
        }
        foreach (['phone' => 30, 'zaloUrl' => 500, 'website' => 500, 'email' => 254, 'facebook' => 500, 'hours' => 300, 'heritageStatus' => 300, 'imageAlt' => 300, 'imageSourceUrl' => 500, 'imageSourceName' => 300, 'keywords' => 100000] as $f => $max) {
            $rules[$f] = "sometimes|nullable|string|max:$max";
        }
        foreach (['gallery', 'panoramas', 'videos'] as $f) {
            $rules[$f] = 'sometimes|array';
        }
        $data = Validator::make($input, $rules)->validate();
        foreach (['gallery', 'panoramas', 'videos'] as $f) {
            if (isset($data[$f])) {
                $items = [];
                foreach ($data[$f] as $item) {
                    if (is_string($item)) {
                        $item = ['url' => $item, 'alt' => '', 'name' => ''];
                    }
                    if (! is_array($item) || empty($item['url'])) {
                        continue;
                    }
                    $items[] = Validator::make($item, ['url' => ['required', 'string', $media->notBlob()], 'alt' => 'sometimes|nullable|string', 'name' => 'sometimes|nullable|string'])->validate() + ['alt' => '', 'name' => ''];
                }
                $data[$f] = $items;
            }
        }
        foreach (['group', 'subgroup', 'address', 'description', 'notes'] as $f) {
            if (array_key_exists($f, $data) && $data[$f] === null) {
                $data[$f] = '';
            }
        }

        return $this->snake($data);
    }

    private function save(array $data, ?string $id = null): Location
    {
        return DB::transaction(function () use ($data, $id) {
            $location = $id ? Location::whereKey($id)->lockForUpdate()->firstOrFail() : new Location;
            $media = app(MediaLifecycle::class);
            $old = $media->locationUrls($location);
            $media->attach(array_intersect_key($data, array_flip(['image', 'gallery', 'panoramas', 'videos'])));
            $location->fill($data)->save();
            $media->replace($old, $media->locationUrls($location));

            return $location->fresh();
        });
    }

    public function store(Request $request): JsonResponse
    {
        Access::manager($request->user());
        $data = $this->data($request->all(), true);
        abort_unless(Access::category($request->user(), $data['category'] ?? 'utility'), 403, 'Bạn không có quyền thêm địa điểm vào nhóm này.');
        abort_if(Location::whereKey($data['id'])->exists(), 400, 'ID địa điểm đã tồn tại.');

        return response()->json($this->save($data), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $location = Location::where('is_active', true)->findOrFail($id);
        abort_unless(Access::location($request->user(), $location), 403, 'Bạn không có quyền sửa địa điểm này.');
        $data = $this->data($request->all(), false);
        if (isset($data['category']) && $data['category'] !== $location->category) {
            abort_unless(Access::category($request->user(), $data['category']), 403, 'Bạn không có quyền chuyển địa điểm sang nhóm này.');
        }

        return response()->json($this->save($data, $id));
    }

    public function destroy(Request $request, string $id, MediaLifecycle $media): JsonResponse
    {
        $location = Location::where('is_active', true)->findOrFail($id);
        abort_unless(Access::location($request->user(), $location), 403, 'Bạn không có quyền xóa địa điểm này.');
        DB::transaction(function () use ($location, $media) {
            $old = $media->locationUrls($location);
            $location->delete();
            $media->replace($old, []);
        });

        return $this->success();
    }

    public function import(Request $request): JsonResponse
    {
        Access::admin($request->user());
        $items = $request->json()->all();
        abort_unless(array_is_list($items), 400, 'Dữ liệu nhập phải là một mảng địa điểm.');

        return response()->json($this->importItems($items));
    }

    private function importItems(array $items): array
    {
        $created = 0;
        $updated = 0;
        $errors = [];
        foreach ($items as $i => $item) {
            try {
                abort_unless(is_array($item), 400, 'Địa điểm không hợp lệ.');
                $data = $this->data($item, true);
                $exists = Location::whereKey($data['id'])->exists();
                $this->save($data, $exists ? $data['id'] : null);
                if ($exists) {
                    $updated++;
                } else {
                    $created++;
                }
            } catch (ValidationException $e) {
                $errors[] = 'Dòng '.($i + 1).': '.json_encode($e->errors(), JSON_UNESCAPED_UNICODE);
            } catch (HttpException $e) {
                $errors[] = 'Dòng '.($i + 1).': '.$e->getMessage();
            }
        }

        return compact('created', 'updated', 'errors');
    }

    public function reset(Request $request, MediaLifecycle $media): JsonResponse
    {
        Access::admin($request->user());
        $path = config('tourism.seed_locations');
        abort_unless(is_file($path), 404, 'Chưa cấu hình file seed địa điểm.');
        $items = json_decode(file_get_contents($path), true);
        abort_unless(is_array($items) && array_is_list($items), 400, 'File seed không hợp lệ.');
        // Validate the entire seed before deleting anything.
        foreach ($items as $item) {
            $this->data($item, true);
        }
        $result = DB::transaction(function () use ($items, $media) {
            $old = [];
            foreach (Location::all() as $l) {
                $old = array_merge($old, $media->locationUrls($l));
            }
            Location::query()->delete();
            $result = $this->importItems($items);
            $media->replace($old, []);

            return $result;
        });

        return response()->json(['success' => true] + $result);
    }
}
