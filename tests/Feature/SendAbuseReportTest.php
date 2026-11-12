<?php

declare(strict_types=1);

use CodingAgency\SpamStrike\Jobs\SendAbuseReport;
use CodingAgency\SpamStrike\Mail\AbuseReport;
use CodingAgency\SpamStrike\Support\AbuseContact;
use CodingAgency\SpamStrike\Support\Dns;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    Http::preventStrayRequests();
    config(['spam-strike.reports.network' => false]);
    app()->instance(Dns::class, new FakeDns);
});

class FakeDns extends Dns
{
    /** @param  array<string, list<string>>  $nameservers */
    public function __construct(public bool $mx = true, public array $nameservers = []) {}

    public function hasMx(string $domain): bool
    {
        return $this->mx;
    }

    public function nameservers(string $domain): array
    {
        return $this->nameservers[$domain] ?? [];
    }
}

function sendReport(string $domain = 'leadgen.example', array $details = []): void
{
    (new SendAbuseReport($domain, 'hello@'.$domain, 'no javascript', array_merge([
        'IP' => '203.0.113.7',
        'User-Agent' => 'python-requests/2.31',
    ], $details), ['message' => 'Fifty qualified leads.']))->handle(app(AbuseContact::class), app(Dns::class));
}

function rdap(string $email): array
{
    return ['entities' => [['roles' => ['registrar'], 'entities' => [[
        'roles' => ['abuse'],
        'vcardArray' => ['vcard', [['version', [], 'text', '4.0'], ['email', [], 'text', $email]]],
    ]]]]];
}

it('mails the abuse contact from RDAP, the owner in cc and the sender in bcc', function () {
    Http::fake(['rdap.org/domain/leadgen.example' => Http::response(rdap('abuse@registrar.example'))]);

    sendReport();

    Mail::assertSent(AbuseReport::class, fn (AbuseReport $mail) => $mail->hasTo('abuse@registrar.example')
        && $mail->hasCc('owner@shop.example')
        && $mail->hasBcc('hello@leadgen.example')
        && $mail->hasFrom('contact@shop.example')
        && $mail->envelope()->subject === 'Abuse report: unsolicited contact form spam from leadgen.example');
});

it('falls back to abuse@ the domain, and tries the registered domain for a subdomain', function () {
    Http::fake([
        'rdap.org/domain/mail.leadgen.example' => Http::response([], 404),
        'rdap.org/domain/leadgen.example' => Http::response([], 404),
    ]);

    sendReport('mail.leadgen.example');

    Mail::assertSent(AbuseReport::class, fn (AbuseReport $mail) => $mail->hasTo('abuse@mail.leadgen.example'));
    Http::assertSentCount(2);
});

it('mails Cloudflare, with its reply desk in cc, for a domain on its nameservers without an abuse contact', function () {
    app()->instance(Dns::class, new FakeDns(nameservers: ['leadgen.example' => ['edward.ns.cloudflare.com', 'mckenzie.ns.cloudflare.com']]));
    Http::fake(['rdap.org/domain/*' => Http::response([], 404)]);

    sendReport();

    Mail::assertSent(AbuseReport::class, fn (AbuseReport $mail) => $mail->hasTo('abuse@cloudflare.com')
        && $mail->hasCc('abusereply@cloudflare.com')
        && $mail->hasCc('owner@shop.example')
        && $mail->hasBcc('hello@leadgen.example')
        && ! $mail->hasTo('abuse@leadgen.example'));
});

it('keeps the abuse contact from RDAP for a domain on Cloudflare nameservers', function () {
    app()->instance(Dns::class, new FakeDns(nameservers: ['leadgen.example' => ['edward.ns.cloudflare.com']]));
    Http::fake(['rdap.org/domain/leadgen.example' => Http::response(rdap('abuse@registrar.example'))]);

    sendReport();

    Mail::assertSent(AbuseReport::class, fn (AbuseReport $mail) => $mail->hasTo('abuse@registrar.example')
        && ! $mail->hasCc('abusereply@cloudflare.com'));
    Mail::assertNotSent(AbuseReport::class, fn (AbuseReport $mail) => $mail->hasTo('abuse@cloudflare.com'));
});

it('does not send a domain on other nameservers to Cloudflare', function () {
    app()->instance(Dns::class, new FakeDns(nameservers: ['leadgen.example' => ['ns1.notcloudflare.com']]));
    Http::fake(['rdap.org/domain/*' => Http::response([], 404)]);

    sendReport();

    Mail::assertSent(AbuseReport::class, fn (AbuseReport $mail) => $mail->hasTo('abuse@leadgen.example'));
    Mail::assertNotSent(AbuseReport::class, fn (AbuseReport $mail) => $mail->hasTo('abuse@cloudflare.com'));
});

