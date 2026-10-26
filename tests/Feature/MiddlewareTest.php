<?php

declare(strict_types=1);

namespace Atannex\Limiting\Tests\Feature;

use Atannex\Limiting\Facades\Limiting;
use Atannex\Limiting\Limit;
use Atannex\Limiting\Routing\RateLimit;
use Atannex\Limiting\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

final class MiddlewareTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        Route::get('/feed', fn () => 'ok')->middleware('limit:feed');
        Route::get('/inline', fn () => 'ok')->middleware('limit:2,1');
        Route::get('/inline-two', fn () => 'ok')->middleware('limit:2,1');
        Route::get('/expensive', fn () => 'ok')->middleware('limit:feed,3');
        Route::post('/login', function (Request $request) {
            return $request->input('password') === 'right'
                ? response('welcome')
                : response()->json(['message' => 'These credentials do not match our records.'], 422);
        })->middleware('limit:signin');
        Route::get('/shielded', fn () => 'ok')->middleware('limit.shield');
        Route::get('/attribute', fn () => 'ok')->middleware((new RateLimit(1, per: '1 minute', by: 'ip'))->middleware());
        Route::get('/slow', function (Request $request) {
            return Limiting::for('reports', $request)->check()->denied() ? 'slot held' : 'slot free';
        })->middleware('limit:reports');
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('limiting.policies.feed', [['name' => 'minute', 'allow' => 5, 'per' => '1 minute', 'by' => 'ip']]);
        $app['config']->set('limiting.policies.signin', [
            ['name' => 'credentials', 'allow' => 3, 'per' => '1 minute', 'by' => 'email:email|ip', 'count' => 'failures'],
            ['name' => 'address', 'allow' => 10, 'per' => '1 hour', 'by' => 'ip', 'count' => 'failures', 'jail' => ['after' => 2, 'within' => '1 hour', 'for' => '15 minutes']],
        ]);
        $app['config']->set('limiting.policies.reports', [['concurrent' => 1, 'by' => 'ip']]);
    }

    public function test_it_sends_draft_and_legacy_headers(): void
    {
        $response = $this->get('/feed')->assertOk();

        $response->assertHeader('RateLimit-Policy', '"feed.minute";q=5;w=60');
        $response->assertHeader('RateLimit', '"feed.minute";r=4;t=60');
        $response->assertHeader('X-RateLimit-Limit', '5');
        $response->assertHeader('X-RateLimit-Remaining', '4');
        $response->assertHeaderMissing('Retry-After');
    }

    public function test_it_refuses_with_429_and_retry_after(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->get('/feed')->assertOk();
        }

        $this->get('/feed')->assertStatus(429)->assertHeader('Retry-After')->assertHeader('X-RateLimit-Remaining', '0');

        $this->advance(61);
        $this->get('/feed')->assertOk();
    }

    public function test_json_requests_get_a_problem_document(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->getJson('/feed');
        }

        $this->getJson('/feed')
            ->assertStatus(429)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJson(['status' => 429, 'title' => 'Too Many Requests'])
            ->assertJsonStructure(['retry_after', 'detail']);
    }

    public function test_route_costs_spend_more(): void
    {
        $this->get('/expensive')->assertOk()->assertHeader('X-RateLimit-Remaining', '2');
        $this->get('/expensive')->assertStatus(429);
        $this->get('/feed')->assertOk();
    }

    public function test_inline_limits_count_per_route(): void
    {
        $this->get('/inline')->assertOk();
        $this->get('/inline')->assertOk();
        $this->get('/inline')->assertStatus(429);
        $this->get('/inline-two')->assertOk();
    }

    public function test_subjects_are_counted_apart(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->get('/feed', ['REMOTE_ADDR' => '203.0.113.1']);
        }

        $this->get('/feed', ['REMOTE_ADDR' => '203.0.113.1'])->assertStatus(429);
        $this->call('GET', '/feed', server: ['REMOTE_ADDR' => '203.0.113.2'])->assertOk();
    }

    public function test_ipv6_neighbours_in_one_64_share_a_limit(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->call('GET', '/feed', server: ['REMOTE_ADDR' => "2001:db8:5:6::{$i}"])->assertOk();
        }

        $this->call('GET', '/feed', server: ['REMOTE_ADDR' => '2001:db8:5:6:ffff::1'])->assertStatus(429);
        $this->call('GET', '/feed', server: ['REMOTE_ADDR' => '2001:db8:5:7::1'])->assertOk();
    }

    public function test_only_failed_logins_count(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/login', ['email' => 'bob@example.com', 'password' => 'right'])->assertOk();
        }

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/login', ['email' => 'bob@example.com', 'password' => 'wrong'])->assertStatus(422);
        }

        // Locked out for that address, even with the right password now...
        $this->postJson('/login', ['email' => 'Bob@Example.com', 'password' => 'right'])->assertStatus(429);
        $this->postJson('/login', ['email' => 'bob+again@example.com', 'password' => 'wrong'])->assertStatus(429);

        // ...but not for another account from the same place.
        $this->postJson('/login', ['email' => 'carol@example.com', 'password' => 'right'])->assertOk();
    }

    public function test_password_sprayers_are_jailed_site_wide(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/login', ['email' => "user{$i}@example.com", 'password' => 'wrong'])->assertStatus(422);
        }

        $this->postJson('/login', ['email' => 'x@example.com', 'password' => 'wrong'])->assertStatus(429); // strike 1
        $this->postJson('/login', ['email' => 'y@example.com', 'password' => 'wrong'])->assertStatus(429); // strike 2: jailed

        Limiting::assertJailed('127.0.0.1');
        $this->get('/shielded')->assertStatus(429)->assertHeader('Retry-After');
        $this->get('/feed')->assertStatus(429);
        $this->call('GET', '/shielded', server: ['REMOTE_ADDR' => '198.51.100.7'])->assertOk();

        $this->advance(901);
        $this->get('/shielded')->assertOk();
    }

    public function test_the_deny_list_answers_403(): void
    {
        Limiting::deny('203.0.113.0/24');

        $this->call('GET', '/shielded', server: ['REMOTE_ADDR' => '203.0.113.50'])->assertForbidden();
        $this->call('GET', '/feed', server: ['REMOTE_ADDR' => '203.0.113.50'])->assertForbidden();
        $this->get('/shielded')->assertOk();
    }

    public function test_the_allow_list_skips_limits_without_headers(): void
    {
        Limiting::allow('127.0.0.0/8');

        for ($i = 0; $i < 8; $i++) {
            $this->get('/feed')->assertOk()->assertHeaderMissing('X-RateLimit-Limit');
        }
    }

    public function test_concurrency_slots_free_after_the_response(): void
    {
        $this->get('/slow')->assertOk()->assertSee('slot held');
        $this->get('/slow')->assertOk()->assertSee('slot held');
    }

    public function test_attribute_middleware_works(): void
    {
        $this->assertSame('limit:1,per=1minute,by=ip', (new RateLimit(1, per: '1 minute', by: 'ip'))->middleware());
        $this->assertSame('limit:api,5', (new RateLimit('api', cost: 5))->middleware());

        $this->get('/attribute')->assertOk();
        $this->get('/attribute')->assertStatus(429);
    }

    public function test_custom_responses(): void
    {
        Limiting::respondUsing(fn (Request $request, $verdict) => response('slow down', 429));

        for ($i = 0; $i < 5; $i++) {
            $this->get('/feed');
        }

        $this->get('/feed')->assertStatus(429)->assertSee('slow down')->assertHeader('Retry-After');
    }

    public function test_dynamic_policies_per_user(): void
    {
        Limiting::define('plan', fn (?Request $request) => $request?->header('X-Plan') === 'pro'
            ? Limit::perMinute(100)->by('ip')
            : Limit::perMinute(1)->by('ip'));
        Route::get('/plan', fn () => 'ok')->middleware('limit:plan');

        $this->get('/plan')->assertOk();
        $this->get('/plan')->assertStatus(429);
        $this->get('/plan', ['X-Plan' => 'pro'])->assertOk()->assertHeader('X-RateLimit-Limit', '100');
    }

    public function test_the_readme_testing_example(): void
    {
        Limiting::fake();

        foreach (range(1, 3) as $i) {
            $this->postJson('/login', ['email' => 'bob@example.com', 'password' => 'wrong'])->assertStatus(422);
        }

        $this->postJson('/login', ['email' => 'bob@example.com', 'password' => 'right'])->assertStatus(429);

        Limiting::assertLimited('signin', 1);
        Limiting::assertAttempted('signin', 3);
        Limiting::assertNotJailed('127.0.0.1');
    }
}
