<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\VerifyEmailResponse as VerifyEmailResponseContract;

/**
 * Right after verifying their email, users land on Credits: accounts start with a
 * $0 balance and need (fake) credits before generating.
 */
class VerifyEmailResponse implements VerifyEmailResponseContract
{
    /**
     * @param  Request  $request
     */
    public function toResponse($request): JsonResponse|RedirectResponse
    {
        return $request->wantsJson()
            ? new JsonResponse('', 204)
            : redirect()->intended(route('credits.index').'?verified=1');
    }
}
