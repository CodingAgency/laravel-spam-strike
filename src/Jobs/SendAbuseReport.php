<?php

declare(strict_types=1);

namespace CodingAgency\SpamStrike\Jobs;

use CodingAgency\SpamStrike\Events\AbuseReportSent;
use CodingAgency\SpamStrike\Events\AbuseReportSkipped;
use CodingAgency\SpamStrike\Http\Middleware\ProtectAgainstSpam;
use CodingAgency\SpamStrike\Mail\AbuseReport;
use CodingAgency\SpamStrike\Support\AbuseContact;
use CodingAgency\SpamStrike\Support\Dns;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Reports a blocked submission to two places, each with the cc addresses in copy:
 *
 * - the abuse contact of the registrar of the sender's domain, with the sender in
 *   blind copy when the domain can receive mail, and the other reported domains on
 *   the same nameservers, which tells the registrar it is one party;
 * - the abuse contact of the network the request came from: the hosting provider
 *   the bot runs at, which a proxy in front of the domain (Cloudflare) cannot hide.
 *
 * Each target once per period, and both counting towards the daily limit.
 */
final class SendAbuseReport implements ShouldQueue
{
    use Queueable;

    /** How long a reported domain is remembered for spotting others on the same nameservers. */
    private const NAMESERVER_MEMORY_DAYS = 30;

    public int $tries = 1;

    /**
     * @param  array<string, string>  $details  See RequestDetails.
     * @param  array<string, string>  $submission  The fields the sender filled in.
     */
    public function __construct(
        public string $domain,
        public string $sender,
        public string $reason,
        public array $details,
        public array $submission,
    ) {}

    public function handle(AbuseContact $contacts, Dns $dns): void
    {
        $domain = Str::lower($this->domain);
        $details = $this->publicDetails();
        $domainRecipient = null;

        // Your own domain in the form is you, or someone testing for you: nothing
        // is reported anywhere, not to its registrar and not to its network.
        if ($this->isOwnDomain($domain)) {
            $this->skip($domain, 'own domain, nothing reported');

            return;
        }

        if ($this->isExempt($domain)) {
            $this->skip($domain, 'exempt domain');
        } elseif ($this->claim("domain:{$domain}", $domain)) {
            $domainRecipient = $this->reportDomain($domain, $details, $contacts, $dns);
        }

        if (config('spam-strike.reports.network') && isset($details['IP']) && $this->mayReportNetwork($details['IP'])) {
            $this->reportNetwork($details['IP'], $domain, $details, $contacts, $domainRecipient);
        }
    }

    /**
     * @param  array<string, string>  $details
     */
    private function reportDomain(string $domain, array $details, AbuseContact $contacts, Dns $dns): string
    {
        $recipient = $contacts->forDomain($domain);
        $webForm = config('spam-strike.reports.web_form_only')[$recipient] ?? null;
        $cc = $this->cc();
        [$nameservers, $related] = $this->sharedNameservers($domain, $dns);

        $mail = new AbuseReport(
            domain: $domain,
            reason: $this->reason,
            details: $details,
            submission: $this->submission,
            abuseContact: $recipient,
            webForm: $webForm,
            nameservers: $nameservers,
            relatedDomains: $related,
        );

        if ($webForm !== null) {
            if ($cc === []) {
                $this->skip($domain, 'web form only, and nobody in cc to tell');

                return $recipient;
            }

            Mail::to($cc)->send($mail);
            $this->sent($domain, $cc, $webForm);

            return $recipient;
        }

        $bcc = config('spam-strike.reports.bcc_sender')
            && ! in_array($this->sender, [$recipient, ...$cc], true)
            && $dns->hasMx($domain)
                ? [$this->sender]
                : [];

        Mail::to($recipient)->cc($cc)->bcc($bcc)->send($mail);
        $this->sent($domain, [$recipient, ...$cc, ...$bcc], null);

        return $recipient;
    }

    /**
     * @param  array<string, string>  $details
     */
    private function reportNetwork(string $ip, string $domain, array $details, AbuseContact $contacts, ?string $domainRecipient): void
    {
        if (! $this->claim("ip:{$ip}", $ip)) {
            return;
        }

        $recipient = $contacts->forIp($ip);

        if ($recipient === null) {
            $this->skip($ip, 'no abuse contact for the network');

            return;
        }

        if ($recipient === $domainRecipient) {
            $this->skip($ip, 'same abuse contact as the domain report');

            return;
        }

        Mail::to($recipient)->cc($this->cc())->send(new AbuseReport(
            domain: $domain,
            reason: $this->reason,
            details: $details,
            submission: $this->submission,
            abuseContact: $recipient,
            ip: $ip,
        ));

        $this->sent($ip, [$recipient, ...$this->cc()], null);
    }

