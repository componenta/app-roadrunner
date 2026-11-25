# Changelog

## Unreleased

- Distinguish request validation from unsafe transport receive failures.
- Avoid silent truncation of stalled streams and body consumption for HEAD.
- Preserve valid 304 metadata and normalize conflicting framing headers.
- Advertise range support only when supplied by the application.
- Honor PHP error masks and protect the persistent output guard.
- Report all cleanup failures and validate aggregate exceptions.
- Share runtime detection and use the public RoadRunner streaming API.

## 3.0.0 - 2026-09-19

- Require Componenta App 5, App HTTP 3 and DI 5.0.3 or newer compatible releases.
- Integrate via the shared HTTP application adapter.

## 2.0.0

- Align dependency requirements for the coordinated Componenta release.

## 1.1.2

- Add verified release workflow and runtime compatibility updates.

## 1.1.0 - 2026-08-09

- Declared stable compatibility with Componenta App 2, Config 2, and DI 3 while
  preserving support for their existing major versions.

## 1.0.0 - 2026-08-08

- Replaced the removed Componenta app-adapter contract with a conditional
  `AppFactoryInterface` implementation.
- Removed the RoadRunner-specific boot-target adapter; the standard
  `componenta/app-http` adapter now accepts HTTP-capable applications directly.
- Made the RoadRunner `App` its own `HttpBootTargetInterface` implementation.
- Removed manual runtime modes; `RR_MODE=http` is now the only valid RoadRunner
  activation signal, with the standard SAPI app preserved otherwise.
- Replaced process-wide stabilization, Reflection-based Cycle cleanup, and
  speculative PHP state restoration with an ordered list of explicit
  `RequestResetterInterface` services.
- Kept automatic Componenta current-user cleanup and request output-buffer
  cleanup.
- Changed the default RoadRunner response chunk size to `0` so non-seekable
  PSR-7 bodies work with the official worker without a transport workaround.
- Replaced the dependency-free stub harness with tests against installed
  Componenta and RoadRunner packages.
