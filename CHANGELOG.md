# Changelog

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
