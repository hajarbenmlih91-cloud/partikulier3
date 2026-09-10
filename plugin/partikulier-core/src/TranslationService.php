<?php
declare(strict_types=1);

namespace Partikulier\Core;

final class TranslationService
{
    public const LOCALES = ['fr', 'en', 'ar'];

    public function normalizeLocale(string $locale): string
    {
        $locale = strtolower(str_replace('_', '-', trim($locale)));
        if (str_starts_with($locale, 'fr')) return 'fr';
        if (str_starts_with($locale, 'en')) return 'en';
        if (str_starts_with($locale, 'ar')) return 'ar';
        return 'fr';
    }

    public function isSupported(string $locale): bool
    {
        return in_array($this->normalizeLocale($locale), self::LOCALES, true);
    }
}
