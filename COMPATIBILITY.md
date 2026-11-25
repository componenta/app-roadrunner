# Compatibility

The package targets:

- PHP `^8.4`;
- Componenta `app` and `app-http` development branches;
- Componenta `config`, `di`, `middleware-factory`, `path-resolver`, `pipeline`,
  and `scope` `^1.0`;
- PSR-7 v2, PSR-17 v1, and PSR-15 v1;
- `spiral/roadrunner-http` `^4.1`;
- `spiral/roadrunner-worker` `^3.5`.

The active Componenta scope remains `Scope::HTTP`. The provider selects an
alternative HTTP runtime service without replacing the shared application factory.
The runtime factory constructs only the selected RoadRunner or SAPI application.
The standard HTTP boot target wraps both implementations and never exposes
their run entry points. CLI and WebSocket registrations are independent.

The package uses only public Componenta and RoadRunner contracts. Cycle is not a
dependency and its private state is never inspected. Applications using mutable
Cycle services must register an application or Cycle-owned
`RequestResetterInterface` implementation.
