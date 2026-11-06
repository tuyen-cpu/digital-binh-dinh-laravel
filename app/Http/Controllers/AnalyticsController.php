<?php

namespace App\Http\Controllers;

use App\Models\AnalyticsEvent;
use App\Models\Location;
use App\Services\Access;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnalyticsController extends ApiController
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['type' => 'required|string|max:50', 'visitorId' => 'required|string|max:100', 'sessionId' => 'required|string|max:100',
            'account' => 'sometimes|nullable|string|max:40', 'role' => 'sometimes|string|max:10', 'locationId' => 'sometimes|nullable|string|max:200', 'locationName' => 'sometimes|nullable|string|max:300',
            'category' => 'sometimes|nullable|string|max:50', 'path' => 'sometimes|nullable|string|max:500', 'section' => 'sometimes|nullable|string|max:200', 'rating' => 'sometimes|nullable|integer']);
        $data['account'] = $data['account'] ?? '';
        $data['role'] = $data['role'] ?? 'guest';
        $event = AnalyticsEvent::create($this->snake($data));

        return response()->json(['id' => $event->id], 201);
    }

    public function index(Request $request): JsonResponse
    {
        Access::manager($request->user());
        $query = AnalyticsEvent::latest();
        if ($request->user()->role === 'manager') {
            $query->whereIn('location_id', Access::scope(Location::query(), $request->user(), true)->select('id'));
        }
        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        return response()->json(['count' => $query->count(), 'results' => $query->limit(5000)->get()]);
    }

    public function destroy(Request $request): JsonResponse
    {
        Access::admin($request->user());
        $count = AnalyticsEvent::query()->delete();

        return response()->json(['success' => true, 'deleted' => $count]);
    }
}
