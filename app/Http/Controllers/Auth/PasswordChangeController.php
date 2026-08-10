<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\UserAccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class PasswordChangeController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        if (! $request->user()->must_change_password) {
            return redirect()->route($request->user()->landingRouteName());
        }

        return view('auth.force-password-change');
    }

    public function update(Request $request, UserAccountService $accounts): RedirectResponse
    {
        if (! $request->user()->must_change_password) {
            return redirect()->route($request->user()->landingRouteName());
        }

        $validated = $request->validate([
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        $accounts->changePassword(
            $request->user(),
            $validated['password'],
            $request->session()->getId(),
            'password_changed',
        );

        return redirect()->route($request->user()->landingRouteName())->with('success', 'Password berhasil diubah.');
    }
}
