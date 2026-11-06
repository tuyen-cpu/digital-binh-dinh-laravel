<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Location;
use App\Services\Access;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CategoryController extends ApiController
{
    public function index(): JsonResponse
    {
        return $this->listing(Category::where('is_active', true)->orderBy('order')->orderBy('key'));
    }

    public function show(string $key): JsonResponse
    {
        return response()->json(Category::findOrFail($key));
    }

    private function rules(): array
    {
        return ['label' => 'sometimes|required|string|max:100', 'shortLabel' => 'sometimes|required|string|max:50', 'emoji' => 'sometimes|required|string|max:20',
            'description' => 'sometimes|nullable|string', 'marker' => 'sometimes|required|string|max:20', 'order' => 'sometimes|integer|min:0|max:65535', 'isActive' => 'sometimes|boolean',
            'suggestedGroups' => 'sometimes|array', 'suggestedSubgroups' => 'sometimes|array', 'suggestedKeywords' => 'sometimes|array',
            'suggestedGroups.*' => 'string', 'suggestedSubgroups.*' => 'string', 'suggestedKeywords.*' => 'string'];
    }

    public function store(Request $request): JsonResponse
    {
        Access::admin($request->user());
        if (is_string($request->input('key'))) {
            $request->merge(['key' => str_replace([' ', '-'], '_', strtolower(trim($request->input('key'))))]);
        }
        $data = $request->validate(['key' => 'required|string|max:50|unique:categories,key'] + $this->rules());
        $key = $data['key'];
        $data += ['label' => $key, 'shortLabel' => $key, 'emoji' => '📍', 'description' => '', 'marker' => '#1d4ed8', 'order' => 99, 'isActive' => true, 'suggestedGroups' => [], 'suggestedSubgroups' => [], 'suggestedKeywords' => []];

        return response()->json(Category::create($this->snake($data)), 201);
    }

    public function update(Request $request, string $key): JsonResponse
    {
        Access::admin($request->user());
        $cat = Category::findOrFail($key);
        $cat->update($this->snake($request->validate($this->rules())));

        return response()->json($cat);
    }

    public function destroy(Request $request, string $key): JsonResponse
    {
        Access::admin($request->user());
        $cat = Category::findOrFail($key);
        abort_if(Location::where('category', $key)->exists(), 400, 'Danh mục vẫn còn địa điểm.');
        $cat->delete();

        return $this->success();
    }

    public function seed(Request $request): JsonResponse
    {
        Access::admin($request->user());
        $created = DB::transaction(function () {
            $count = 0;
            foreach (json_decode(file_get_contents(database_path('seeders/categories.json')), true) as $data) {
                $data['is_active'] = true;
                $cat = Category::firstOrCreate(['key' => $data['key']], $data);
                if ($cat->wasRecentlyCreated) {
                    $count++;
                } else {
                    $cat->update(array_intersect_key($data, array_flip(['suggested_groups', 'suggested_subgroups', 'suggested_keywords'])));
                }
            }

            return $count;
        });

        return response()->json(['success' => true, 'created' => $created, 'total' => Category::count()]);
    }
}
