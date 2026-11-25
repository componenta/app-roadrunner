# Componenta App RoadRunner

`componenta/app-roadrunner` runs Componenta's existing HTTP application pipeline
inside a RoadRunner worker. It keeps `Componenta\App\Scope::HTTP`, the normal
HTTP bootloaders, middleware configuration, routing, and error handling.

The package owns only the RoadRunner transport lifecycle. Application services
that retain request data must expose their own cleanup through
`RequestResetterInterface`; this package does not inspect private framework,
Cycle, or PHP runtime state.

A Russian guide is available in [README.ru.md](README.ru.md).

## Installation

```bash
composer require componenta/app-roadrunner:^3.0
composer require --dev spiral/roadrunner-cli:^2.7
vendor/bin/rr get-binary
```

Copy the worker entry point and the example server configuration into the
application:

```bash
cp vendor/componenta/app-roadrunner/resources/bin/roadrunner.php bin/roadrunner.php
cp vendor/componenta/app-roadrunner/resources/.rr.yaml .rr.yaml
```

The Componenta Composer plugin discovers
`Componenta\App\RoadRunner\ConfigProvider` from the package metadata. If the
application maintains its provider list manually, add this provider after the
standard `Componenta\App\Server\ConfigProvider`.

Rebuild Componenta's production cache after installing the package or changing
its configuration:

```bash
php bin/console.php app:build
```

Build and publish caches before starting or reloading the worker pool. Workers
must only read completed shared artifacts; they must not race to generate config,
container, discovery, route, DI, or ORM caches during concurrent boot. Restart
all workers after a cache or code deployment so no long-lived container keeps
the previous release.

Start the server:

```bash
./rr serve -c .rr.yaml
```

## Runtime selection

The provider decorates `Componenta\App\Server\HttpAppAdapter` through DI with
`Componenta\App\RoadRunner\HttpAppAdapter`. The shared application factory selects
the HTTP adapter normally. It creates the RoadRunner app when `RR_MODE=http` and
delegates to the original HTTP adapter outside a worker. Provider order does not
change this selection; CLI and WebSocket adapters retain their behavior. The RoadRunner app implements
`HttpBootTargetInterface` directly, and the standard `componenta/app-http`
adapter uses it without an integration-specific adapter.

There is no separate RoadRunner scope and no manual `always` mode. Starting the
RoadRunner app without the RoadRunner worker protocol would be invalid, while
forcing the SAPI app inside a worker would corrupt that protocol.

## Request lifecycle

The RoadRunner app:

1. builds the configured PSR-15 middleware pipeline once;
2. refreshes RoadRunner's `$_SERVER` baseline after Componenta boot completes;
3. receives PSR-7 requests through the official `PSR7Worker`;
4. handles every request through the same Componenta pipeline;
5. prepares and sends the PSR-7 response;
6. runs configured request resetters in declaration order;
7. clears output buffers opened by the request;
8. accepts the next request.

Middleware priority and equal-priority registration order match
`componenta/app-http`.

An unhandled pipeline exception becomes an empty generic `500` response. Recognized invalid
request data becomes an empty generic `400`. Unexpected receive failures are
not answered because the relay may be out of sync. A transport or cleanup
failure exits the worker with code `1`, allowing RoadRunner to replace a process
whose state is no longer trusted.

## Request state

Mutable shared services register an explicit resetter. DI 5 has no current-user
provider; applications own user context and its cleanup:

```php
namespace App\Infrastructure\Reset;

use App\Tenant\TenantContext;
use Componenta\App\RoadRunner\Reset\RequestResetterInterface;

final readonly class TenantContextResetter implements RequestResetterInterface
{
    public function __construct(private TenantContext $context) {}

    public function reset(): void
    {
        $this->context->clear();
    }
}
```

Register its service id in execution order:

```php
return [
    'roadrunner' => [
        'resetters' => [
            App\Infrastructure\Reset\TenantContextResetter::class,
        ],
    ],
];
```

All resetters are attempted even if one fails. Failures are aggregated into
`ResetFailedException`, and the worker exits after cleanup.

Cycle transactions, `EntityManager`, ORM heap, tenant context, locale, profiler
state, and similar mutable services belong to their owning integration. Register
resetters for the services used by the application. Do not store request objects
or users in static properties or long-lived controller properties. Keep
RoadRunner's `max_jobs` limit as a final guard against third-party leaks and
memory fragmentation.

## Configuration

```php
return [
    'roadrunner' => [
        'http' => [
            'chunk_size' => 0,
        ],
        'worker' => [
            'intercept_side_effects' => true,
        ],
        'resetters' => [],
    ],
];
```

- `roadrunner.http.chunk_size` controls RoadRunner's streamed PSR-7 response
  mode. The default `0` materializes the body before sending it through the official HTTP transport.
  Positive values stream through RoadRunner's public HTTP transport. Non-seekable
  response bodies are buffered before sending; a stalled read fails instead of
  returning a truncated success. Large or unbounded non-seekable streams are
  therefore not suitable for low-memory streaming.
- `roadrunner.worker.intercept_side_effects` protects the Goridge protocol from
  accidental PHP output to stdout.
- `roadrunner.resetters` is an ordered list of container service ids implementing
  `RequestResetterInterface`.

A copyable configuration file is included at
`resources/config/autoload/roadrunner.global.php`.

## Response transport

`ResponsePreparer` preserves the observable HTTP response semantics for `HEAD`,
informational and bodyless statuses, full responses, `206 Content-Range`,
non-seekable streams, `X-Sendfile`, and `X-Accel-Redirect`. It performs
RoadRunner-specific transport normalization before the response crosses
Goridge, so internal PSR-7 headers and body objects are not guaranteed to be
identical to those passed to Componenta's SAPI emitter.

A `206` response must already contain the selected range in its PSR-7 body. The
preparer transfers that body without applying the representation offset again.
It preserves explanatory bodies on `416` responses and suppresses content on `205`.

To use `X-Sendfile`, enable RoadRunner's sendfile middleware:

```yaml
http:
  middleware: ["sendfile"]
```

## Public API

- `ConfigProvider` wires the integration into Componenta's application factory.
- `App` is the long-running HTTP application and also its HTTP boot target.
- `RequestResetterInterface` is the application cleanup extension point.
- `ResetFailedException` exposes all cleanup failures through its `failures`
  property.

HEAD responses do not consume non-seekable bodies to compute optional metadata.
Range support is advertised only if the application sets `Accept-Ranges`.
A 304 response preserves explicitly provided representation metadata, including
`Content-Length` when valid under RFC 9110. Conflicting transfer encoding and
content length are normalized by removing content length.

The bundled entry point uses a non-removable output guard and honors PHP's
error reporting mask. Direct writes to STDOUT are still forbidden. The runtime
checks the structure of persistent output buffers; it cannot identify every
replacement by an identical PHP handler. Application code must not modify
persistent runtime buffers or retain per-request state in other PHP globals.

The worker loop and factory are container-managed infrastructure; application
code normally interacts only with `RequestResetterInterface`.
