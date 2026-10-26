<?php

declare(strict_types=1);

namespace Atannex\Limiting\Tests\Unit;

use Atannex\Limiting\Subjects\IpAddress;
use Atannex\Limiting\Subjects\Normalizer;
use Atannex\Limiting\Support\Duration;
use Atannex\Limiting\Support\KeyGenerator;
use PHPUnit\Framework\TestCase;

final class SubjectsTest extends TestCase
{
    public function test_ipv6_addresses_are_grouped_by_their_64(): void
    {
        $this->assertSame('2001:db8:1:2::/64', IpAddress::normalize('2001:db8:1:2:aaaa:bbbb:cccc:dddd'));
        $this->assertSame(IpAddress::normalize('2001:db8:1:2::1'), IpAddress::normalize('2001:db8:1:2:ffff::9'));
        $this->assertNotSame(IpAddress::normalize('2001:db8:1:2::1'), IpAddress::normalize('2001:db8:1:3::1'));
        $this->assertSame('2001:db8::/32', IpAddress::normalize('2001:db8:abcd::1', 32, 32));
    }

    public function test_ipv4_stays_exact_and_mapped_addresses_unwrap(): void
    {
        $this->assertSame('203.0.113.9', IpAddress::normalize('203.0.113.9'));
        $this->assertSame('203.0.113.9', IpAddress::normalize('::ffff:203.0.113.9'));
        $this->assertSame('203.0.113.0/24', IpAddress::normalize('203.0.113.9', 24));
        $this->assertSame('203.0.112.0/23', IpAddress::normalize('203.0.113.9', 23));
        $this->assertNull(IpAddress::normalize('not an ip'));
    }

    public function test_emails_that_reach_one_inbox_count_as_one(): void
    {
        $this->assertSame('bob@example.com', Normalizer::email('  Bob@Example.COM '));
        $this->assertSame('bob@example.com', Normalizer::email('bob+promo@example.com'));
        $this->assertSame('bobsmith@gmail.com', Normalizer::email('Bob.Smith+x@googlemail.com'));
        $this->assertSame('bob.smith@example.com', Normalizer::email('bob.smith@example.com'), 'dots matter outside Gmail');
        $this->assertSame('bob@example.com', Normalizer::email("\u{FF42}\u{FF4F}\u{FF42}@example.com"), 'fullwidth letters fold');
        $this->assertSame('bob@example.com', Normalizer::email("b\u{200B}ob@example.com"), 'zero-width characters drop');
    }

    public function test_durations_parse(): void
    {
        $this->assertSame(90.0, Duration::seconds(90));
        $this->assertSame(300.0, Duration::seconds('5 minutes'));
        $this->assertSame(300.0, Duration::seconds('5minutes'));
        $this->assertSame(3600.0, Duration::seconds('1h'));
        $this->assertSame(3600.0, Duration::seconds('hour'));
        $this->assertSame(0.5, Duration::seconds('500ms'));
        $this->assertSame(900.0, Duration::seconds(new \DateInterval('PT15M')));
        $this->assertSame('1h30m', Duration::label(5400));
        $this->assertSame('250ms', Duration::label(0.25));
    }

    public function test_bad_durations_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Duration::seconds('soon');
    }

    public function test_keys_are_keyed_hashes(): void
    {
        $a = new KeyGenerator('secret-a');
        $b = new KeyGenerator('secret-b');

        $key = $a->counter('login', 'credentials', 'email:bob@example.com|ip:203.0.113.9');
        $this->assertStringNotContainsString('bob', $key);
        $this->assertStringNotContainsString('203.0', $key);
        $this->assertSame(strlen($key), strlen($a->counter('p', 'l', str_repeat('x', 5000))));
        $this->assertNotSame($key, $b->counter('login', 'credentials', 'email:bob@example.com|ip:203.0.113.9'));
        $this->assertNotSame($a->counter('a', 'bc', 'd'), $a->counter('ab', 'c', 'd'), 'parts cannot run together');
    }
}
