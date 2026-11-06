<?php

namespace App\Http\Controllers;

use App\Models\Location;
use App\Models\Review;
use App\Models\ReviewReply;
use App\Services\Access;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReviewController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        Access::manager($request->user());

        return $this->listing(Review::with(['user', 'replies.user'])->whereIn('location_id', Access::scope(Location::query(), $request->user())->select('id'))->latest());
    }

    public function locationIndex(string $id): JsonResponse
    {
        Location::findOrFail($id);

        return $this->listing(Review::with(['user', 'replies.user'])->where('location_id', $id)->latest());
    }

    public function store(Request $request, string $id): JsonResponse
    {
        abort_unless(in_array($request->user()->role, ['user', 'manager']), 403, 'Bạn phải đăng nhập tài khoản người dùng để đánh giá.');
        abort_unless(preg_match('/^(\+84|0)(3|5|7|8|9)\d{8}$/', $request->user()->phone ?? ''), 400, 'Tài khoản cần có số điện thoại hợp lệ.');
        Location::findOrFail($id);
        $data = $request->validate(['rating' => 'required|integer|between:1,5', 'comment' => 'sometimes|nullable|string|max:800']);
        $data['comment'] = $data['comment'] ?? '';
        $review = Review::updateOrCreate(['location_id' => $id, 'user_id' => $request->user()->id], $data);

        return response()->json($review, $review->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $review = Review::with('location')->findOrFail($id);
        abort_unless(Access::location($request->user(), $review->location), 403, 'Bạn không có quyền xóa đánh giá này.');
        $review->delete();

        return $this->success();
    }

    public function reply(Request $request, string $id): JsonResponse
    {
        Review::findOrFail($id);
        $data = $request->validate(['comment' => 'required|string|max:600']);

        return response()->json(ReviewReply::create(['review_id' => $id, 'user_id' => $request->user()->id] + $data), 201);
    }

    public function destroyReply(Request $request, string $id, string $reply): JsonResponse
    {
        $item = ReviewReply::with('review.location')->where('review_id', $id)->findOrFail($reply);
        abort_unless($item->user_id === $request->user()->id || Access::location($request->user(), $item->review->location), 403, 'Bạn không có quyền xóa phản hồi này.');
        $item->delete();

        return $this->success();
    }
}
