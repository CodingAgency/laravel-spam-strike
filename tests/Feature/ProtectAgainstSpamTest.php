<?php

declare(strict_types=1);

use CodingAgency\SpamStrike\Events\SpamBlocked;
use CodingAgency\SpamStrike\Jobs\SendAbuseReport;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

beforeEach(fn () => Queue::fake());

$spam = fn (array $overrides = []): array => array_merge([
    'name' => 'Lead Gen',
    'email' => 'hello@spamco.example',
    'message' => 'We bring you fifty qualified leads every month.',
], $overrides);

it('lets a form with a token through, with the seconds and the request details', function () use ($spam) {
    $this->withHeaders(['User-Agent' => 'Firefox'])
        ->postJson('/contact', $spam(['_strike_token' => $this->token(12)]))
        ->assertOk()
        ->assertJson(['sent' => true, 'seconds' => 12, 'details' => ['User-Agent' => 'Firefox']]);

    Queue::assertNothingPushed();
});

it('tells a form without a token that it went through, logs it and queues an abuse report', function () use ($spam) {
    Event::fake([SpamBlocked::class]);
    Log::spy();

    $this->postJson('/contact', $spam())
        ->assertOk()
        ->assertExactJson(['success' => true]);

    Log::shouldHaveReceived('info')->once()->withArgs(fn (string $message, array $context) => $context['reason'] === 'no javascript');
    Event::assertDispatched(SpamBlocked::class, fn ($event) => $event->reason === 'no javascript');
    Queue::assertPushed(SendAbuseReport::class, fn ($job) => $job->domain === 'spamco.example'
        && $job->sender === 'hello@spamco.example'
        && $job->submission['message'] === 'We bring you fifty qualified leads every month.');
});

it('never lets a fooled submission reach the controller', function () use ($spam) {
    $this->postJson('/contact', $spam())->assertOk()->assertJsonMissingPath('sent');
    $this->postJson('/contact', $spam(['website_url' => 'https://x.example', '_strike_token' => $this->token()]))->assertJsonMissingPath('sent');
    $this->postJson('/contact', $spam(['_strike_token' => $this->token(1)]))->assertJsonMissingPath('sent');
});

it('sends a plain HTML form back with the success message and no error', function () use ($spam) {
    $this->from('/form')->post('/contact', $spam())
        ->assertStatus(303)
        ->assertRedirect('/form')
        ->assertSessionHas('success', 'Thank you for your message.')
        ->assertSessionHasNoErrors();
});

it('sends a plain HTML form to the thank-you page when there is one', function () use ($spam) {
    config(['spam-strike.silent.redirect' => '/thanks']);

    $this->from('/form')->post('/contact', $spam())
        ->assertStatus(303)
        ->assertRedirect('/thanks')
        ->assertSessionHas('success');
});

it('treats a token it did not hand out as none', function (Closure $token) use ($spam) {
    $this->postJson('/contact', $spam(['_strike_token' => $token()]))->assertOk()->assertExactJson(['success' => true]);

    Queue::assertPushed(SendAbuseReport::class, fn ($job) => $job->reason === 'no javascript');
})->with([
    'made up' => fn () => fn () => 'abc123',
    'someone else\'s payload' => fn () => fn () => Crypt::encryptString('1700000000'),
]);

it('fools a form sent within seconds of its token, without a report', function () use ($spam) {
    $this->postJson('/contact', $spam(['_strike_token' => $this->token(1)]))->assertOk()->assertExactJson(['success' => true]);

    Queue::assertNothingPushed();
});

