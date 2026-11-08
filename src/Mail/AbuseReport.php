<?php

declare(strict_types=1);

namespace CodingAgency\SpamStrike\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class AbuseReport extends Mailable
{
    use Queueable;

    /**
     * @param  array<string, string>  $details
     * @param  array<string, string>  $submission
     * @param  string|null  $webForm  Set when the abuse desk only takes its web form: the mail then goes to you.
     * @param  string|null  $ip  Set for a report to the network the request came from, instead of the domain's registrar.
     * @param  list<string>  $nameservers
     * @param  list<string>  $relatedDomains  Other reported domains on exactly the same nameservers.
     */
    public function __construct(
        public string $domain,
        public string $reason,
        public array $details,
        public array $submission,
        public string $abuseContact,
        public ?string $webForm = null,
        public ?string $ip = null,
        public array $nameservers = [],
        public array $relatedDomains = [],
    ) {}

    public function envelope(): Envelope
    {
        $from = config('spam-strike.reports.from.address');
        $subject = $this->ip !== null
            ? 'Abuse report: contact form spam sent from '.$this->ip.' in your network'
            : 'Abuse report: unsolicited contact form spam from '.$this->domain;

        return new Envelope(
            from: $from ? new Address((string) $from, config('spam-strike.reports.from.name')) : null,
            subject: $this->webForm !== null ? '[Action needed: web form] '.$subject : $subject,
        );
    }

    public function content(): Content
    {
        return new Content(view: 'spam-strike::mail.abuse-report');
    }
}
