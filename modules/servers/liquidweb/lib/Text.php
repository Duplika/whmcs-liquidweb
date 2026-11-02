<?php
/** @author Duplika https://duplika.com/ | @copyright 2026 Duplika | @license MIT */
namespace Duplika\LiquidWeb;

/** Native WHMCS active locale and overrides; module-owned fallback dictionaries. */
final class Text
{
    public static function locale(): string
    {
        if (class_exists('Lang')) {
            $locale = \Lang::trans('locale');
            if (is_string($locale) && $locale !== '' && $locale !== 'locale') { return $locale; }
        }
        return 'en_GB';
    }

    private static function catalog(): array
    {
        static $catalogs = [];
        // Never use request/session values or locale text as a file path.
        $language = preg_match('/^es(?:[_-]|$)/i', self::locale()) ? 'spanish' : 'english';
        if (!isset($catalogs[$language])) {
            $catalogs[$language] = require __DIR__ . '/../lang/' . $language . '.php';
        }
        return $catalogs[$language];
    }

    public static function all(): array
    {
        $result = [];
        foreach (self::catalog() as $key => $fallback) { $result[$key] = self::t($key); }
        return $result;
    }

    public static function t(string $key): string
    {
        $nativeKey = 'liquidweb.' . $key;
        if (class_exists('Lang')) {
            $value = \Lang::trans($nativeKey);
            if (is_string($value) && $value !== '' && $value !== $nativeKey) { return $value; }
        }
        return self::catalog()[$key] ?? $key;
    }
}
