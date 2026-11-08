<?php

declare(strict_types=1);

namespace CodingAgency\SpamStrike\Support;

/**
 * The DNS questions the reports ask. A class of its own so tests can answer them.
 */
class Dns
{
    /** Whether the domain can receive mail at all: without MX, a copy only bounces. */
    public function hasMx(string $domain): bool
    {
        return @checkdnsrr($domain, 'MX');
    }

    /**
     * The domain's nameservers, lowercase and sorted.
     *
     * @return list<string>
     */
    public function nameservers(string $domain): array
    {
        $records = @dns_get_record($domain, DNS_NS) ?: [];
        $hosts = array_map(fn (array $record): string => strtolower(rtrim((string) ($record['target'] ?? ''), '.')), $records);
        $hosts = array_values(array_unique(array_filter($hosts)));
        sort($hosts);

        return $hosts;
    }
}
