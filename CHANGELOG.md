# Changelog

## Unreleased

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