    /**
     * Take one report for this target in this period, within the daily limit.
     */
    private function claim(string $key, string $target): bool
    {
        $dailyKey = 'spam-strike:reports:'.now()->format('Y-m-d');

        if ((int) Cache::get($dailyKey, 0) >= (int) config('spam-strike.reports.daily_limit')) {
            $this->skip($target, 'daily limit');

            return false;
        }

        if (! Cache::add("spam-strike:report:{$key}", now()->toIso8601String(), now()->addHours((int) config('spam-strike.reports.per_domain_hours')))) {
            $this->skip($target, 'already reported');

            return false;
        }

        Cache::add($dailyKey, 0, now()->addDays(2));
        Cache::increment($dailyKey);

        return true;
    }

    /**
     * The domain's nameservers, and the other domains reported in the last weeks
     * that use exactly the same ones. The domain is remembered for the next report.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function sharedNameservers(string $domain, Dns $dns): array
    {
        $nameservers = $dns->nameservers($domain);

        if ($nameservers === []) {
            return [[], []];
        }

        $key = 'spam-strike:nameservers:'.sha1(implode(',', $nameservers));
        $cutoff = now()->subDays(self::NAMESERVER_MEMORY_DAYS)->getTimestamp();

        /** @var array<string, int> $seen */
        $seen = array_filter((array) Cache::get($key, []), fn (mixed $at): bool => (int) $at >= $cutoff);
        $related = array_values(array_diff(array_keys($seen), [$domain]));
        sort($related);

        $seen[$domain] = now()->getTimestamp();
        Cache::put($key, $seen, now()->addDays(self::NAMESERVER_MEMORY_DAYS));

        return [$nameservers, $related];
    }

    /**
     * @return list<string>
     */
    private function cc(): array
    {
        return array_values(array_filter((array) config('spam-strike.reports.cc')));
    }

    private function isOwnDomain(string $domain): bool
    {
        $own = array_filter([
            parse_url((string) config('app.url'), PHP_URL_HOST),
            Str::after((string) config('spam-strike.reports.from.address'), '@'),
            ...(array) config('spam-strike.reports.own_domains'),
        ]);

        foreach ($own as $exempt) {
            $exempt = Str::lower((string) $exempt);

            if ($domain === $exempt || str_ends_with($domain, '.'.$exempt)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Never the network of an address on the ignore list, or of one that sent a
     * valid form in the last weeks: that is a person, maybe you, maybe a customer
     * behind the same office connection.
     */
    private function mayReportNetwork(string $ip): bool
    {
        if (IpUtils::checkIp($ip, (array) config('spam-strike.reports.ignore_ips'))) {
            $this->skip($ip, 'address on the ignore list');

            return false;
        }

        if (Cache::has(ProtectAgainstSpam::trustedKey($ip))) {
            $this->skip($ip, 'address sent a valid form before');

            return false;
        }

        return true;
    }

    private function isExempt(string $domain): bool
    {
        foreach ((array) config('spam-strike.reports.exempt_domains') as $exempt) {
            if ($domain === $exempt || str_ends_with($domain, '.'.$exempt)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The details without an address of your own network, which would only mislead the recipient.
     *
     * @return array<string, string>
     */
    private function publicDetails(): array
    {
        $details = $this->details;

        foreach (['IP', 'X-Forwarded-For'] as $key) {
            $ip = $details[$key] ?? '';

            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
                || IpUtils::checkIp($ip, (array) config('spam-strike.reports.hidden_networks'))) {
                unset($details[$key]);
            }
        }

        return $details;
    }

    private function skip(string $target, string $reason): void
    {
        Log::info('Spam Strike skipped an abuse report', ['target' => $target, 'reason' => $reason]);
        event(new AbuseReportSkipped($target, $reason));
    }

    /**
     * @param  list<string>  $to
     */
    private function sent(string $target, array $to, ?string $webForm): void
    {
        Log::info('Spam Strike sent an abuse report', ['target' => $target, 'to' => $to, 'web_form' => $webForm, 'reason' => $this->reason]);
        event(new AbuseReportSent($target, $to, $webForm));
    }
}