it('mails a desk that asks for its web form anyway, with the sender in bcc', function () {
    Http::fake(['rdap.org/*' => Http::response(rdap('registrar-abuse@cloudflare.com'))]);

    sendReport();

    Mail::assertSent(AbuseReport::class, fn (AbuseReport $mail) => $mail->hasTo('registrar-abuse@cloudflare.com')
        && $mail->hasBcc('hello@leadgen.example'));
});

it('sends a desk listed as web form only nothing, and tells the owner instead', function () {
    config(['spam-strike.reports.web_form_only' => ['registrar-abuse@cloudflare.com' => 'https://www.cloudflare.com/trust-hub/reporting-abuse/']]);
    Http::fake(['rdap.org/*' => Http::response(rdap('registrar-abuse@cloudflare.com'))]);

    sendReport();

    Mail::assertSent(AbuseReport::class, fn (AbuseReport $mail) => $mail->hasTo('owner@shop.example')
        && ! $mail->hasTo('registrar-abuse@cloudflare.com')
        && $mail->bcc === []
        && str_starts_with($mail->envelope()->subject, '[Action needed: web form]'));
});

it('reports a domain once per period and stops at the daily limit', function () {
    Http::fake(['rdap.org/*' => Http::response([], 404)]);

    sendReport();
    sendReport();
    Mail::assertSentCount(1);

    config(['spam-strike.reports.daily_limit' => 3]);
    foreach (range(1, 5) as $number) {
        sendReport("spam{$number}.example");
    }
    Mail::assertSentCount(3);
});

it('never reports free mailboxes or the site itself', function (string $domain) {
    sendReport($domain);

    Mail::assertNothingSent();
    Http::assertNothingSent();
})->with(['gmail.com', 'shop.example', 'mail.shop.example']);

it('leaves an address of your own network out of the report', function () {
    Http::fake(['rdap.org/*' => Http::response([], 404)]);

    sendReport(details: ['IP' => '100.96.32.1', 'X-Forwarded-For' => '10.0.0.4']);

    Mail::assertSent(AbuseReport::class, fn (AbuseReport $mail) => ! isset($mail->details['IP'])
        && ! isset($mail->details['X-Forwarded-For'])
        && $mail->details['User-Agent'] === 'python-requests/2.31');
});

it('puts the submission and the request details in the mail', function () {
    Http::fake(['rdap.org/*' => Http::response([], 404)]);

    sendReport();

    Mail::assertSent(AbuseReport::class, function (AbuseReport $mail) {
        $mail->assertSeeInOrderInHtml(['leadgen.example', 'Fifty qualified leads.', '203.0.113.7', 'python-requests/2.31']);

        return true;
    });
});

it('sends no blind copy to a domain that cannot receive mail', function () {
    app()->instance(Dns::class, new FakeDns(mx: false));
    Http::fake(['rdap.org/*' => Http::response([], 404)]);

    sendReport();

    Mail::assertSent(AbuseReport::class, fn (AbuseReport $mail) => $mail->bcc === [] && $mail->hasTo('abuse@leadgen.example'));
});

it('tells the registrar about other reported domains on the same nameservers', function () {
    $shared = ['edward.ns.cloudflare.com', 'mckenzie.ns.cloudflare.com'];
    app()->instance(Dns::class, new FakeDns(nameservers: [
        'firstleadgen.example' => $shared,
        'amazingleadgen.example' => $shared,
        'other.example' => ['ns1.elsewhere.example'],
    ]));
    Http::fake(['rdap.org/*' => Http::response(rdap('registrar-abuse@cloudflare.com'))]);

    sendReport('firstleadgen.example');
    sendReport('other.example');
    sendReport('amazingleadgen.example');

    Mail::assertSent(AbuseReport::class, fn (AbuseReport $mail) => $mail->domain === 'firstleadgen.example' && $mail->relatedDomains === []);
    Mail::assertSent(AbuseReport::class, fn (AbuseReport $mail) => $mail->domain === 'other.example' && $mail->relatedDomains === []);
    Mail::assertSent(AbuseReport::class, function (AbuseReport $mail) {
        if ($mail->domain !== 'amazingleadgen.example') {
            return false;
        }

        $mail->assertSeeInHtml('firstleadgen.example');
        $mail->assertSeeInHtml('edward.ns.cloudflare.com');

        return $mail->relatedDomains === ['firstleadgen.example'];
    });
});

