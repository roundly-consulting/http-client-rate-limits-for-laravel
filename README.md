<!-- roundly-hero:start -->
<p align="center">
  <a href="https://roundly-consulting.com/open-source/docs/http-client-rate-limits-for-laravel?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=http-client-rate-limits-for-laravel">
    <img src="https://raw.githubusercontent.com/roundly-consulting/http-client-rate-limits-for-laravel/main/art/hero.png" alt="HTTP Client Rate Limits for Laravel — Roundly open source" width="100%">
  </a>
</p>
<!-- roundly-hero:end -->

<!-- roundly-badges:start -->
<p align="center">
  <a href="https://packagist.org/packages/roundly-consulting/http-client-rate-limits-for-laravel"><img src="https://img.shields.io/packagist/v/roundly-consulting/http-client-rate-limits-for-laravel?style=flat-square&label=release" alt="Latest release"></a>
  <a href="https://github.com/roundly-consulting/http-client-rate-limits-for-laravel/actions/workflows/run-tests.yml"><img src="https://img.shields.io/github/actions/workflow/status/roundly-consulting/http-client-rate-limits-for-laravel/run-tests.yml?branch=main&style=flat-square&label=tests" alt="Tests"></a>
  <a href="https://github.com/roundly-consulting/http-client-rate-limits-for-laravel/actions/workflows/fix-php-code-style-issues.yml"><img src="https://img.shields.io/github/actions/workflow/status/roundly-consulting/http-client-rate-limits-for-laravel/fix-php-code-style-issues.yml?branch=main&style=flat-square&label=code%20style" alt="Code style"></a>
  <a href="https://donate.stripe.com/dRmeVe8FX5PF1Qd9pXcEw00"><img src="https://img.shields.io/badge/donate-support%20our%20open%20source-F24E29?style=flat-square&logo=stripe&logoColor=white" alt="Donate"></a>
  <a href="https://www.patreon.com/cw/roundly"><img src="https://img.shields.io/badge/patreon-become%20a%20patron-F96854?style=flat-square&logo=patreon&logoColor=white" alt="Patreon"></a>
  <a href="https://roundly-consulting.com/support-us?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=http-client-rate-limits-for-laravel#crypto"><img src="https://img.shields.io/badge/crypto-BTC%20%C2%B7%20ETH%20%C2%B7%20BNB%20%C2%B7%20SOL-F7931A?style=flat-square&logo=bitcoin&logoColor=white" alt="Crypto"></a>
</p>
<!-- roundly-badges:end -->

# HTTP Client Rate Limits for Laravel

Rate limit the outgoing requests you make with Laravel's HTTP client, so you never blow past a
third-party API's quota. When a budget is spent the request waits for the next free slot
instead of earning a `429`; compound windows, per-owner budgets, server-adaptive limits and
shared stores come built in.

## Installation

Requires PHP 8.4 and Laravel 12 or 13.

```bash
composer require roundly-consulting/http-client-rate-limits-for-laravel
```

The default `InMemoryStore` counts per process. With several workers, share one budget by
setting `HTTP_CLIENT_RATE_LIMITS_STORE` to the `CacheStore`, `RedisStore` or `DatabaseStore`
class (the last one needs its tables:
`php artisan vendor:publish --tag="http-client-rate-limits-migrations"`, then `migrate`).

## Usage

Throttle any HTTP client call with the `rateLimit()` macro and the `RateLimits` facade:

```php
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\HttpClientRateLimits\Facades\RateLimits;

// 5 requests per second; the sixth waits for a free slot, then goes out.
Http::rateLimit(RateLimits::perSecond(5))->get('https://api.example.com/things');

// 30 per minute, with a separate budget per account.
Http::rateLimit(30, by: 'acct-1')->get('https://api.example.com/orders');
```

Combine windows, follow the server's own limits and cap the wait:

```php
$limit = RateLimits::perSecond(5)
    ->alongside(RateLimits::perMinute(100))   // both windows apply; the strictest wins
    ->adaptive()                              // honour Retry-After / X-RateLimit-* headers
    ->maxWait(5_000);                         // throw RateLimitExceededException past 5 s

Http::rateLimit($limit)->get('https://api.example.com/report');

$limit->remaining();                          // requests left in the window, without sending one
```

<!-- roundly-docs:start -->
## Documentation

The full documentation — configuration, every feature and its API, and testing — lives on our
website: **[roundly-consulting.com/open-source/docs/http-client-rate-limits-for-laravel](https://roundly-consulting.com/open-source/docs/http-client-rate-limits-for-laravel?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=http-client-rate-limits-for-laravel)**

Release notes are in [CHANGELOG.md](CHANGELOG.md). To contribute, see the
[contributing guide](https://github.com/roundly-consulting/.github/blob/main/CONTRIBUTING.md).
<!-- roundly-docs:end -->

<!-- roundly-support:start -->
## Support our work

This package is free and open source, built and maintained by
[Roundly Consulting](https://roundly-consulting.com/open-source?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=http-client-rate-limits-for-laravel).
If it saves you time, please consider supporting our open-source work — a one-time donation, a
monthly pledge on Patreon or a crypto donation helps fund maintenance, new features and new
packages.

<a href="https://donate.stripe.com/dRmeVe8FX5PF1Qd9pXcEw00"><img src="https://img.shields.io/badge/Donate-Support%20Roundly%20open%20source-F24E29?style=for-the-badge&logo=stripe&logoColor=white" alt="Donate to Roundly open source"></a>
<a href="https://www.patreon.com/cw/roundly"><img src="https://img.shields.io/badge/Patreon-Become%20a%20patron-F96854?style=for-the-badge&logo=patreon&logoColor=white" alt="Become a patron on Patreon"></a>
<a href="https://roundly-consulting.com/support-us?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=http-client-rate-limits-for-laravel#crypto"><img src="https://img.shields.io/badge/Crypto-BTC%20%C2%B7%20ETH%20%C2%B7%20BNB%20%C2%B7%20SOL-F7931A?style=for-the-badge&logo=bitcoin&logoColor=white" alt="Donate crypto: BTC, ETH, BNB or SOL"></a>
<!-- roundly-support:end -->

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
