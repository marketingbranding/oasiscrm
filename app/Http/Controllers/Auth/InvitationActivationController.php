<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AcceptInvitationRequest;
use App\Models\UserInvitation;
use App\Services\UserInvitationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class InvitationActivationController extends Controller
{
    public function show(string $token, UserInvitationService $invitations): View
    {
        $invitation = $invitations->findByToken($token);

        return view('auth.activate-invitation', [
            'token' => $token,
            'invitation' => $invitation,
            'state' => $this->state($invitation),
        ]);
    }

    public function store(AcceptInvitationRequest $request, string $token, UserInvitationService $invitations): RedirectResponse
    {
        try {
            $user = $invitations->accept($token, $request->string('password')->toString());
        } catch (\DomainException) {
            return redirect()->route('invitations.show', ['token' => $token])
                ->withErrors(['invitation' => 'Undangan ini tidak dapat digunakan lagi.']);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return redirect()->route($user->landingRouteName())
            ->with('success', 'Akun OASIS berhasil diaktifkan.');
    }

    private function state(?UserInvitation $invitation): string
    {
        if (! $invitation) {
            return 'invalid';
        }
        if ($invitation->accepted_at) {
            return 'accepted';
        }
        if ($invitation->revoked_at) {
            $hasReplacement = UserInvitation::where('user_id', $invitation->user_id)
                ->where('id', '>', $invitation->id)
                ->exists();

            return $hasReplacement ? 'superseded' : 'revoked';
        }
        if ($invitation->expires_at->isPast()) {
            return 'expired';
        }

        return 'valid';
    }
}
