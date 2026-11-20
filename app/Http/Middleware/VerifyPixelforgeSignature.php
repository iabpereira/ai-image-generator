<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies `Pixelforge-Signature: t=<unix>,v1=<hex hmac-sha256("{t}.{raw body}")>`.
 */
class VerifyPixelforgeSignature
{
    private const int TOLERANCE_SECONDS = 300;

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->hasValidSignature($request)) {
            return response()->json(['error' => 'invalid_signature'], 400);
        }

        return $next($request);
    }

    private function hasValidSignature(Request $request): bool
    {
        $secret = (string) config('services.pixelforge.webhook_secret');
        $parts = [];

        foreach (explode(',', (string) $request->header('Pixelforge-Signature')) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            $parts[$key] = $value;
        }

        $timestamp = $parts['t'] ?? '';
        $signature = $parts['v1'] ?? '';

        if ($secret === '' || ! ctype_digit($timestamp) || $signature === '') {
            return false;
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$request->getContent()}", $secret);

        return hash_equals($expected, $signature)
            && abs(time() - (int) $timestamp) <= self::TOLERANCE_SECONDS;
    }
}