it('tells the person who left the page open that it was not sent, and does not report them', function () use ($spam) {
    $this->postJson('/contact', $spam(['_strike_token' => $this->token(90000)]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('form');

    Queue::assertNothingPushed();
});

it('fools a filled honeypot, and reports it only without a token', function () use ($spam) {
    $this->postJson('/contact', $spam(['website_url' => 'https://x.example', '_strike_token' => $this->token()]))->assertOk()->assertExactJson(['success' => true]);
    Queue::assertNothingPushed();

    $this->postJson('/contact', $spam(['website_url' => 'https://x.example']))->assertOk()->assertExactJson(['success' => true]);
    Queue::assertPushed(SendAbuseReport::class, fn ($job) => $job->reason === 'honeypot');
});

it('queues nothing without a valid address, or with reports switched off', function () use ($spam) {
    $this->postJson('/contact', $spam(['email' => 'nope']))->assertOk();

    config(['spam-strike.reports.enabled' => false]);
    $this->postJson('/contact', $spam())->assertOk();

    Queue::assertNothingPushed();
});

it('logs the report it would have sent when reports are switched off', function () use ($spam) {
    config(['spam-strike.reports.enabled' => false]);
    Log::spy();

    $this->postJson('/contact', $spam())->assertOk();

    Queue::assertNothingPushed();
    Log::shouldHaveReceived('info')->with('Spam Strike did not report a submission: reports are switched off', Mockery::on(fn ($context) => $context['domain'] === 'spamco.example'));
});

describe('refusing out loud', function () use ($spam) {
    beforeEach(fn () => config(['spam-strike.silent.reasons' => ['honeypot']]));

    it('refuses a reason that is not listed with a 422 on the form key, and still reports it', function () use ($spam) {
        $this->postJson('/contact', $spam())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('form');

        $this->postJson('/contact', $spam(['_strike_token' => $this->token(1)]))->assertUnprocessable();

        Queue::assertPushed(SendAbuseReport::class, 1);
    });

    it('sends a plain HTML form back with the error on the form key', function () use ($spam) {
        $this->from('/form')->post('/contact', $spam())
            ->assertRedirect('/form')
            ->assertSessionHasErrors('form');
    });

    it('keeps fooling the reasons that are listed, with the configured answer', function () use ($spam) {
        config(['spam-strike.silent.json' => ['success' => true, 'message' => 'Sent!']]);

        $this->postJson('/contact', $spam(['website_url' => 'https://x.example']))
            ->assertOk()
            ->assertExactJson(['success' => true, 'message' => 'Sent!']);
    });
});

describe('blocked senders', function () use ($spam) {
    it('refuses a lead generation seller with JavaScript, makes it think it went through, and reports it', function (string $email) use ($spam) {
        config(['spam-strike.silent.json' => ['success' => true]]);

        $this->postJson('/contact', $spam(['email' => $email, '_strike_token' => $this->token(30)]))
            ->assertOk()
            ->assertExactJson(['success' => true]);

        Queue::assertPushed(SendAbuseReport::class, fn ($job) => $job->reason === 'blocked sender' && $job->sender === $email);
    })->with([
        'amazingleadgen.com' => 'jane@amazingleadgen.com',
        'subdomain' => 'jane@mail.firstleadgen.io',
        'leads.' => 'jane@qualifiedleads.co',
        'growthhack' => 'jane@growthhackers.agency',
    ]);

    it('lets an ordinary sender through', function (string $email) use ($spam) {
        $this->postJson('/contact', $spam(['email' => $email, '_strike_token' => $this->token(30)]))
            ->assertOk()
            ->assertJson(['sent' => true]);

        Queue::assertNothingPushed();
    })->with(['jan@bakkerij.nl', 'leadgen@gmail.com', 'info@leadership.example']);

    it('blocks nothing with an empty list', function () use ($spam) {
        config(['spam-strike.blocked_senders' => []]);

        $this->postJson('/contact', $spam(['email' => 'jane@amazingleadgen.com', '_strike_token' => $this->token(30)]))
            ->assertJson(['sent' => true]);
    });
});

describe('queueing only what the job would send', function () use ($spam) {
    beforeEach(fn () => config(['spam-strike.silent.reasons' => []]));

    it('queues nothing for a domain that was reported in the last day, so a bot that keeps posting keeps no worker awake', function () use ($spam) {
        Cache::put('spam-strike:report:domain:spamco.example', now()->toIso8601String(), now()->addDay());
        config(['spam-strike.reports.network' => false]);

        foreach (range(1, 5) as $attempt) {
            $this->postJson('/contact', $spam())->assertUnprocessable();
        }

        Queue::assertNothingPushed();
    });

    it('still queues a domain it has not reported yet', function () use ($spam) {
        $this->postJson('/contact', $spam())->assertUnprocessable();

        Queue::assertPushed(SendAbuseReport::class, 1);
    });

    it('queues nothing for a free mailbox provider when the network is reported already', function () use ($spam) {
        Cache::put('spam-strike:report:ip:127.0.0.1', true, now()->addDay());

        $this->postJson('/contact', $spam(['email' => 'bot@gmail.com']))->assertUnprocessable();

        Queue::assertNothingPushed();
    });

    it('still queues a free mailbox provider when only its network can be reported', function () use ($spam) {
        config(['spam-strike.reports.network' => true]);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->postJson('/contact', $spam(['email' => 'bot@gmail.com']))->assertUnprocessable();

        Queue::assertPushed(SendAbuseReport::class, 1);
    });

    it('queues nothing once the daily limit is used', function () use ($spam) {
        Cache::put('spam-strike:reports:'.now()->format('Y-m-d'), (int) config('spam-strike.reports.daily_limit'), now()->addDay());

        $this->postJson('/contact', $spam())->assertUnprocessable();

        Queue::assertNothingPushed();
    });

    it('queues nothing for an address of its own network, or for our own domain', function () use ($spam) {
        $this->postJson('/contact', $spam(['email' => 'test@'.parse_url((string) config('app.url'), PHP_URL_HOST)]))->assertUnprocessable();

        Queue::assertNothingPushed();
    });
});
