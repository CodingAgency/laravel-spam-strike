<?php

declare(strict_types=1);

namespace CodingAgency\SpamStrike\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Abuse addresses from RDAP (what WHOIS became): of the registrar of a domain, and
 * of the network an IP address belongs to (the hosting provider the bot runs at).
 */
class AbuseContact
{
    /**
     * The abuse address of the network an address belongs to, or null when RDAP has none.
     */
    public function forIp(string $ip): ?string
    {
        try {
            $answer = Http::timeout(10)->acceptJson()->get('https://rdap.org/ip/'.$ip);
        } catch (Throwable) {
            return null;
        }

        return $answer->successful() ? $this->abuseEmail((array) $answer->json('entities')) : null;
    }

    /**
     * Tried for the domain and for its last two labels, with abuse@domain when RDAP has none.
     */
    public function forDomain(string $domain): string
    {
        $labels = explode('.', $domain);

        foreach (array_unique([$domain, implode('.', array_slice($labels, -2))]) as $candidate) {
            try {
                $answer = Http::timeout(10)->acceptJson()->get('https://rdap.org/domain/'.$candidate);
            } catch (Throwable) {
                continue;
            }

            if ($answer->successful() && ($email = $this->abuseEmail((array) $answer->json('entities'))) !== null) {
                return $email;
            }
        }

        return 'abuse@'.$domain;
    }

    /**
     * The first address of an entity with the role "abuse", however deep it sits.
     *
     * @param  array<int, mixed>  $entities
     */
    private function abuseEmail(array $entities): ?string
    {
        foreach ($entities as $entity) {
            if (! is_array($entity)) {
                continue;
            }

            if (in_array('abuse', (array) ($entity['roles'] ?? []), true)) {
                foreach ((array) ($entity['vcardArray'][1] ?? []) as $field) {
                    if (($field[0] ?? null) === 'email' && filter_var($field[3] ?? null, FILTER_VALIDATE_EMAIL)) {
                        return Str::lower($field[3]);
                    }
                }
            }

            if (($nested = $this->abuseEmail((array) ($entity['entities'] ?? []))) !== null) {
                return $nested;
            }
        }

        return null;
    }
}
