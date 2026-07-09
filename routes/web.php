<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Public APK download. The pre-push hook on the mobile repo rsyncs the latest
// release build to storage/app/mobile/kadi.apk, which this route serves.
Route::get('/download', function () {
    $path = storage_path('app/mobile/kadi.apk');
    abort_unless(is_file($path), 404, 'The Kadi app is not available yet.');

    return response()->download($path, 'Kadi.apk', [
        'Content-Type' => 'application/vnd.android.package-archive',
    ]);
})->name('download.apk');
