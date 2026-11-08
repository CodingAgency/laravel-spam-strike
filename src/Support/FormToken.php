<?php

declare(strict_types=1);

namespace CodingAgency\SpamStrike\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * The token the script fetches on the first touch of a form: the moment it was
 * handed out, encrypted with the application key, so nobody can make one up.
 */
final class FormToken
{
    public static function issue(): string
    {
        return Crypt::encryptString('spam-strike:'.now()->getTimestamp());
    }

    /**
     * Seconds since the token was handed out, or null when it is missing or not one of ours.
     */
    public static function age(mixed $token): ?int
    {
        if (! is_string($token) || $token === '') {
            return null;
        }

        try {
            $payload = Crypt::decryptString($token);
        } catch (DecryptException) {
            return null;
        }

        if (preg_match('/^spam-strike:(\d+)$/', $payload, $match) !== 1) {
            return null;
        }

        return max(0, now()->getTimestamp() - (int) $match[1]);
    }
}
