<?php

declare(strict_types=1);

namespace Atannex\Limiting\Subjects;

/**
 * Makes look-alike input count as one subject, so 'Bob@Example.com ',
 * 'bob+1@example.com' and the fullwidth 'ｂｏｂ@example.com' share a counter
 * instead of handing an attacker three of them.
 */
final class Normalizer
{
    public static function text(string $value): string
    {
        if (class_exists(\Normalizer::class)) {
            $value = \Normalizer::normalize($value, \Normalizer::FORM_KC) ?: $value;
        }

        $value = preg_replace('/[\x{200B}-\x{200D}\x{2060}\x{FEFF}\p{Cc}]/u', '', $value) ?? $value;

        return mb_strtolower(trim($value));
    }

    /**
     * Lowercase, drop "+tags", and drop dots in Gmail local parts (Gmail
     * ignores them). Other providers keep their dots: they may be significant.
     */
    public static function email(string $value): string
    {
        $value = self::text($value);
        $at = strrpos($value, '@');

        if ($at === false) {
            return $value;
        }

        $local = substr($value, 0, $at);
        $domain = rtrim(substr($value, $at + 1), '.');

        if (function_exists('idn_to_ascii')) {
            $domain = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $domain;
        }

        $local = explode('+', $local, 2)[0];

        if ($domain === 'googlemail.com') {
            $domain = 'gmail.com';
        }

        if ($domain === 'gmail.com') {
            $local = str_replace('.', '', $local);
        }

        return "{$local}@{$domain}";
    }
}
