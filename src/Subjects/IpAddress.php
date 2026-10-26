<?php

declare(strict_types=1);

namespace Atannex\Limiting\Subjects;

/**
 * IP addresses as limiting subjects.
 *
 * One IPv6 customer usually holds a whole /64 (often a /56 or /48), so an
 * attacker can rotate through billions of addresses without leaving it.
 * Counting IPv6 per /64 by default closes that gap. IPv4-mapped IPv6
 * (::ffff:1.2.3.4) is read as the IPv4 address it is.
 */
final class IpAddress
{
    public static function normalize(string $ip, int $v4Prefix = 32, int $v6Prefix = 64): ?string
    {
        $binary = @inet_pton(trim($ip, "[] \t"));

        if ($binary === false) {
            return null;
        }

        if (strlen($binary) === 16 && str_starts_with($binary, str_repeat("\0", 10)."\xff\xff")) {
            $binary = substr($binary, 12);
        }

        $v4 = strlen($binary) === 4;
        $prefix = max(0, min($v4 ? 32 : 128, $v4 ? $v4Prefix : $v6Prefix));
        $masked = self::mask($binary, $prefix);
        $address = (string) inet_ntop($masked);

        return $prefix === ($v4 ? 32 : 128) ? $address : "{$address}/{$prefix}";
    }

    public static function isIp(string $value): bool
    {
        return filter_var(trim($value, '[]'), FILTER_VALIDATE_IP) !== false;
    }

    private static function mask(string $binary, int $prefix): string
    {
        $bytes = intdiv($prefix, 8);
        $bits = $prefix % 8;
        $out = substr($binary, 0, $bytes);

        if ($bits > 0) {
            $out .= chr(ord($binary[$bytes]) & (0xFF << (8 - $bits)) & 0xFF);
            $bytes++;
        }

        return str_pad($out, strlen($binary), "\0");
    }
}
