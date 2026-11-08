## Spam Strike (codingagency/laravel-spam-strike)

Spam Strike protects public forms without a CAPTCHA and reports spam it blocks to the abuse contact of the sender's domain.

- Protect a form in two places, never one: `data-spam-strike` plus `<x-spam-strike::fields />` inside the `<form>`, and the `spam-strike` middleware on the route the form posts to.
- Do not add a CAPTCHA, and do not put a server-side timestamp or token in the page: the page must stay the same for every visitor so it can be cached. The package's script fetches the token from `route('spam-strike.token')` on the first touch of the form.
- A form sent with `fetch()` must `await window.SpamStrike.ready(form)` before building its `FormData`.
- The middleware refuses with a 422 and an error on the `form` key. Show `$errors->first('form')` (or `errors.form` in JSON) near the submit button.
- Abuse reports run as the queued job `CodingAgency\SpamStrike\Jobs\SendAbuseReport`. A queue worker must run. Never send them synchronously, and never remove the per-domain or daily limits.
- Reports also go to the network of the sender's IP address. Behind a CDN or load balancer, make sure `$request->ip()` is the visitor (TrustProxies), or that report has nothing to go on.
- After the middleware, `$request->attributes->get('spam-strike.details')` holds the sender's IP and headers for a debug block in your own notification mail.
- Configuration lives in `config/spam-strike.php` (publish with `php artisan vendor:publish --tag=spam-strike-config`). Set `SPAM_STRIKE_CC` to the address that should get a copy of every report.
