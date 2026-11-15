# Changelog

## 0.6.0 — 2026-11-15

- **A caught bot is told it went through.** `silent.reasons` now lists `no javascript`, `honeypot` and `too fast` next to `blocked sender` by default: those get a 200 with `silent.json`, or a redirect with `silent.flash`, instead of a 422, so the bot has no reason to try again another way. The submission is still refused, logged and reported as before. Only an expired token still gets the 422: that is a person who left the tab open.
- `silent.redirect`: the URL or path of your thank-you page, for a plain form that does not come back to its own page when it is sent. `null` sends it back to the form.
- Published config files keep what they say: add the reasons to `silent.reasons` to get the new behaviour. To keep the 422, set `silent.reasons` to `['blocked sender']`.

## 0.5.0 — 2026-11-15

- `blocked_senders`: a sender whose domain matches one of these patterns is refused whatever else the form says, and reported. By default the lead generation sellers: `*leadgen*`, `*leads.*` and `*growthhack*`. The reason is `blocked sender`, which is in `silent.reasons` by default, so they are told it went through.
- `reports.never_report`: abuse desks that are never mailed, by the domain of their address. Cloudflare is in it by default: it answers every report with a link to a form and does nothing.
- `nameserver_fallbacks` is empty by default; Cloudflare is no longer in it. Published config files keep what they say: add `blocked_senders` and `never_report`, and take Cloudflare out of `nameserver_fallbacks`.

## 0.4.0 — 2026-11-12

- `nameserver_fallbacks`: when RDAP gives no abuse contact for a domain, a domain on the listed nameservers is reported to that party instead of abuse@domain, which would only reach the spammer. Cloudflare is listed by default: `abuse@cloudflare.com`, with `abusereply@cloudflare.com` in cc. Published config files need the key added.

## 0.3.1 — 2026-11-09

- **Never report yourself.** A form with an address at the site's own domain (`app.url`, the from address, or `own_domains`) is no longer reported anywhere. Until now only the report to the registrar was skipped, and the report to the network still went out: testing your own form without JavaScript reported your own connection to your provider.
- An address that sent a valid form in the last 30 days is never reported to its network: that is a person, perhaps a customer behind the same office connection.
- `ignore_ips` (`SPAM_STRIKE_IGNORE_IPS`): addresses and ranges whose network is never reported.

## 0.3.0 — 2026-11-08

- `silent.reasons`: blocked submissions for the listed reasons get the answer of a form that went through (JSON with status 200, or a flashed success message), so the bot does not learn it was caught. They are still refused, and still reported where they would be.

## 0.2.0 — 2026-11-08

- `data-spam-strike="manual"`: the script fetches the token but leaves the submit event to the page, which awaits `SpamStrike.ready(form)` itself. For pages that answer the submit themselves, such as an AI agent's (WebMCP).

## 0.1.0 — 2026-11-08

First release.

- `spam-strike` middleware: refuses a submission without a valid JavaScript token, with a filled honeypot, sent within `minimum_seconds` of its token, or with an expired token.
- `<x-spam-strike::fields />`: the token field, the honeypot, the script, and the notice without JavaScript.
- A token route that is never cached, so the page with the form can be.
- A notice without JavaScript in the visitor's first browser language, or nothing for a language without a message.
- Queued abuse reports to the abuse contact of the sender's registrar (RDAP), with cc to you and bcc to the sender, limited per domain and per day.
- A second report to the network the request came from (RDAP for the IP address): the hosting provider the bot runs at. Once per address per day; on by default (`network`).
- No blind copy to a sender whose domain has no MX record.
- Other reported domains on exactly the same nameservers are named in the report to the registrar.
- Optionally (`web_form_only`), abuse desks that only take a web form get no mail; you get the prepared report and the link instead. Off by default: they are mailed like any other.
- Laravel Boost guidelines and a skill.
