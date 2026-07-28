<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\UserAccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class PasswordChangeController extends Controller
{
    public function show()
    {
        return view('auth.force-password-change');
    }

    public function update(Request $request, UserAccountService $accounts): RedirectResponse
    {
        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        $accounts->changePassword(
            $request->user(),
            $request->string('password')->toString(),
            $request->session()->getId(),
            'password_changed',
        );

        return redirect()->route($request->user()->landingRouteName())->with('success', 'Password berhasil diubah.');
    }
}
