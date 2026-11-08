<?php

declare(strict_types=1);

use CodingAgency\SpamStrike\Support\FormToken;

it('hands out a token that is never cached', function () {
    $response = $this->getJson(route('spam-strike.token'))->assertOk();

    expect(FormToken::age($response->json('token')))->toBe(0)
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('Cache-Control'))->not->toContain('public');
});

it('shows the notice in the first browser language', function (string $language, string $text) {
    $this->withHeaders(['Accept-Language' => $language])
        ->get(route('spam-strike.noscript'))
        ->assertOk()
        ->assertSee($text)
        ->assertSee('mailto:contact@shop.example', false)
        ->assertHeader('Vary', 'Accept-Language');
})->with([
    'Dutch' => ['nl-NL,nl;q=0.9', 'Het contactformulier kon niet geladen worden.'],
    'English' => ['en-GB', 'The contact form could not be loaded.'],
]);

it('shows nothing at all for a language without a message', function () {
    $response = $this->withHeaders(['Accept-Language' => 'de-DE,nl;q=0.5'])->get(route('spam-strike.noscript'));

    expect($response->getContent())->not->toContain('spam-strike-notice"');
});

it('renders the fields, and the script only once for two forms', function () {
    $html = $this->get('/form')->assertOk()->getContent();

    expect(substr_count($html, 'name="_strike_token"'))->toBe(2)
        ->and(substr_count($html, 'name="website_url"'))->toBe(2)
        ->and(substr_count($html, 'data-spam-strike-script'))->toBe(1)
        ->and($html)->toContain('data-token-url="'.route('spam-strike.token').'"')
        ->and($html)->toContain('<iframe src="'.route('spam-strike.noscript').'"');
});
