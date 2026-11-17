<?php

declare(strict_types=1);

namespace CodingAgency\SpamStrike\Support;

use CodingAgency\SpamStrike\Http\Middleware\ProtectAgainstSpam;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Whether a report is worth queueing at all, asked before the job is dispatched.
 *
 * The job decides for itself and skips what it may not report (an exempt or own domain,
 * a domain or address reported in the last day, the daily limit). A site that gets a
 * submission every half minute from the same few bots would still queue a job for each of
 * them, and a worker that wakes for a message every thirty seconds never sleeps. So the
 * same limits are read here, without taking anything: what the job would skip is not queued.
 */
final class ReportGate
{
    /**
     * Whether either report (to the registrar, to the network) could still be sent for this submission.
     */
    public static function worthQueueing(string $domain, ?string $ip): bool
    {
        if (self::isOwn($domain) || self::dailyLimitReached()) {
            return false;
        }

        return self::registrarOpen($domain) || self::networkOpen($ip);
    }

    public static function registrarOpen(string $domain): bool
    {
        return ! self::isExempt($domain) && ! Cache::has('spam-strike:report:domain:'.Str::lower($domain));
    }

    public static function networkOpen(?string $ip): bool
    {
        if (! config('spam-strike.reports.network') || $ip === null || $ip === '') {
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
            || IpUtils::checkIp($ip, (array) config('spam-strike.reports.hidden_networks'))
            || IpUtils::checkIp($ip, (array) config('spam-strike.reports.ignore_ips'))
            || Cache::has(ProtectAgainstSpam::trustedKey($ip))) {
            return false;
        }

        return ! Cache::has('spam-strike:report:ip:'.$ip);
    }

    public static function dailyLimitReached(): bool
    {
        return (int) Cache::get('spam-strike:reports:'.now()->format('Y-m-d'), 0) >= (int) config('spam-strike.reports.daily_limit');
    }

    public static function isOwn(string $domain): bool
    {
        $own = array_filter([
            parse_url((string) config('app.url'), PHP_URL_HOST),
            Str::after((string) config('spam-strike.reports.from.address'), '@'),
            ...(array) config('spam-strike.reports.own_domains'),
        ]);

        return self::matches(Str::lower($domain), $own);
    }

    public static function isExempt(string $domain): bool
    {
        return self::matches(Str::lower($domain), (array) config('spam-strike.reports.exempt_domains'));
    }

    /**
     * @param  array<int|string, mixed>  $domains
     */
    private static function matches(string $domain, array $domains): bool
    {
        foreach ($domains as $candidate) {
            $candidate = Str::lower((string) $candidate);

            if ($domain === $candidate || str_ends_with($domain, '.'.$candidate)) {
                return true;
            }
        }

        return false;
    }
}
