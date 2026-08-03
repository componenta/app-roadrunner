# Compatibility

The package targets:

- PHP `^8.4`;
- Componenta `app` and `app-http` development branches;
- Componenta `config`, `di`, `middleware-factory`, `path-resolver`, `pipeline`,
  and `scope` `^1.0`;
- PSR-7 v2, PSR-17 v1, and PSR-15 v1;
- `spiral/roadrunner-http` `^4.1`;
- `spiral/roadrunner-worker` `^3.5`.

The active Componenta scope remains `Scope::HTTP`. No Componenta source patch is
required: `ConfigProvider` replaces Componenta's concrete `AppFactory` service.
The standard `componenta/app-http` adapter accepts applications that already
implement `HttpBootTargetInterface`. The RoadRunner factory delegates to
Componenta's default factory outside `RR_MODE=http`, preserving the standard
SAPI and CLI applications.

The package uses only public Componenta and RoadRunner contracts. Cycle is not a
dependency and its private state is never inspected. Applications using mutable
Cycle services must register an application or Cycle-owned
`RequestResetterInterface` implementation.
