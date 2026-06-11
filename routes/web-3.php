<?php

use App\Services\OctaneWorkerManager;

Route::get('/octane/reload', function (OctaneWorkerManager $manager) {

    if (!app()->environment('local')) {
        abort(403);
    }

    $ok = $manager->reload();

    return [
        'success' => $ok,
        'message' => $ok ? 'reload signal sent' : 'failed',
    ];
});

Route::get('/octane/stop', function (OctaneWorkerManager $manager) {

    if (!app()->environment('local')) {
        abort(403);
    }

    return [
        'success' => $manager->stop(),
    ];
});

Route::get('/octane/status', function (OctaneWorkerManager $manager) {

    return $manager->status();
});

Route::get('/octane/debug', function (OctaneWorkerManager $manager) {

    return [
        'env' => env('OCTANE_SERVER'),
        'status' => $manager->status(),
        'cwd' => getcwd(),
        'base_path' => base_path(),
        'artisan_exists' => file_exists(base_path('artisan')),
    ];
});
