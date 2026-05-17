<?php

namespace App\Services\I18n;

use App\Enums\AppLocale;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * Resolves the active locale for a request in this priority order:
 *
 *   1. ?locale= query parameter   — explicit override for a single request
 *   2. Authenticated user's `locale` column
 *   3. Accept-Language HTTP header — best-match against supported set
 *   4. config('i18n.default')     — the fallback
 *
 * The resolver also exposes `forUser()` so notification + payslip + SMS
 * pipelines can pick the right locale even outside an HTTP context.
 */
class LocaleResolver
{
    public function resolveFromRequest(Request $request): string
    {
        // 1. Query override
        $q = (string) $request->query('locale', '');
        if (AppLocale::isSupported($q)) return $q;

        // 2. User preference — read defensively so a User instance missing
        //    the `locale` column (e.g. a factory-built test user that didn't
        //    select it) doesn't trip Model::shouldBeStrict() in non-prod.
        $user = $request->user();
        if ($user instanceof User) {
            $locale = Arr::get($user->getAttributes(), 'locale');
            if (AppLocale::isSupported($locale)) return $locale;
        }

        // 3. Accept-Language
        $header = (string) $request->header('Accept-Language', '');
        if ($header !== '') {
            foreach ($this->parseAcceptLanguage($header) as $tag) {
                $primary = strtolower(substr($tag, 0, 2));
                if (AppLocale::isSupported($primary)) return $primary;
            }
        }

        // 4. Config default
        return (string) config('i18n.default', AppLocale::default()->value);
    }

    public function forUser(?User $user): string
    {
        if ($user) {
            $locale = Arr::get($user->getAttributes(), 'locale');
            if (AppLocale::isSupported($locale)) return $locale;
        }
        return (string) config('i18n.default', AppLocale::default()->value);
    }

    /**
     * @return array<int, string> tag list ordered by q-value (highest first)
     */
    private function parseAcceptLanguage(string $header): array
    {
        $entries = [];
        foreach (explode(',', $header) as $part) {
            $bits = array_map('trim', explode(';', $part));
            $tag  = $bits[0] ?? '';
            if ($tag === '') continue;
            $q = 1.0;
            foreach (array_slice($bits, 1) as $param) {
                if (str_starts_with($param, 'q=')) {
                    $q = (float) substr($param, 2);
                }
            }
            $entries[] = ['tag' => $tag, 'q' => $q];
        }
        usort($entries, fn ($a, $b) => $b['q'] <=> $a['q']);
        return array_column($entries, 'tag');
    }
}
