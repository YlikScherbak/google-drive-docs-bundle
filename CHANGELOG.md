# Changelog

All notable changes to this project are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- PSR-14 events for every write operation (`DocumentCreatedEvent`, `FolderCreatedEvent`,
  `DocumentRenamedEvent`, `DocumentMovedEvent`, `DocumentDeletedEvent`, `AccessGrantedEvent`,
  `AccessRevokedEvent`) so auditing and notifications can live outside the bundle
- Test suite (PHPUnit) covering visibility filtering, parent-chain access checks,
  sharing behaviour, folder/document creation and configuration handling
- Continuous integration on GitHub Actions (PHP 8.1–8.3 × Symfony 6.4/7) and PHPStan analysis

## [0.1.0] - 2026-08-24

### Added
- Browse folders and documents of a Google Shared Drive, navigate any depth, search by name
- Create, rename, move and delete documents and folders
- Per-item sharing management (`reader` / `commenter` / `writer`)
- Per-user visibility filtering driven by Google sharing, with ancestor-folder inheritance
- Inherited permissions are flagged; revoking them raises a dedicated exception
- OAuth refresh-token authentication and a console command to obtain the token
- `ViewerContextInterface` extension point so the host application decides who sees what

[Unreleased]: https://github.com/YlikScherbak/google-drive-docs-bundle/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/YlikScherbak/google-drive-docs-bundle/releases/tag/v0.1.0
