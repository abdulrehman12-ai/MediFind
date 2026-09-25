<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Pharmacy\PharmacyRuleException;
use App\Staff\StaffInvitation;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class InvitationController extends Controller
{
    public function show(Request $request, StaffInvitation $invitations): View
    {
        $token = (string) $request->query('token', '');

        return view('invitations.accept', [
            'token' => $token,
            'mode' => $token === '' ? 'invalid' : $invitations->preview($token),
            'error' => null,
        ]);
    }

    public function store(Request $request, StaffInvitation $invitations): View
    {
        $token = (string) $request->input('token', '');
        $mode = $token === '' ? 'invalid' : $invitations->preview($token);

        if ($mode === 'invalid') {
            return view('invitations.accept', [
                'token' => '',
                'mode' => 'invalid',
                'error' => 'This invitation is no longer valid.',
            ]);
        }

        if ($mode === 'existing') {
            if ($request->filled('password') || $request->filled('password_confirmation')) {
                return view('invitations.accept', [
                    'token' => $token,
                    'mode' => 'existing',
                    'error' => 'This account already has a password. Sign in and confirm the invitation without entering a new one.',
                ]);
            }

            $actor = $request->user();

            if (! $actor instanceof User) {
                return view('invitations.accept', [
                    'token' => $token,
                    'mode' => 'existing',
                    'error' => 'Sign in with the invited staff account, then confirm this invitation.',
                ]);
            }

            try {
                $invitations->acceptAuthenticated($actor, $token);
            } catch (PharmacyRuleException $exception) {
                return view('invitations.accept', [
                    'token' => $token,
                    'mode' => 'existing',
                    'error' => $exception->getMessage(),
                ]);
            }

            return view('invitations.accepted', ['createdAccount' => false]);
        }

        $data = $request->validate([
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', 'min:12'],
        ]);

        try {
            $invitations->acceptNewAccount($data['token'], $data['password']);
        } catch (PharmacyRuleException $exception) {
            return view('invitations.accept', [
                'token' => '',
                'mode' => 'invalid',
                'error' => $exception->getMessage(),
            ]);
        }

        return view('invitations.accepted', ['createdAccount' => true]);
    }
}
