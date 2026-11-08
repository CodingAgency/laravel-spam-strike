# Spam Strike 🥊

[![Tests](https://github.com/CodingAgency/laravel-spam-strike/actions/workflows/tests.yml/badge.svg)](https://github.com/CodingAgency/laravel-spam-strike/actions/workflows/tests.yml)
[![Latest version](https://img.shields.io/packagist/v/codingagency/laravel-spam-strike.svg)](https://packagist.org/packages/codingagency/laravel-spam-strike)
[![License](https://img.shields.io/packagist/l/codingagency/laravel-spam-strike.svg)](LICENSE)

**Stop contact form spam in Laravel without a CAPTCHA. Then hit back.**

> *"Hi, I noticed a few errors on your website. Can I send you a free quote?"*
>
> *"We can bring you 50 qualified leads per month, guaranteed!"*
>
> *"Dear Sir/Madam, are you interested in a guest post?"*

Nobody has ever wanted these, yet they keep landing in your inbox. Spam Strike stops them, and then sends the spammer's registrar and hosting provider a polite, detailed and fully documented complaint, with a blind copy to the spammer. Your visitors never see a puzzle. The spammer gets paperwork.

## How it works

Most form spam comes from cheap scripts that post straight to your form without running JavaScript. They save a second, and Spam Strike makes that second count.

1. **A JavaScript token.** The form only goes through with a token that the package's script fetches on the first touch of the form. The token is encrypted with your application key, so it cannot be made up. No JavaScript means no token, and no token means no entry.
2. **A honeypot.** A hidden field that no person ever sees. Bots fill in every field they find, and that gives them away.
3. **A timing check.** No person types a whole message in under 3 seconds. A bot does.
4. **Payback.** A refused bot gets a 422 straight away. A queued job then files the complaints:
   - **to the registrar of the sender's domain**, found through RDAP (what WHOIS became). The sender gets a blind copy, so they know you know. Other reported domains on exactly the same nameservers are named in the report, because spammers do love a fresh domain every week.
   - **to the hosting provider the bot runs at**, found through RDAP for its IP address. That one is worth the most, because hosting providers switch off servers. It also works when the spammer's domain hides behind Cloudflare.

Real visitors notice nothing. The page stays the same for everyone, so it can stay in your full-page or CDN cache. Only the small token route is never cached.

## Installation

```bash
composer require codingagency/laravel-spam-strike
```

```dotenv
SPAM_STRIKE_CC=you@example.com              # a copy of every complaint, for your own amusement
SPAM_STRIKE_FROM_ADDRESS=contact@example.com
```

The complaints are queued, so a queue worker has to run.

## Usage

Mark the form with `data-spam-strike`, put the fields inside it, and put the middleware on the route the form posts to. That is all:

```blade
<form method="POST" action="{{ route('contact.submit') }}" data-spam-strike>
    @csrf
    <x-spam-strike::fields />

    <input name="name">
    <input name="email" type="email">
    <textarea name="message"></textarea>

    @error('form') <p role="alert">{{ $message }}</p> @enderror
    <button>Send</button>
</form>
```

```php
Route::post('/contact', [ContactController::class, 'submit'])
    ->middleware('spam-strike')
    ->name('contact.submit');
```

A refused submission gets a 422 with the error on the `form` key, in JSON or as a redirect back.

### Forms sent with fetch()

Wait for the token before you build the form data:

```js
await window.SpamStrike.ready(form);
const response = await fetch(form.action, { method: 'POST', body: new FormData(form) });
```

Does your page handle the submit itself, for example to return an answer to an AI agent (WebMCP)? Set `data-spam-strike="manual"`. The script still fetches the token, but leaves the submit event alone, and your handler awaits `SpamStrike.ready(form)` as above.

A form that is sent too fast, for example by an AI agent filling it in for a real person, does not fail. The script waits the remaining seconds and then sends it.

### The sender's details in your own mail

After the middleware, the request holds what it told about the sender. Use it for a small debug block at the bottom of your notification mail:

```php
$details = $request->attributes->get('spam-strike.details'); // IP, User-Agent, Referer, Accept-Language, ...
$seconds = $request->attributes->get('spam-strike.seconds'); // how long the form was in use
```

## Who gets what

| Submission | Response | Complaint filed |
|---|---|---|
| No token, or a made-up one | 422 | yes 📨 |
| Honeypot filled, no token | 422 | yes 📨 |
| Honeypot filled, with a token | 422 | no |
| Sent within `minimum_seconds` of the token | 422 | no |
| Token older than `token_lifetime` (someone left the tab open) | 422 | no |
| A real person | passes ✅ | no |

Without JavaScript, the form is hidden and a short notice is shown in the visitor's browser language, with your e-mail address. Languages you did not configure get nothing at all. If you only serve Dutch and English, a bot claiming to be from somewhere else does not need help.

## Playing fair

Spam Strike hits back hard, and it plays fair while doing it. The address in a web form is whatever the sender typed, and spammers sometimes type someone else's. So:

- each domain and each IP address is reported **once a day**, with **at most 25 complaints a day** in all, so nobody can use your form to flood other people;
- free mailbox providers (Gmail, Outlook and the like) and your own domain are never reported to a registrar;
- addresses of your own network (private, reserved and `100.64.0.0/10`) are never put in a complaint;
- complaints go to abuse desks, whose job it is to look into them;
- every complaint, and every one that was skipped, is logged and dispatched as an event: `SpamBlocked`, `AbuseReportSent` and `AbuseReportSkipped`.

Keep those limits on.

## Behind a proxy or CDN

The complaint to the hosting provider needs the bot's real IP address. Behind a CDN or load balancer, trust your proxies with Laravel's `TrustProxies`, or `$request->ip()` is your proxy and the complaint has nothing to go on.

## Configuration

```bash
php artisan vendor:publish --tag=spam-strike-config
php artisan vendor:publish --tag=spam-strike-views
```

The config file describes every option: the field names, the minimum seconds, the notice per language, who gets a copy, the blind copy to the sender, the limits, the exempt domains, and abuse desks that only take a web form (`web_form_only`). Cloudflare, for one, answers mailed complaints with a link to its form. By default they get the mail anyway.

## Testing a protected route

```php
use CodingAgency\SpamStrike\Support\FormToken;

$this->travel(-10)->seconds();
$token = FormToken::issue();
$this->travel(10)->seconds();

$this->post('/contact', ['_strike_token' => $token, 'email' => 'jan@example.com']);
```

Fake the queue (`Queue::fake()`) in tests that post without a token, or the complaint job runs and calls RDAP.

## For AI coding agents

The package ships [Laravel Boost](https://github.com/laravel/boost) guidelines and a skill (`resources/boost`). After `php artisan boost:install`, your coding agent knows how to protect a form with Spam Strike, and knows not to suggest a CAPTCHA.

## Make their lives harder: contribute

Every spammer who gets past this package is a pull request waiting to happen. Ideas we would love to see:

- more abuse desks and their quirks;
- smarter detection that never bothers a real person;
- reports to more places that actually act on them;
- translations of the notice without JavaScript.

Pull requests are very welcome. See [CONTRIBUTING.md](CONTRIBUTING.md). Got a spam message so bad it deserves its own test case? Open an issue, we collect them.

## Laravel versions

Spam Strike supports Laravel 12 and 13 on PHP 8.3 and up. CI also runs the tests against the next Laravel major while it is being built, so a new major is supported shortly after its release.

## License

MIT. Made with mild irritation by [Coding Agency](https://coding.agency) in Meppel, the Netherlands, after one too many "free SEO audits".
