<?php

declare(strict_types=1);

namespace CodingAgency\SpamStrike\Http\Middleware;

use Closure;
use CodingAgency\SpamStrike\Events\SpamBlocked;
use CodingAgency\SpamStrike\Jobs\SendAbuseReport;
use CodingAgency\SpamStrike\Support\FormToken;
use CodingAgency\SpamStrike\Support\RequestDetails;
use Illuminate\Http\Request;
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
 *
 * Reasons listed under `silent.reasons` get the answer of a form that went
 * through instead, so the bot does not learn it was caught.
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

        if ($age > (int) config('spam-strike.token_lifetime')) {
            return $this->block($request, self::EXPIRED, report: false);
        }

        if ($age < (int) config('spam-strike.minimum_seconds')) {
            return $this->block($request, self::TOO_FAST, report: false);
        }

        $request->attributes->set('spam-strike.seconds', $age);
        $request->attributes->set('spam-strike.details', RequestDetails::from($request));

        return $next($request);
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

        $response = back(303);

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
        if (! config('spam-strike.reports.enabled')) {
            return;
        }

        $email = Str::lower(trim((string) $request->input((string) config('spam-strike.fields.email'), '')));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
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
