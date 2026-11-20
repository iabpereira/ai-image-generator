<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

test('requests forwarded as https by the reverse proxy are treated as secure', function () {
    Route::get('/_proxy-check', fn (Request $request) => [
        'secure' => $request->isSecure(),
        'url' => url('/'),
    ]);

    $response = $this->get('/_proxy-check', [
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-Host' => 'ai-image.example.com',
    ]);

    $response->assertOk()->assertExactJson([
        'secure' => true,
        'url' => 'https://ai-image.example.com',
    ]);
});
