# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
While the project is in the `0.x` series the public API is not considered stable:
behaviour may change in any minor release.

## [0.2.0] - 2026-08-27

### Added

- **PHPStan at level 5, in CI.** `composer stan` locally, a dedicated job on
  every push, alongside the existing test matrix. The configuration lives in
  `phpstan.php`; its single ignore (redundant PHPUnit type assertions in tests)
  is documented in prose rather than carried as a baseline. It found the
  defects below.

- **Development files are excluded from dist archives.** `.gitattributes` now
  marks `tests/`, `phpstan.php`, `phpunit.xml.dist`, `.php-cs-fixer.php`,
  `.github/` and editor directories `export-ignore`, so a `composer require`
  no longer ships them.

### Fixed

- **An unsupported checksum algorithm escaped as an uncaught `ValueError`.**
  `hash_init()` was guarded against a `false` return it has not produced since
  PHP 8 — it raises `ValueError` instead — so the intended `TusException` was
  never thrown. Callers catching `ValueError` here should catch `TusException`;
  the original is chained as `$previous`. `hash_update_stream()` was checked the
  same way and now detects a failed read rather than never reporting one.

- Removed dead guards and redundant null handling found by PHPStan: an
  unreachable storage-backend check, an unreachable request-body check, `??` on
  non-nullable properties, and an unused closure capture. No behavioural change.

### Changed

- **`modufolio/http` constraint widened to `^0.1 || ^0.2`.** The package uses
  one class from it (`Http\Response`); the suite passes against both.

- **The `opcache.enable`/`opcache.enable_cli` ini directives were dropped from
  `phpunit.xml.dist`.** `opcache.enable_cli` is `PHP_INI_SYSTEM` and cannot be
  set at runtime, so the directive never took effect. The schema reference now
  matches the PHPUnit version in use.

## [0.1.0] - 2026-06-05

Initial public release.

[0.2.0]: https://github.com/modufolio/tus-psr7/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/modufolio/tus-psr7/releases/tag/v0.1.0
