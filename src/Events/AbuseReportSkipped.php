<?php

declare(strict_types=1);

namespace CodingAgency\SpamStrike\Events;

/** $target is the reported domain, or the IP address for a report to its network. */
final readonly class AbuseReportSkipped
{
    public function __construct(public string $target, public string $reason) {}
}
