<?php

namespace App\Http\Controllers;

use App\Models\Favorite;
use App\Models\Location;
use App\Models\TravelHistory;
use App\Models\TravelReminder;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TravelController extends ApiController
{
    public function favorites(Request $request): JsonResponse
    {
        return $this->listing(Favorite::where('user_id', $request->user()->id)->latest());
    }

    public function toggle(Request $request): JsonResponse
    {
        $data = $request->validate(['locationId' => 'required|string']);
        Location::findOrFail($data['locationId']);

        return DB::transaction(function () use ($request, $data) {
            // Serialize toggles for this account, including when no favorite exists yet.
            User::whereKey($request->user()->id)->lockForUpdate()->first();
            $fav = Favorite::firstOrCreate(['user_id' => $request->user()->id, 'location_id' => $data['locationId']]);
            $created = $fav->wasRecentlyCreated;
            if (! $created) {
                $fav->delete();
            }

            return response()->json(['favorited' => $created, 'locationId' => $data['locationId']], $created ? 201 : 200);
        });
    }

    public function history(Request $request): JsonResponse
    {
        return $this->listing(TravelHistory::where('user_id', $request->user()->id)->orderByDesc('last_activity_at'));
    }

    public function record(Request $request): JsonResponse
    {
        abort_unless(in_array($request->user()->role, ['user', 'manager']), 403, 'Chỉ user/manager mới ghi nhận hành trình.');
        $data = $request->validate(['locationId' => 'required|string', 'action' => 'required|in:view,route,visited,unvisit']);
        Location::findOrFail($data['locationId']);

        return DB::transaction(function () use ($request, $data) {
            User::whereKey($request->user()->id)->lockForUpdate()->first();
            $entry = TravelHistory::firstOrCreate(['user_id' => $request->user()->id, 'location_id' => $data['locationId']], ['last_action' => $data['action'], 'view_count' => 0, 'route_count' => 0]);
            $created = $entry->wasRecentlyCreated;
            $action = $data['action'];
            if ($action === 'unvisit') {
                $entry->visited_at = null;
                if (! $created) {
                    $entry->last_action = $entry->view_count > 0 ? 'view' : ($entry->route_count > 0 ? 'route' : 'view');
                }
            } else {
                $entry->last_action = $action;
                if ($action === 'view') {
                    $entry->view_count++;
                }
                if ($action === 'route') {
                    $entry->route_count++;
                }
                if ($action === 'visited' && ! $entry->visited_at) {
                    $entry->visited_at = now();
                }
            }
            $entry->save();

            return response()->json($entry->fresh(), $created ? 201 : 200);
        });
    }

    public function destroyHistory(Request $request, string $id): JsonResponse
    {
        TravelHistory::where('user_id', $request->user()->id)->findOrFail($id)->delete();

        return $this->success();
    }

    public function reminders(Request $request): JsonResponse
    {
        return $this->listing(TravelReminder::where('user_id', $request->user()->id)->orderBy('scheduled_at'));
    }

    public function createReminder(Request $request): JsonResponse
    {
        abort_unless(in_array($request->user()->role, ['user', 'manager']), 403, 'Hãy đăng nhập để tạo lịch nhắc.');
        $data = $request->validate(['locationId' => 'required|string', 'scheduledAt' => 'required|date', 'note' => 'sometimes|nullable|string|max:300']);
        Location::findOrFail($data['locationId']);
        $data['note'] = $data['note'] ?? '';

        return response()->json(TravelReminder::create(['user_id' => $request->user()->id] + $this->snake($data))->fresh(), 201);
    }

    public function updateReminder(Request $request, string $id): JsonResponse
    {
        $item = TravelReminder::where('user_id', $request->user()->id)->findOrFail($id);
        $data = $request->validate(['completedAt' => 'sometimes|nullable|date', 'notifiedAt' => 'sometimes|nullable|date']);
        $item->update($this->snake($data));

        return response()->json($item);
    }

    public function destroyReminder(Request $request, string $id): JsonResponse
    {
        TravelReminder::where('user_id', $request->user()->id)->findOrFail($id)->delete();

        return $this->success();
    }
}
