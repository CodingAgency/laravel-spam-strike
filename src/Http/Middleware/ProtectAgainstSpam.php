<?php

declare(strict_types=1);

namespace CodingAgency\SpamStrike\Http\Middleware;

use Closure;
use CodingAgency\SpamStrike\Events\SpamBlocked;
use CodingAgency\SpamStrike\Jobs\SendAbuseReport;
use CodingAgency\SpamStrike\Support\FormToken;
use CodingAgency\SpamStrike\Support\ReportGate;
use CodingAgency\SpamStrike\Support\RequestDetails;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Put on the route a protected form posts to: Route::post('/contact', ...)->middleware('spam-strike').
 *
 * - No valid token (the browser ran no JavaScript): refused, and reported.
 * - The honeypot filled in: refused, and reported when there was no token either.
 * - Sent within the minimum seconds of the token: refused.
 * - A token older than the lifetime: refused, not reported.
 * - A sender whose domain matches `blocked_senders` (lead generation sellers): refused, and reported.
 *
 * Reasons listed under `silent.reasons` (by default all of them but the expired
 * token) get the answer of a form that went through instead of a refusal, so
 * the bot does not learn it was caught.
 *
 * What passes gets the seconds the form was in use and the request details as
 * request attributes `spam-strike.seconds` and `spam-strike.details`.
 */
final class ProtectAgainstSpam
{
    public const NO_JAVASCRIPT = 'no javascript';

    public const HONEYPOT = 'honeypot';

    public const TOO_FAST = 'too fast';

    public const EXPIRED = 'expired token';

    public const BLOCKED_SENDER = 'blocked sender';

    /** How long an address that sent a valid form is never reported to its network. */
    public const TRUSTED_DAYS = 30;

    public static function trustedKey(string $ip): string
    {
        return 'spam-strike:trusted-ip:'.$ip;
    }

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe()) {
            return $next($request);
        }

        $age = FormToken::age($request->input((string) config('spam-strike.fields.token')));
        $honeypot = $request->filled((string) config('spam-strike.fields.honeypot'));

        if ($honeypot) {
            return $this->block($request, self::HONEYPOT, report: $age === null);
        }

        if ($age === null) {
            return $this->block($request, self::NO_JAVASCRIPT, report: true);
        }

        if ($this->isBlockedSender($request)) {
            return $this->block($request, self::BLOCKED_SENDER, report: true);
        }

        if ($age > (int) config('spam-strike.token_lifetime')) {
            return $this->block($request, self::EXPIRED, report: false);
        }

        if ($age < (int) config('spam-strike.minimum_seconds')) {
            return $this->block($request, self::TOO_FAST, report: false);
        }

        // A person sent this: their address is never reported to its network.
        Cache::put(self::trustedKey((string) $request->ip()), true, now()->addDays(self::TRUSTED_DAYS));

        $request->attributes->set('spam-strike.seconds', $age);
        $request->attributes->set('spam-strike.details', RequestDetails::from($request));

        return $next($request);
    }

    /**
     * A sender at a domain of `blocked_senders`, such as the lead generation sellers that
     * do run JavaScript and fill in a form by hand. Matched on the domain of the address.
     */
    private function isBlockedSender(Request $request): bool
    {
        $email = Str::lower(trim((string) $request->input((string) config('spam-strike.fields.email'), '')));

        if (! str_contains($email, '@')) {
            return false;
        }

        $domain = Str::after($email, '@');

        foreach ((array) config('spam-strike.blocked_senders') as $pattern) {
            if (fnmatch(Str::lower((string) $pattern), $domain)) {
                return true;
            }
        }

        return false;
    }

    private function block(Request $request, string $reason, bool $report): Response
    {
        Log::info('Spam Strike blocked a submission', [
            'reason' => $reason,
            'path' => $request->path(),
            'email' => $request->input((string) config('spam-strike.fields.email')),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        event(new SpamBlocked($request, $reason));

        if ($report) {
            $this->queueReport($request, $reason);
        }

        if (in_array($reason, (array) config('spam-strike.silent.reasons'), true)) {
            return $this->pretendSuccess($request);
        }

        $message = (string) config('spam-strike.response.message');
        $status = (int) config('spam-strike.response.status');

        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'errors' => ['form' => [$message]]], $status);
        }

        return back(303)->withErrors(['form' => $message])->withInput($request->except((string) config('spam-strike.fields.token')));
    }

    /**
     * The answer of a form that went through, for a bot that should not learn it was caught.
     */
    private function pretendSuccess(Request $request): Response
    {
        if ($request->expectsJson()) {
            return response()->json((array) config('spam-strike.silent.json'));
        }

        $redirect = config('spam-strike.silent.redirect');

        $response = is_string($redirect) && $redirect !== '' ? redirect()->to($redirect, 303) : back(303);

        foreach ((array) config('spam-strike.silent.flash') as $key => $value) {
            $response->with((string) $key, $value);
        }

        return $response;
    }

    /**
     * Only the address is checked here; the lookup, the limits and the mail happen
     * in the job, outside the request.
     */
    private function queueReport(Request $request, string $reason): void
    {
        $email = Str::lower(trim((string) $request->input((string) config('spam-strike.fields.email'), '')));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }

        // What the job would skip is not queued: a bot that posts every half minute would
        // otherwise keep a worker awake all day for jobs that do nothing.
        if (! ReportGate::worthQueueing(Str::after($email, '@'), $request->ip())) {
            Log::info('Spam Strike did not queue an abuse report', ['email' => $email, 'reason' => $reason]);

            return;
        }

        // Switched off: nothing is queued, so no worker is needed, and the log says what would have gone out.
        if (! config('spam-strike.reports.enabled')) {
            Log::info('Spam Strike did not report a submission: reports are switched off', [
                'domain' => Str::after($email, '@'),
                'reason' => $reason,
            ]);

            return;
        }

        $submission = collect($request->except([
            (string) config('spam-strike.fields.token'),
            '_token',
        ]))
            ->filter(fn (mixed $value): bool => is_scalar($value) && $value !== '')
            ->map(fn (mixed $value): string => Str::limit((string) $value, 5000))
            ->all();

        SendAbuseReport::dispatch(Str::after($email, '@'), $email, $reason, RequestDetails::from($request), $submission)
            ->onQueue(config('spam-strike.reports.queue'));
    }
}
