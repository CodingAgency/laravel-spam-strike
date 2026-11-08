<?php

declare(strict_types=1);

namespace CodingAgency\SpamStrike\Http\Controllers;

use CodingAgency\SpamStrike\Support\FormToken;
use Illuminate\Http\JsonResponse;

final class TokenController
{
    public function __invoke(): JsonResponse
    {
        return response()
            ->json(['token' => FormToken::issue()])
            ->header('Cache-Control', 'no-store, private');
    }
}
