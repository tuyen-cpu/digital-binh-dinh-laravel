<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json(['service' => 'Binh Dinh Tourism API', 'framework' => 'Laravel', 'api' => '/api', 'health' => '/up']);
});
