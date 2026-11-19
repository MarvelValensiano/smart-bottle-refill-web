<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/monitor', function () {
    $messages = \App\Models\MqttMessage::query()
        ->where('device_id', 'rack01')
        ->latest('id')
        ->limit(20)
        ->get();

    $totalMessages = \App\Models\MqttMessage::query()
        ->where('device_id', 'rack01')
        ->count();

    return view('monitor', compact('messages', 'totalMessages'));
});
