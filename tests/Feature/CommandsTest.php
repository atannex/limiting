<?php

declare(strict_types=1);

namespace Atannex\Limiting\Tests\Feature;

use Atannex\Limiting\Facades\Limiting;
use Atannex\Limiting\Limit;
use Atannex\Limiting\Tests\TestCase;

final class CommandsTest extends TestCase
{
    public function test_policies_lists_the_config_presets(): void
    {
        $this->artisan('limiting:policies')
            ->expectsOutputToContain('credentials')
            ->expectsOutputToContain('sliding-log')
            ->expectsOutputToContain('jail after 3')
            ->assertSuccessful();
    }

    public function test_inspect_and_reset(): void
    {
        Limiting::define('send', Limit::perMinute(2)->by('ip'));
        Limiting::for('send', 'ip:203.0.113.9')->attempt();
        Limiting::for('send', 'ip:203.0.113.9')->attempt();

        $this->artisan('limiting:inspect', ['policy' => 'send', 'subject' => '203.0.113.9'])
            ->expectsOutputToContain('refused')
            ->assertSuccessful();

        $this->artisan('limiting:reset', ['policy' => 'send', 'subject' => '203.0.113.9'])->assertSuccessful();
        $this->assertTrue(Limiting::for('send', 'ip:203.0.113.9')->attempt()->allowed());

        $this->artisan('limiting:inspect', ['policy' => 'send', 'subject' => '203.0.113.9'])
            ->expectsOutputToContain('1 left')
            ->assertSuccessful();
    }

    public function test_jail_list_and_release(): void
    {
        $this->artisan('limiting:jail', ['subject' => '198.51.100.4', '--for' => '2h', '--reason' => 'scraping'])
            ->expectsOutputToContain('Jailed ip:198.51.100.4')
            ->assertSuccessful();

        Limiting::assertJailed('198.51.100.4');

        $this->artisan('limiting:jailed')
            ->expectsOutputToContain('scraping')
            ->assertSuccessful();

        $this->artisan('limiting:release', ['subject' => '198.51.100.4', '--pardon' => true])
            ->expectsOutputToContain('Released ip:198.51.100.4')
            ->assertSuccessful();

        Limiting::assertNotJailed('198.51.100.4');
        $this->artisan('limiting:jailed')->expectsOutputToContain('Nobody is jailed')->assertSuccessful();
    }

    public function test_stats(): void
    {
        $this->artisan('limiting:stats')->expectsOutputToContain('Nothing was refused')->assertSuccessful();

        Limiting::define('send', Limit::perMinute(1));
        Limiting::for('send', 'user:9')->attempt();
        Limiting::for('send', 'user:9')->attempt();

        $this->artisan('limiting:stats')
            ->expectsOutputToContain('send:1-per-1m')
            ->expectsOutputToContain('user:9')
            ->assertSuccessful();
    }

    public function test_doctor_flags_a_per_request_store(): void
    {
        // The test app uses the array cache: fine locally, not in production.
        $this->artisan('limiting:doctor')
            ->expectsOutputToContain('Read-modify-write works')
            ->expectsOutputToContain('counts live for one request only')
            ->assertSuccessful();

        $this->app['env'] = 'production';
        $this->artisan('limiting:doctor')->assertFailed();
    }
}
