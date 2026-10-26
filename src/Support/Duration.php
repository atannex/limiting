<?php

declare(strict_types=1);

namespace Atannex\Limiting\Support;

use Atannex\Limiting\Exceptions\InvalidLimit;
use DateInterval;

/**
 * Turns the ways a duration can be written into seconds:
 *
 *     90, 1.5, '90s', '500ms', '5 minutes', '1h', 'hour', '2 days', '1 week',
 *     new DateInterval('PT15M')
 */
final class Duration
{
    private const UNITS = [
        'ms' => 0.001,
        'millisecond' => 0.001,
        'milliseconds' => 0.001,
        's' => 1,
        'sec' => 1,
        'secs' => 1,
        'second' => 1,
        'seconds' => 1,
        'm' => 60,
        'min' => 60,
        'mins' => 60,
        'minute' => 60,
        'minutes' => 60,
        'h' => 3600,
        'hr' => 3600,
        'hrs' => 3600,
        'hour' => 3600,
        'hours' => 3600,
        'd' => 86400,
        'day' => 86400,
        'days' => 86400,
        'w' => 604800,
        'week' => 604800,
        'weeks' => 604800,
    ];

    public static function seconds(int|float|string|DateInterval $duration): float
    {
        if ($duration instanceof DateInterval) {
            $seconds = (float) ((new \DateTimeImmutable('@0'))->add($duration)->getTimestamp());
        } elseif (is_int($duration) || is_float($duration)) {
            $seconds = (float) $duration;
        } elseif (is_numeric($duration)) {
            $seconds = (float) $duration;
        } elseif (preg_match('/^\s*(\d+(?:\.\d+)?)?\s*([a-z]+)\s*$/i', $duration, $match) && isset(self::UNITS[strtolower($match[2])])) {
            $seconds = (float) ($match[1] !== '' ? $match[1] : 1) * self::UNITS[strtolower($match[2])];
        } else {
            throw new InvalidLimit("Unknown duration \"{$duration}\". Write it as seconds or like '5 minutes', '1h', '500ms'.");
        }

        if ($seconds <= 0) {
            throw new InvalidLimit('A duration must be longer than zero.');
        }

        return $seconds;
    }

    /** "60s", "5m", "1h30m": short labels for names and console output. */
    public static function label(float $seconds): string
    {
        if ($seconds < 1) {
            return round($seconds * 1000).'ms';
        }

        $parts = [];

        foreach (['d' => 86400, 'h' => 3600, 'm' => 60] as $unit => $size) {
            if ($seconds >= $size) {
                $parts[] = intdiv((int) $seconds, $size).$unit;
                $seconds = fmod($seconds, $size);
            }
        }

        if ($seconds > 0 || $parts === []) {
            $parts[] = rtrim(rtrim(number_format($seconds, 3, '.', ''), '0'), '.').'s';
        }

        return implode('', $parts);
    }
}
