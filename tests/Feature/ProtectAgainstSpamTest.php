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
    'email' => 'hello@leadgen.example',
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
    Queue::assertPushed(SendAbuseReport::class, fn ($job) => $job->domain === 'leadgen.example'
        && $job->sender === 'hello@leadgen.example'
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
