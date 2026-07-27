<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;

class VerifyEmailController extends Controller
{
    /**
     * Mark the authenticated user's email address as verified.
     */
    public function __invoke(EmailVerificationRequest $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return $this->redirectAfterVerification($request);
        }

        if ($request->user()->markEmailAsVerified()) {
            event(new Verified($request->user()));
        }

        return $this->redirectAfterVerification($request);
    }

    private function redirectAfterVerification(EmailVerificationRequest $request): RedirectResponse
    {
        if ($request->user()->isSales()) {
            return redirect()->route($request->user()->landingRouteName(), ['verified' => 1]);
        }

        return redirect()->intended(route('dashboard', ['verified' => 1], absolute: false));
    }
}
