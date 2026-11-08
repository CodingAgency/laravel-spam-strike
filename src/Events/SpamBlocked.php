<?php

declare(strict_types=1);

namespace CodingAgency\SpamStrike\Events;

use Illuminate\Http\Request;

/** A protected form was refused. $reason is one of the SpamStrike reason constants. */
final readonly class SpamBlocked
{
    public function __construct(public Request $request, public string $reason) {}
}
