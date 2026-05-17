<?php

namespace App\Http\Controllers;

use App\Enums\AppLocale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Sets the authenticated user's preferred locale.
 *
 * Endpoint:  POST /locale
 * Payload:   { locale: 'en' | 'tw' | 'ga' | 'ee' }
 *
 * Persists to `users.locale` so notifications, payslip PDFs, and SMS pick
 * the same language even when the user isn't currently browsing.
 */
class LocaleController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'locale' => ['required', 'string', 'in:' . implode(',', AppLocale::codes())],
        ]);

        if ($user = $request->user()) {
            $user->forceFill(['locale' => $data['locale']])->save();
        }

        // Cookie for the immediate next request (covers logout flows that
        // still want the chosen language until login).
        cookie()->queue(cookie('locale', $data['locale'], minutes: 60 * 24 * 365));

        return back()->with('success', __('common.thank_you'));
    }
}
