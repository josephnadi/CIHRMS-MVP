<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Rules\NotRecentPassword;
use App\Services\Auth\PasswordHistoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class PasswordController extends Controller
{
    /**
     * Update the user's password.
     */
    public function update(Request $request, PasswordHistoryService $history): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            // L6: reject any of the user's last 5 passwords.
            'password' => ['required', Password::defaults(), 'confirmed', new NotRecentPassword($request->user())],
        ]);

        $hash = Hash::make($validated['password']);
        $request->user()->update(['password' => $hash]);
        $history->record($request->user(), $hash);

        return back();
    }
}
