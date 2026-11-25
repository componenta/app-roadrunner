# Historical verification

The following results concern the 2026-08-03 development baseline, not the
3.0.0 release or subsequent audit fixes. They must not be treated as current
release acceptance evidence. The 3.0.0 release workflow passed at
https://github.com/componenta/app-roadrunner/actions/runs/35468780385.
New runtime and regression checks require a fresh dependency installation and
execution on PHP 8.4/8.5, plus real RoadRunner process tests.

Verification date: 2026-08-03

Environment:

- PHP 8.4.5 CLI;
- Composer 2.10.2;
- installed production and development dependencies from `composer.json`.

## Completed checks

- `composer validate --strict --no-check-lock`: passed;
- optimized Composer autoload generation with strict PSR and ambiguous-class
  checks: passed, 5,130 classes with the current Componenta development
  packages;
- PHP syntax validation for every non-vendor PHP file: passed;
- Pest against the current Componenta development packages: 30 tests,
  89 assertions, passed;
- PHPStan level `max`: passed with no errors;
- UTF-8/LF and accidental-artifact checks: passed after cleanup.
- process-level package smoke test through RoadRunner 2025.1.15: three HTTP
  requests completed in the same PHP worker, the middleware pipeline remained
  reusable, and per-request current-user cleanup ran before the next request;
- ecom process smoke through a four-worker RoadRunner pool: public categories
  remained `200`, anonymous account requests remained `401`, and ten fresh
  alternating pairs completed without transport or reset failures;
- ecom RoadRunner/SAPI parity smoke: status and response body matched for the
  public categories and anonymous account contracts, including identical
  SHA-256 body hashes;
- ecom production cache smoke: the cached container resolved the composite
  RoadRunner resetter with the application resetters registered.

The test suite verifies the public integration contract rather than private
implementation details. In particular, it covers:

- RoadRunner selection only when `RR_MODE=http`;
- unchanged delegation to Componenta's standard application factory in SAPI
  mode with the current scope-based configuration;
- application-factory resolution with both Componenta config merge orders in a
  real DI container;
- one reusable middleware pipeline across multiple requests;
- middleware ordering and per-request reset execution;
- generic `400` and `500` responses without leaking exception details;
- worker termination after transport or reset failures;
- output-buffer cleanup without closing a persistent buffer;
- declared resetter order, object deduplication, aggregate failure reporting,
  and automatic current-user cleanup;
- full, `HEAD`, bodyless, range, sendfile, transfer-encoding, malformed range,
  and non-seekable response cases;
- differential body parity against the real Componenta SAPI emitter for full,
  `205`, `206`, and non-seekable responses;
- chunked response transport through the installed official
  `Spiral\RoadRunner\Http\PSR7Worker` implementation.

## Not claimed

The ecom smoke covered anonymous read-only HTTP contracts. It did not exercise
authenticated identity transitions, writes, file uploads, streaming downloads,
or failure recovery against a production data set. The registered resetters and
their failure paths are covered by focused tests, but those scenarios still need
deployment-level monitoring.

RoadRunner performs transport-specific PSR-7 normalization. Public status,
headers, and body semantics are covered for the cases listed above; byte-for-byte
identity of the intermediate PSR-7 response object with Componenta's SAPI
emitter is neither required nor claimed.
