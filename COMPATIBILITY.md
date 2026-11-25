# Compatibility

The Composer manifest is the authoritative dependency contract.

| Dependency | Supported version |
|---|---|
| PHP | ^8.4 |
| componenta/app | ^5.0.0 |
| componenta/app-http | ^3.0.0 |
| componenta/config | ^3.0.0 |
| componenta/di | ^5.0.3 |
| componenta/middleware-factory | ^2.1.0 |
| componenta/path-resolver, componenta/pipeline, componenta/scope | ^1.0 |
| PSR-11 | ^2.0 |
| PSR-7 | ^2.0 |
| PSR-17 | ^1.0.1 |
| PSR-15 middleware | ^1.0 |
| spiral/roadrunner-http | ^4.1 |
| spiral/roadrunner-worker | ^3.5 |

The provider decorates the standard HTTP application adapter rather than
replacing the shared application factory. The active scope remains Scope::HTTP.
The RoadRunner application implements HttpBootTargetInterface directly.
The compatibility AppFactory delegates to the default factory outside an HTTP
worker.

Cycle is not a dependency. Applications using mutable Cycle services must
register an application-owned resetter. No private state is inspected.

A compatible Composer solve and passing package tests are required before
releasing against a new dependency set. See the release workflow.
