<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;

/**
 * Same answer whether or not the email has an account, so the forgot-password form cannot be used to find out
 * who is a customer. Bound for both the successful and the failed Fortify response.
 */
class PasswordResetLinkResponse implements FailedPasswordResetLinkRequestResponse, SuccessfulPasswordResetLinkRequestResponse
{
    public function __construct(protected string $status = '')
    {
    }

    public function toResponse($request)
    {
        $message = 'If an account exists for that email, a password reset link is on its way. Check your inbox.';

        return $request->wantsJson()
            ? new JsonResponse(['message' => $message], 200)
            : back()->with('status', $message);
    }
}
