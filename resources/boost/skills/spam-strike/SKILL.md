---
name: spam-strike
description: Protect a Laravel form against spam with codingagency/laravel-spam-strike, or change how it blocks and reports. Use when adding a contact, signup or quote form, when spam comes through a form, or when someone asks for a CAPTCHA.
---

# Protecting a form with Spam Strike

## Steps

1. Mark the form and add the fields:

   ```blade
   <form method="POST" action="{{ route('contact.submit') }}" data-spam-strike>
       @csrf
       <x-spam-strike::fields />
       {{-- your inputs --}}
       @error('form') <p role="alert">{{ $message }}</p> @enderror
   </form>
   ```

2. Put the middleware on the route it posts to:

   ```php
   Route::post('/contact', [ContactController::class, 'submit'])
       ->middleware('spam-strike')
       ->name('contact.submit');
   ```

3. Sent with `fetch()`? Wait for the token first:

   ```js
   await window.SpamStrike.ready(form);
   const body = new FormData(form);
   ```

4. Optional: put `$request->attributes->get('spam-strike.details')` in your notification mail.

5. Make sure a queue worker runs, and set `SPAM_STRIKE_CC` and `SPAM_STRIKE_FROM_ADDRESS`.

## Testing a protected route

Send a valid token from the test, handed out longer ago than `minimum_seconds`:

```php
use CodingAgency\SpamStrike\Support\FormToken;

$this->travel(-10)->seconds();
$token = FormToken::issue();
$this->travel(10)->seconds();

$this->post(route('contact.submit'), ['_strike_token' => $token, /* ... */]);
```

Fake the queue (`Queue::fake()`) in tests that post without a token, or the report job runs and calls RDAP.

## Do not

- Do not add a CAPTCHA, or a token or timestamp rendered into the page.
- Do not send reports synchronously or raise `daily_limit` without reason: the address in a form is unverified.
