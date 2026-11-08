# Working on Spam Strike

A Laravel package. Users install it with `composer require codingagency/laravel-spam-strike`. Laravel Boost picks up `resources/boost/guidelines/core.blade.php` and `resources/boost/skills/spam-strike/SKILL.md`; keep both in step with the code.

- Tests: `vendor/bin/pest`. Style: `vendor/bin/pint`. Both must pass before a commit.
- Supports PHP 8.3+ and Laravel 12 and 13. Add no dependency beyond `illuminate/*`.
- Every change of behaviour gets a test and a line in `CHANGELOG.md`.
- The page around a protected form must stay cacheable: nothing per visitor is rendered into it.
- Abuse reports go to third parties. Keep them queued, limited per domain and per day, and never send one for a submission that had a valid token.
