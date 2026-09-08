<?php

use Illuminate\Support\Facades\Route;

/*
|─────────────────────────────────────────────────────────────────────
| Web Routes — SmartHome AI
|─────────────────────────────────────────────────────────────────────
*/

// Halaman utama → Chat Interface
Route::get('/', function () {
    return view('smarthome.index');
})->name('smarthome.index');
