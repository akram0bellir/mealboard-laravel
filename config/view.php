<?php

return [
    'paths' => [resource_path('views')],

    'compiled' => env('VIEW_COMPILED_PATH', realpath(storage_path('framework/views'))),

    // Production (deploy\optimize.bat): the compiled views are made once by `artisan optimize`, so there is no
    // need to compare file dates on every render (each check is a disk access, slow on Windows).
    'check_cache_timestamps' => (bool) env('VIEW_CHECK_CACHE_TIMESTAMPS', true),
];
