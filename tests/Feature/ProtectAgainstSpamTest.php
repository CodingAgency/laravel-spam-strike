<?php

declare(strict_types=1);

use CodingAgency\SpamStrike\Events\SpamBlocked;
use CodingAgency\SpamStrike\Jobs\SendAbuseReport;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Event;
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

it('refuses a form without a token with a 422 and queues an abuse report', function () use ($spam) {
    Event::fake([SpamBlocked::class]);

    $this->postJson('/contact', $spam())
        ->assertUnprocessable()
        ->assertJsonValidationErrors('form');

    Event::assertDispatched(SpamBlocked::class, fn ($event) => $event->reason === 'no javascript');
    Queue::assertPushed(SendAbuseReport::class, fn ($job) => $job->domain === 'spamco.example'
        && $job->sender === 'hello@spamco.example'
        && $job->submission['message'] === 'We bring you fifty qualified leads every month.');
});

it('sends a plain HTML form back with the error on the form key', function () use ($spam) {
    $this->from('/form')->post('/contact', $spam())
        ->assertRedirect('/form')
        ->assertSessionHasErrors('form');
});

it('treats a token it did not hand out as none', function (Closure $token) use ($spam) {
    $this->postJson('/contact', $spam(['_strike_token' => $token()]))->assertUnprocessable();

    Queue::assertPushed(SendAbuseReport::class);
})->with([
    'made up' => fn () => fn () => 'abc123',
    'someone else\'s payload' => fn () => fn () => Crypt::encryptString('1700000000'),
]);

it('refuses a form sent within seconds of its token, without a report', function () use ($spam) {
    $this->postJson('/contact', $spam(['_strike_token' => $this->token(1)]))->assertUnprocessable();

    Queue::assertNothingPushed();
});

it('refuses an expired token without reporting the person who left the page open', function () use ($spam) {
    $this->postJson('/contact', $spam(['_strike_token' => $this->token(90000)]))->assertUnprocessable();

    Queue::assertNothingPushed();
});

it('refuses a filled honeypot, and reports it only without a token', function () use ($spam) {
    $this->postJson('/contact', $spam(['website_url' => 'https://x.example', '_strike_token' => $this->token()]))->assertUnprocessable();
    Queue::assertNothingPushed();

    $this->postJson('/contact', $spam(['website_url' => 'https://x.example']))->assertUnprocessable();
    Queue::assertPushed(SendAbuseReport::class, fn ($job) => $job->reason === 'honeypot');
});

it('queues nothing without a valid address, or with reports switched off', function () use ($spam) {
    $this->postJson('/contact', $spam(['email' => 'nope']))->assertUnprocessable();

    config(['spam-strike.reports.enabled' => false]);
    $this->postJson('/contact', $spam())->assertUnprocessable();

    Queue::assertNothingPushed();
});

describe('fooling the bot', function () use ($spam) {
    beforeEach(fn () => config([
        'spam-strike.silent.reasons' => ['honeypot', 'too fast'],
        'spam-strike.silent.json' => ['success' => true, 'message' => 'Sent!'],
        'spam-strike.silent.flash' => ['success' => 'Thanks!'],
    ]));

    it('answers a listed reason like a form that went through', function () use ($spam) {
        $this->postJson('/contact', $spam(['_strike_token' => $this->token(1)]))
            ->assertOk()
            ->assertExactJson(['success' => true, 'message' => 'Sent!']);
    });

    it('flashes the success message to a plain form', function () use ($spam) {
        $this->from('/form')->post('/contact', $spam(['website_url' => 'https://x.example', '_strike_token' => $this->token()]))
            ->assertRedirect('/form')
            ->assertSessionHas('success', 'Thanks!')
            ->assertSessionHasNoErrors();
    });

    it('still reports what it fools', function () use ($spam) {
        $this->postJson('/contact', $spam(['website_url' => 'https://x.example']))->assertOk();

        Queue::assertPushed(SendAbuseReport::class, fn ($job) => $job->reason === 'honeypot');
    });

    it('keeps refusing the reasons that are not listed', function () use ($spam) {
        $this->postJson('/contact', $spam())->assertUnprocessable();
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
