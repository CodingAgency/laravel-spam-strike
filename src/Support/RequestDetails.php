<?php

declare(strict_types=1);

namespace CodingAgency\SpamStrike\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * What the request of a submission tells about its sender: for an abuse report,
 * or for a debug block at the bottom of your own notification mail.
 */
final class RequestDetails
{
    /** @var list<string> */
    private const HEADERS = [
        'User-Agent', 'Referer', 'Origin', 'Accept-Language', 'Accept', 'X-Forwarded-For', 'X-Requested-With',
        'Sec-Fetch-Site', 'Sec-Fetch-Mode', 'Sec-CH-UA', 'Sec-CH-UA-Platform', 'Sec-CH-UA-Mobile', 'DNT',
    ];

    /**
     * @return array<string, string>
     */
    public static function from(Request $request): array
    {
        $details = ['IP' => (string) $request->ip()];

        foreach (self::HEADERS as $header) {
            $value = $request->headers->get($header);

            if (filled($value)) {
                $details[$header] = Str::limit((string) $value, 500);
            }
        }

        foreach ($request->headers->all() as $name => $values) {
            if ((str_starts_with($name, 'cdn-') || str_starts_with($name, 'cf-')) && filled($values[0] ?? null)) {
                $details[Str::headline($name)] = Str::limit((string) $values[0], 200);
            }
        }

        $details['Received'] = now()->toIso8601String();

        return $details;
    }
}
