<?php

declare(strict_types=1);

namespace CodingAgency\SpamStrike\Events;

/** $target is the reported domain, or the IP address for a report to its network. */
final readonly class AbuseReportSent
{
    /**
     * @param  list<string>  $to
     */
    public function __construct(public string $target, public array $to, public ?string $webForm = null) {}
}
