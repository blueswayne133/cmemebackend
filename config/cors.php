<?php

return [
    'paths' => [
        'api/*', 
        'sanctum/csrf-cookie', 
        'admin/*',
        'admin',
        'login',
        'logout', 
        'register',
        'user'
    ],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://localhost:5173',
        'http://127.0.0.1:5173',
        'https://www.cmeme.app',  // Your frontend domain
        'https://cmeme.app'       // Also allow without www
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,
];