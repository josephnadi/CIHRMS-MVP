<?php

namespace App\Enums;

/**
 * Supported UI / notification locales for CIHRMS.
 *
 *  - English (en)  — default
 *  - Twi      (tw) — Akan, the most widely spoken Ghanaian language
 *  - Ga       (ga) — Greater Accra
 *  - Ewe      (ee) — Volta region
 *
 * The set is deliberately small — adding a new language is a matter of
 * adding a case here, dropping `lang/{code}/*.php`, and a JS bundle.
 */
enum AppLocale: string
{
    case English = 'en';
    case Twi     = 'tw';
    case Ga      = 'ga';
    case Ewe     = 'ee';

    public function label(): string
    {
        return match ($this) {
            self::English => 'English',
            self::Twi     => 'Twi',
            self::Ga      => 'Ga',
            self::Ewe     => 'Ewe',
        };
    }

    /** Native-script name for the locale switcher menu. */
    public function nativeName(): string
    {
        return match ($this) {
            self::English => 'English',
            self::Twi     => 'Twi (Akan)',
            self::Ga      => 'Gã',
            self::Ewe     => 'Eʋegbe',
        };
    }

    /** ISO 639-1 ↔ Ghana convention. */
    public static function default(): self
    {
        return self::English;
    }

    public static function isSupported(?string $code): bool
    {
        if ($code === null) return false;
        return self::tryFrom($code) !== null;
    }

    /** All supported codes — handy for validation rules. */
    public static function codes(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
