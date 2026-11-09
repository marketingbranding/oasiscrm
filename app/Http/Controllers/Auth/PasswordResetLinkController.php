<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class PasswordResetLinkController extends Controller
{
    /**
     * Display the password reset link request view.
     */
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * Handle an incoming password reset link request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $email = Str::lower(trim($request->string('email')->toString()));
        $request->merge(['email' => $email]);
        $throttleKey = 'password-reset|'.$email.'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Terlalu banyak permintaan reset. Coba lagi beberapa saat lagi.',
            ]);
        }
        RateLimiter::hit($throttleKey, 60);

        try {
            $status = Password::sendResetLink(['email' => $email]);
        } catch (Throwable $exception) {
            logger()->warning('Password reset email delivery failed.', [
                'event' => 'password_reset_mail_failed',
                'exception' => $exception::class,
            ]);

            return back()->withInput(['email' => $email])
                ->withErrors(['email' => 'Email reset belum dapat dikirim. Periksa konfigurasi email atau hubungi administrator OASIS.']);
        }

        return $status == Password::RESET_LINK_SENT
                    ? back()->with('status', 'Jika email terdaftar dan aktif, tautan reset telah dikirim.')
                    : back()->withInput($request->only('email'))
                        ->withErrors(['email' => __($status)]);
    }
}
