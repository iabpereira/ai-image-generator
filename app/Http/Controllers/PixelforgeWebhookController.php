<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessPixelforgeWebhook;
use App\Models\PixelforgeWebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PixelforgeWebhookController extends Controller
{
    /**
     * Acknowledge fast (Pixelforge waits at most 10 s) and process in a job. The
     * unique event_id turns a redelivered event into a counted no-op.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'id' => ['required', 'string', 'starts_with:evt_'],
            'type' => ['required', 'string'],
            'data' => ['present', 'array'],
        ]);

        $event = PixelforgeWebhookEvent::query()->createOrFirst(
            ['event_id' => $payload['id']],
            ['type' => $payload['type'], 'payload' => $request->json()->all()],
        );

        if (! $event->wasRecentlyCreated) {
            $event->increment('deliveries');
        }

        if ($event->processed_at === null) {
            ProcessPixelforgeWebhook::dispatch($event);
        }

        return response()->json(['received' => true]);
    }
}
