# Contributing

Thanks for helping to make life a little harder for spammers. 🥊

## Ideas we would love to see

- More abuse desks and their quirks, such as desks that only take a web form.
- Smarter detection that never gets in the way of a real person.
- Reports to more places that actually act on them.
- Translations of the notice without JavaScript (`config/spam-strike.php`, `noscript.messages`).
- That one spam message so absurd it deserves its own test.

## How

1. Fork the repository and create a branch.
2. `composer install`
3. Write a test for the change, then make it pass: `vendor/bin/pest`
4. Format the code: `vendor/bin/pint`
5. Add a line to `CHANGELOG.md` and open a pull request.

To try the package in a browser, run `vendor/bin/testbench serve` and open `/form`. It shows a plain form and a form sent with `fetch()`, both protected.

## Ground rules

- Never get in the way of a real person. A false positive costs a site owner a customer.
- Reports go to third parties, based on an address the sender typed. They stay queued and limited per domain, per address and per day, and a submission with a valid token is never reported.
- No dependencies beyond `illuminate/*`.
- The page around a protected form stays the same for every visitor, so it can be cached.

## Security

Found a way around the protection that a real spammer could use? Please mail contact@coding.agency instead of opening a public issue, so we can fix it before the bots read about it.
