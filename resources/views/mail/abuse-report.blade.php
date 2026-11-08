<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; line-height: 1.6; color: #333; max-width: 640px; margin: 0 auto; padding: 20px; }
        h2 { font-size: 15px; margin: 24px 0 8px; }
        table { border-collapse: collapse; font-size: 13px; }
        td { padding: 2px 12px 2px 0; vertical-align: top; word-break: break-all; }
        td.label { white-space: nowrap; color: #666; }
        .message { background: #f6f6f6; padding: 12px 16px; white-space: pre-line; font-size: 13px; }
        .action { background: #fff6dd; border-left: 3px solid #e5a100; padding: 12px 16px; }
    </style>
</head>
<body>
    @if ($webForm)
        <div class="action">
            <strong>Not sent to {{ $abuseContact }}.</strong> That abuse desk only takes reports through its web form:
            <a href="{{ $webForm }}">{{ $webForm }}</a>. The report below is ready to paste in.
        </div>
    @endif

    <p>Hello,</p>

    @if ($ip)
        <p>
            We are reporting unsolicited spam that was sent through the contact form on our website,
            {{ config('app.url') }}, from <strong>{{ $ip }}</strong>, an address in your network. The sender
            gave an e-mail address at {{ $domain }}.
        </p>

        <p>
            The submission was made by an automated client: it did not run the JavaScript our form requires,
            which a person using a browser always does. This suggests a bot running on a server or service
            in your network. It was rejected and never reached anyone.
        </p>
    @else
        <p>
            We are reporting unsolicited spam that was sent through the contact form on our website,
            {{ config('app.url') }}. The sender gave an e-mail address at <strong>{{ $domain }}</strong>,
            a domain for which you are listed as the abuse contact.
        </p>

        <p>
            The submission was made by an automated client: it did not run the JavaScript our form requires,
            which a person using a browser always does. It was rejected and never reached anyone.
        </p>

        @if ($relatedDomains !== [])
            <p>
                The same sender appears to operate more domains: we received the same kind of spam in the past weeks
                from {{ implode(', ', $relatedDomains) }}, which use exactly the same nameservers as {{ $domain }}
                ({{ implode(', ', $nameservers) }}).
            </p>
        @endif
    @endif

    <p>
        We kindly ask you to look into this and to take appropriate action against the account or
        customer responsible. Details of the submission follow below.
    </p>

    @if ($submission !== [])
        <h2>Submission</h2>
        <table cellpadding="0" cellspacing="0">
            @foreach ($submission as $field => $value)
                <tr><td class="label">{{ \Illuminate\Support\Str::headline($field) }}</td><td><div class="message">{{ $value }}</div></td></tr>
            @endforeach
        </table>
    @endif

    <h2>Request details</h2>
    <table cellpadding="0" cellspacing="0">
        @foreach ($details as $label => $value)
            <tr><td class="label">{{ $label }}</td><td>{{ $value }}</td></tr>
        @endforeach
    </table>

    <p style="margin-top: 24px;">
        Kind regards,<br>
        {{ config('spam-strike.reports.from.name') ?: config('app.name') }}<br>
        {{ config('spam-strike.reports.from.address') }}
    </p>
</body>
</html>
