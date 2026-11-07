# Changelog

All notable changes to this package are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- The release workflow takes its notes from this changelog, and fails when a tag has no entry.

## [0.3.0] - 2026-11-07

### Added

- `SovereigntyUnavailableException` for a 503 `sovereignty_unavailable` and `RouterUnavailableException` for a 503 `team_router_unavailable`. Both extend `ModelUnavailableException`, so existing catch blocks keep working.
- A `c-point-labs/laravel-hostyourai/{version}` User-Agent on all requests.
- README sections on EU only and data handling and on where to get help, the beta label of the decision API, and links to registration and API keys.

### Changed

- **Breaking:** provider options that have no effect now raise an `InvalidRequestException` that names them, instead of being ignored. This includes `reasoning` outside a stream, `schema.strict` outside structured output, and values that aren't true or false.
- The README's privacy row says "This package logs nothing", so it can't be read as a claim about HostYourAI.
- The Composer description and social preview say "Not affiliated with HostYourAI B.V."

## [0.2.1] - 2026-11-07

### Added

- `SovereigntyViolationException` for a 403 `sovereignty_violation`, also inside a stream.
- A release workflow that creates a GitHub release for every pushed `v*` tag.

### Fixed

- Inside a stream, `team_model_budget_exceeded`, `rate_limit_exceeded`, `team_router_unavailable` and `loop_detected` now get their documented status and exception. They used to be a `ServerException` with status 500.

## [0.2.0] - 2026-11-06

### Changed

- **Breaking:** streams leave reasoning out unless you pass `withProviderOptions(['reasoning' => true])`. They then emit Prism's own `ThinkingEvent`.
- **Breaking:** documents, audio and video raise `InvalidRequestException` instead of being dropped without a word.
- **Breaking:** a balance with a missing field raises `UnexpectedResponseException` instead of reading as zero.
- Numeric criteria names are refused, because JSON would lose them.
- The fake rejects answers and requests the real API could not handle.
- Classes that aren't public API are marked `@internal`.
- CI also tests the lowest dependency versions.

### Removed

- **Breaking:** `PrivateThinkingEvent`.

### Fixed

- Errors inside a 200 stream map by their code.

## [0.1.1] - 2026-11-06

### Changed

- The README installs from Packagist, opens with an example and has a logo and banner.

### Fixed

- Configured retries apply to Prism calls. Prism's default of `[0]` used to replace them.
- A stalled or trickling stream stops at `stream_timeout`.
- A broken connection in a stream raises a `ConnectionException` instead of a plain `RuntimeException`.
- A stream finishes at `[DONE]`, also when the connection stays open.

## [0.1.0] - 2026-11-06

### Added

- A Prism provider for HostYourAI chat, with text, streaming, tool calling and structured output.
- A typed client for the decision API, with Choice, Noul and Score questions. Ensembles are not supported yet.
- Typed exceptions with `Retry-After` support.
- A test fake for decisions, balance and models.

[Unreleased]: https://github.com/C-Point-Labs/laravel-hostyourai/compare/v0.3.0...HEAD
[0.3.0]: https://github.com/C-Point-Labs/laravel-hostyourai/compare/v0.2.1...v0.3.0
[0.2.1]: https://github.com/C-Point-Labs/laravel-hostyourai/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/C-Point-Labs/laravel-hostyourai/compare/v0.1.1...v0.2.0
[0.1.1]: https://github.com/C-Point-Labs/laravel-hostyourai/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/C-Point-Labs/laravel-hostyourai/releases/tag/v0.1.0