it('forgets a domain on the same nameservers after thirty days', function () {
    $shared = ['ns1.same.example'];
    app()->instance(Dns::class, new FakeDns(nameservers: ['a.example' => $shared, 'b.example' => $shared]));
    Http::fake(['rdap.org/*' => Http::response([], 404)]);

    sendReport('a.example');
    $this->travel(31)->days();
    sendReport('b.example');

    Mail::assertSent(AbuseReport::class, fn (AbuseReport $mail) => $mail->domain === 'b.example' && $mail->relatedDomains === []);
});

describe('the network the bot runs at', function () {
    beforeEach(fn () => config(['spam-strike.reports.network' => true]));

    it('also reports to the abuse contact of the network the request came from', function () {
        Http::fake([
            'rdap.org/domain/*' => Http::response(rdap('registrar-abuse@cloudflare.com')),
            'rdap.org/ip/203.0.113.7' => Http::response(rdap('abuse@hosting.example')),
        ]);

        sendReport();

        Mail::assertSent(AbuseReport::class, fn (AbuseReport $mail) => $mail->hasTo('registrar-abuse@cloudflare.com') && $mail->ip === null);
        Mail::assertSent(AbuseReport::class, fn (AbuseReport $mail) => $mail->hasTo('abuse@hosting.example')
            && $mail->hasCc('owner@shop.example')
            && $mail->bcc === []
            && $mail->ip === '203.0.113.7'
            && $mail->envelope()->subject === 'Abuse report: contact form spam sent from 203.0.113.7 in your network');
    });

    it('reports the network even when the sender used a free mailbox', function () {
        Http::fake(['rdap.org/ip/*' => Http::response(rdap('abuse@hosting.example'))]);

        sendReport('gmail.com');

        Mail::assertSentCount(1);
        Mail::assertSent(AbuseReport::class, fn (AbuseReport $mail) => $mail->hasTo('abuse@hosting.example'));
    });

    it('reports one address once per period', function () {
        Http::fake(['rdap.org/domain/*' => Http::response([], 404), 'rdap.org/ip/*' => Http::response(rdap('abuse@hosting.example'))]);

        sendReport('one.example');
        sendReport('two.example');

        Mail::assertSent(AbuseReport::class, fn (AbuseReport $mail) => $mail->hasTo('abuse@hosting.example'));
        expect(Mail::sent(AbuseReport::class, fn (AbuseReport $mail) => $mail->ip !== null))->toHaveCount(1);
    });

    it('sends nothing to the network without a public address or an abuse contact', function () {
        Http::fake(['rdap.org/domain/*' => Http::response([], 404), 'rdap.org/ip/*' => Http::response([], 404)]);

        sendReport('one.example', ['IP' => '100.96.32.1']);
        sendReport('two.example');

        expect(Mail::sent(AbuseReport::class, fn (AbuseReport $mail) => $mail->ip !== null))->toHaveCount(0);
    });
});

describe('never yourself', function () {
    beforeEach(fn () => config(['spam-strike.reports.network' => true]));

    it('reports nothing at all, not even the network, for the site\'s own domain', function (string $domain) {
        Http::fake(['rdap.org/*' => Http::response(rdap('abuse@isp.example'))]);

        sendReport($domain);

        Mail::assertNothingSent();
        Http::assertNothingSent();
    })->with(['shop.example', 'mail.shop.example']);

    it('counts own_domains as the site\'s own', function () {
        config(['spam-strike.reports.own_domains' => ['owner-home.example']]);
        Http::fake(['rdap.org/*' => Http::response(rdap('abuse@isp.example'))]);

        sendReport('owner-home.example');

        Mail::assertNothingSent();
    });

    it('never reports the network of an address on the ignore list', function () {
        config(['spam-strike.reports.ignore_ips' => ['203.0.113.0/24']]);
        Http::fake(['rdap.org/domain/*' => Http::response([], 404), 'rdap.org/ip/*' => Http::response(rdap('abuse@isp.example'))]);

        sendReport();

        expect(Mail::sent(AbuseReport::class, fn (AbuseReport $mail) => $mail->ip !== null))->toHaveCount(0);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'rdap.org/ip/'));
    });

    it('never reports the network of an address that sent a valid form before', function () {
        Http::fake(['rdap.org/domain/*' => Http::response([], 404), 'rdap.org/ip/*' => Http::response(rdap('abuse@isp.example'))]);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->postJson('/contact', ['_strike_token' => $this->token(), 'email' => 'jan@leadgen.example'])
            ->assertOk();

        sendReport();

        expect(Mail::sent(AbuseReport::class, fn (AbuseReport $mail) => $mail->ip !== null))->toHaveCount(0);
    });
});
