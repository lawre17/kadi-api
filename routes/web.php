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

// Android App Links verification. Lets the Kadi app claim /join/* links so
// tapping one opens the app directly (must match the APK's signing cert).
Route::get('/.well-known/assetlinks.json', function () {
    return response()->json([
        [
            'relation' => ['delegate_permission/common.handle_all_urls'],
            'target' => [
                'namespace' => 'android_app',
                'package_name' => 'com.lawre.kadi',
                'sha256_cert_fingerprints' => [
                    'FA:C6:17:45:DC:09:03:78:6F:B9:ED:E6:2A:96:2B:39:9F:73:48:F0:BB:6F:89:9B:83:32:66:75:91:03:3B:9C',
                ],
            ],
        ],
    ]);
});

// Smart join link. On a device with Kadi installed + the App Link verified,
// Android opens the app straight to this room instead of loading this page.
// Otherwise it shows the code and a download button.
Route::get('/join/{code}', function (string $code) {
    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));

    return response()->view('join', ['code' => $code]);
})->where('code', '[A-Za-z0-9]{4,10}')->name('join');
