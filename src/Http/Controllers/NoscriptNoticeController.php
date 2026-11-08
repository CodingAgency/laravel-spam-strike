<?php

declare(strict_types=1);

namespace CodingAgency\SpamStrike\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The notice in place of a protected form for a browser without JavaScript. Only
 * such a browser loads it (it sits in <noscript>), and it is never cached, so the
 * page around it can be the same for everybody.
 */
final class NoscriptNoticeController
{
    public function __invoke(Request $request): Response
    {
        $language = strtolower(substr((string) ($request->getLanguages()[0] ?? ''), 0, 2));
        $message = config("spam-strike.noscript.messages.{$language}");

        return response()
            ->view('spam-strike::noscript', [
                'language' => $language,
                'email' => (string) config('spam-strike.noscript.email'),
                'parts' => is_string($message) ? explode(':email', $message, 2) : [],
            ])
            ->header('Cache-Control', 'no-store, private')
            ->header('Vary', 'Accept-Language');
    }
}
