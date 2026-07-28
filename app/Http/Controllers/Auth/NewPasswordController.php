<?php

namespace App\Http\Controllers\Auth;

use App\Enums\AccountStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\UserAccountService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class NewPasswordController extends Controller
{
    /**
     * Display the password reset view.
     */
    public function create(Request $request): View
    {
        return view('auth.reset-password', ['request' => $request]);
    }

    /**
     * Handle an incoming new password request.
     *
     * @throws ValidationException
     */
    public function store(Request $request, UserAccountService $accounts): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $email = str($request->string('email')->toString())->trim()->lower()->toString();
        $request->merge(['email' => $email]);
        $resetUser = User::where('email', $email)->first();
        if ($resetUser && $resetUser->account_status !== AccountStatus::Active) {
            throw ValidationException::withMessages([
                'email' => 'Reset kata sandi hanya dapat diselesaikan untuk akun aktif. Hubungi administrator OASIS.',
            ]);
        }

        // Here we will attempt to reset the user's password. If it is successful we
        // will update the password on an actual user model and persist it to the
        // database. Otherwise we will parse the error and return the response.
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user) use ($request, $accounts) {
                if ($user->account_status !== AccountStatus::Active) {
                    throw ValidationException::withMessages(['email' => 'Akun ini tidak dapat mereset kata sandi.']);
                }

                $accounts->changePassword(
                    $user,
                    $request->string('password')->toString(),
                    $request->hasSession() ? $request->session()->getId() : null,
                    'password_reset_completed',
                );

                event(new PasswordReset($user));
            }
        );

        // If the password was successfully reset, we will redirect the user back to
        // the application's home authenticated view. If there is an error we can
        // redirect them back to where they came from with their error message.
        return $status == Password::PASSWORD_RESET
                    ? redirect()->route('login')->with('status', __($status))
                    : back()->withInput($request->only('email'))
                        ->withErrors(['email' => __($status)]);
    }
}
