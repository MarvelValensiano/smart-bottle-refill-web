<?php

return [
    'host' => env('MQTT_HOST'),
    'port' => (int) env('MQTT_PORT', 8883),
    'username' => env('MQTT_USERNAME'),
    'password' => env('MQTT_PASSWORD'),
    'client_id' => env('MQTT_CLIENT_ID', 'smart-refill-laravel'),
    'ca_file' => storage_path('app/certs/emqxsl-ca.crt'),
];