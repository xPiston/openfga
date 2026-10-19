# Changelog

All notable changes to this package are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this package adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- **The Symfony voter brought the container down on Symfony 7.3 and later.**
  `Voter::voteOnAttribute()` gained a fourth parameter there - a `Vote` the
  voter may annotate with its reason - and Symfony 8 made that signature the
  only compatible one, so `OpenFgaVoter` was a fatal error at compile time
  rather than a failing check. It now ends in a variadic, which satisfies 6.4
  through 8 alike. Nothing to change in an application: the voter is wired the
  same way and votes the same.

### Changed

- **The development constraints now allow Laravel 13, Symfony 8 and
  PHPUnit 13.** `composer.json` claimed support for Laravel 13, but
  `orchestra/testbench: ^10.0` pinned the resolver to Laravel 12 - the major
  of testbench follows the major of Laravel - so the `highest` CI job had
  never once run against the version the package advertised. Symfony had to
  come along with it, since Laravel 13 depends on Symfony 8. Nothing in
  `require` moved: this is still PHP 8.2 and PSR interfaces, and no consumer's
  resolution changes.

## [0.1.0] - 2026-10-13

First release. A PHP SDK for [OpenFGA](https://openfga.dev), usable from
Laravel, Symfony or plain PHP — and the only one in PHP that parses OpenFGA's
DSL.

There is no official OpenFGA SDK for PHP, and the community one
(`evansims/openfga-php`) was archived on GitHub and marked abandoned on
Packagist in 2025. This starts from PHP 8.2, where that one required 8.3.

### Added

- **A DSL parser and writer** — `.fga` text to the JSON the API accepts, and
  back. Written against OpenFGA's published ANTLR grammar
  (`OpenFGAParser.g4`, `OpenFGALexer.g4`) rather than against examples.
  `Dsl::parse()`, `Dsl::file()`, `Dsl::toJson()`, `Dsl::fromJson()`,
  `Dsl::stringify()`, and `models()->write()` taking DSL text directly.
  Syntax errors carry a line, a column and a caret.
- **Queries** — `check`, `batchCheck` (answers returned in the order asked,
  not the order the server replies in), `listObjects`, `streamListObjects`
  (a generator over the newline-delimited stream, with no result cap),
  `listUsers`, and `expand` with `users()` and `usersets()` kept apart.
- **Tuples** — transactional `write` of writes and deletes together, `grant`,
  `revoke`, paginated `read`/`readAll`, and `changes`/`changesSince` for
  mirroring OpenFGA into something else.
- **Conditions (ABAC)** — conditional grants with stored context, and
  per-check context, so one tuple can answer differently at different times.
- **Contextual tuples** — hypothetical tuples for one question, written
  nowhere.
- **Models** — `write`, `read`, `list`, `all`, `latest`. Model versions are
  append-only, and the facade is shaped to encourage pinning an id.
- **Stores** and **assertions**.
- **Three authentication modes** — none (the development default),
  pre-shared key, and OAuth2 client credentials with a cached token refreshed
  early, with jitter so a fleet does not stampede the issuer at expiry.
- **Laravel bridge** — auto-discovered provider, publishable config, a
  `Gate::before` hook, `fga:` route middleware, and an Eloquent trait. The
  hook **abstains** for anything not implementing `FgaObject`.
- **Symfony bridge** — `AbstractBundle` with a validated configuration tree
  and a Security voter, so `#[IsGranted('viewer', subject: 'document')]`
  works. The voter abstains for `ROLE_*`, `PUBLIC_ACCESS` and any subject
  that did not opt in.
- **Typed exceptions** — `ValidationError`, `Unauthenticated`, `NotFound`,
  `RateLimited`, `TransportFailed`, `InvalidArgument`, all implementing
  `OpenFgaException` and carrying OpenFGA's own `code` and `message`.
- **PSR-18 / PSR-17 throughout**, with optional discovery, and no hard
  dependency on any HTTP client.
- **355 tests** plus **30 against a live server**, PHPStan at level `max`,
  and CI across PHP 8.2 → 8.5, a Symfony 6.4 tree with no Laravel in it, and
  a job that starts a real OpenFGA container and runs the live suite.

### Verified

- The DSL parser reproduces OpenFGA's reference transformer on **29/29** of
  its own fixtures, in three directions (DSL → JSON, JSON → DSL → JSON,
  DSL → DSL → JSON), and refuses the **50** syntax-error cases in their
  validation corpus. The fixtures are vendored with attribution under
  `tests/Conformance/fixtures/`.
- Every model in those fixtures was deployed to a live **OpenFGA v1.21.0**,
  read back and compared. Three of them are refused by the server on
  *semantic* grounds — an undeclared CEL reference, a tupleset that is not a
  direct relation, an invalid relation type — which is the division this
  package keeps: syntax here, semantics there.
- Conditions are evaluated by the server's real CEL engine in the live suite:
  the same tuple allowed at 09:30 and denied at 11:00.
- The OAuth2 exchange follows the shape the official SDKs send and is
  asserted against a fake issuer. It has **not** been run against a real
  identity provider — the one thing in this release that is
  conformant-by-reading rather than measured.

### Notes

Several behaviours here were measured against a live server rather than taken
from the documentation, because the two differ:

- **`listUsers` accepts exactly one user filter.** The field is an array and
  the OpenAPI definition declares no constraint, but the server answers two
  elements with *"value must contain exactly 1 item(s)"*. The signature takes
  a string, so a list cannot be passed.
- **Timestamps carry nanoseconds.** OpenFGA is written in Go and emits nine
  fractional digits; PHP's `u` format specifier means six, so
  `createFromFormat` returns false on a real response. `Model\Timestamp`
  handles any precision, and refuses `"tomorrow"` and impossible dates like
  `2026-02-30`, which a plain `new DateTimeImmutable` accepts.
- **An empty request body must be `{}`, not `[]`.** `json_encode([])`
  produces a JSON array, and the server answers one with *"syntax error (line
  1:1): unexpected token ["*.
- **Reading a model back yields protobuf zero values** — `condition: ""`,
  `module: ""`, `source_info: null`, `conditions: {}` — all of which mean
  "absent".
- **Store names are 3 to 64 characters** of letters, digits, whitespace and
  `. - / ^ _ & @`, enforced here so the failure names the problem.
- **`document:*` is not a valid object** even though `user:*` is a valid
  user, which is why `User` and `TypedObject` are separate types.
- **Tuple object ids may contain a comma.** OpenFGA's own DSL test corpus
  marks `a,b` invalid, and the tuple API accepts it. A client stricter than
  its server refuses writes the server would have taken, so the server wins.

Deliberately out of scope: modular models (`module`/`extend`, recognised and
refused with a message), a CEL parser, semantic model validation, the AuthZEN
endpoints added in OpenFGA 1.21, private-key JWT client assertions, and any
retry or caching layer.

[Unreleased]: https://github.com/xPiston/openfga/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/xPiston/openfga/releases/tag/v0.1.0
