<?php

namespace App\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

abstract class ApiController extends Controller
{
    protected function snake(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $result[Str::snake($key)] = $value;
        }

        return $result;
    }

    protected function listing(Builder $query): JsonResponse
    {
        $items = $query->get();

        return response()->json(['count' => $items->count(), 'results' => $items]);
    }

    protected function success(): JsonResponse
    {
        return response()->json(['success' => true]);
    }
}
