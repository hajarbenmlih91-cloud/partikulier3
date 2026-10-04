# Changelog — Partikulier 3

All notable changes to this monorepo (the `partikulier-core` plugin, the `partikulier` theme, tooling and documentation) are recorded here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the components follow [Semantic Versioning](https://semver.org/).

Releases ship as a **plugin-theme** pair. Up to 2.10.8-6.20.7 they were tagged `v<plugin>-<theme>`; from 2.10.9-6.20.8 on, `pre-release-<plugin>-<theme>` deploys to UAT and `release-<plugin>-<theme>` deploys to production. Component-level details live in:

- [`plugin/partikulier-core/CHANGELOG.md`](plugin/partikulier-core/CHANGELOG.md)
- [`theme/partikulier/CHANGELOG.md`](theme/partikulier/CHANGELOG.md)

---

## [Unreleased]

## [2.10.10-6.20.9] - 2026-10-04

### Added
- Explicit demo installer with 30 listings, bundled images and optional Polylang variants.
- Moroccan phone login, existing-account protection, configurable admin login gateway and opt-in TOTP 2FA.
- Focused merge regression contracts for authentication, health, lead transactions, demo cleanup and responsive browser behavior.

### Fixed
- Bounded deadlock retries and checked SQL operations throughout contact authorization.
- Taxonomy lookup errors no longer crash lead snapshots.
- Health returns uncached HTTP 503 for degraded/critical states and stops DB-backed diagnostics when the database is unavailable; anonymous responses exclude operational details.
- Existing n8n shared-secret webhook headers retained alongside HMAC signatures.
- Language-specific navigation, localized form validation and scoped Estatik authentication-popup suppression.
- Contact-card contrast, mobile gallery/card/deposit layout and above-the-fold image loading.

### Changed
- Integrated delivery commit `f502d43` into `fixes-v3`, retaining Docker, deployment approvals/tag rules, database backups, user documentation and existing contract fixes.
- Plugin version raised to 2.10.10 to distinguish this functional release from the already published 2.10.9.
- Optional admin gateway has no built-in access key; password recovery remains available. 2FA setup never sends seeds to an external QR provider.
- Delivery performance reports remain historical evidence, not a production SLO certification of the merged source.

## [2.10.9-6.20.8] - 2026-10-03

Tooling, CI/CD and documentation release. No functional change to the plugin or the theme: only the version numbers are bumped (plugin 2.10.9, theme 6.20.8) and the contract tests are aligned with them.

### Added
- **Tag-based deployments**: `pre-release-<version>` deploys to **uat**, `release-<version>` deploys to **prd**, push to `develop` deploys to **dev**. A new `resolve` job validates the tag against `^(pre-)?release-[0-9]+\.[0-9]+\.[0-9]+(-.+)?$` and fails before any publication if it does not match.
- **UAT approval**: uat (and prd) deploy jobs wait for the GitHub environment's *Required reviewers* before reading any secret.
- **Site URL in deploy logs**: logged at start and end (`::notice`), written to the run summary and exposed as the environment URL.
- **User documentation** (`docs/user-stories/`): detailed FR/EN user stories by role with acceptance criteria, and a simplified FR/EN user guide.
- **Test plan** (`docs/user-stories/cahier-de-tests.html`, in French): 47 acceptance test cases (visitor/buyer, depositor, refused agent, owner, administrator, system actors). Each case gives the user type, an execution prompt, the expected result, an SQL check and a Playwright skeleton. A traceability matrix links each case to its user story.
- **Deployment environments** dev / uat / prd and a template for local secrets.
- Root `CHANGELOG.md` (this file); `docs/user-stories/README.md` updated.

### Changed
- **Deployment**: push to `main` no longer deploys to uat; `v*` tags no longer deploy to prd (see `docs/DEPLOIEMENT.md`).
- HTML pages in `docs/user-stories/` aligned with the theme's design system: cognac palette, local DM Sans font, square corners, editorial hero and role-based navigation.
- Code restructured for readability (no functional change announced).

### Fixed
- **Deployment**: database backup now uses `mysqldump`, because `proc_open` is disabled on Hostinger.
- **Lint**: `*.example` templates are no longer flagged as forbidden artifacts.

## [2.10.8-6.20.7-final] - 2026-09-28

Official consolidation; all 18 acceptance scenarios signed off.

### Added
- **Listing deactivation / reactivation** (SE-044 / DP-9): reasons sold, rented, no longer wanted and other, with a server-side transition matrix.
- **Consolidation**: field builder SE-042c (max 8 fields), public premium at 0 MAD (SE-048-R), 100% WhatsApp contact on listing pages (SIM-07), bilingual place-name engine.
- **Security**: rewrite-prefix hardening (SE-027), XML-RPC hardening (SE-041) and diagnostics cleanup (SE-032).

### Fixed
- Page cache: reliable purge and isolation of logged-in areas.
- Translation variants compatible with PHP 8.1; `VariantTransitionsTrait` restored.
- CI: REST lite registry, available-listings filtering, DP-9 harness (mu-plugins, APCu).
- Canonical i18n catalogues and deposit-form split restored; premium contract recalibrated.

## [2.10.8-6.20.7] - 2026-09-19

Pre-production micro-batch (10 batches + SE-025).

### Added
- Schema 2.7.0: `pk_slug_redirects` table and SlugRedirects API.
- SE-025: trilingual Polylang workflow, 9 URLs × 3 languages contract, RTL and hreflang.
- SE-035: complete EN/AR catalogues with deterministic generation.
- SE-036: trilingual "Cities & districts" screen, complete Arabic reference data and CSV import.
- SE-037: sitemap × 3 languages with hreflang clusters.
- SE-054: "sold listing" experience (3 similar listings and prefilled WhatsApp).

### Fixed
- SE-024: deduplicated i18n dictionaries.
- SE-026: missing `hmac_mode` warning in automation.
- SE-034: idempotency of the public deposit channel.
- SE-043: explicit 404/410 geographic resolution and 301 redirects for old slugs.
- SE-048-U: per-listing `premium_granted` audit.

## [2.10.7-6.20.6] - 2026-09-16

Isolated security batch.

### Security
- HMAC bypass fixed (E-4905) and audit in `enforce` mode (E-4901).
- GDPR cascade extended to `pk_saved_alerts` and `pk_alert_deliveries` (E-5501 to E-5503).
- A non-scalar search parameter no longer returns the whole catalogue (E-5105).

## [2.10.6-6.20.5] - 2026-09-15

### Fixed
- SE-022: per-cycle idempotency of REST guards and release hygiene.

### Changed
- `phpcbf` style pass (SE-019), no functional change, referenced in `.git-blame-ignore-revs`.
- Versions and tooling pins aligned (package script, contrats-recette workflow, README).

## [2.10.5-6.20.4] - 2026-09-13

First versioned pair of the monorepo after the B1→B6 evidence campaign (tag `preuves-b1-b6`, 2026-09-10). See [`docs/RELEASE.md`](docs/RELEASE.md).

[Unreleased]: https://github.com/hajarbenmlih91-cloud/partikulier3/compare/fixes-v3...HEAD
[2.10.10-6.20.9]: https://github.com/hajarbenmlih91-cloud/partikulier3/compare/pre-release-2.10.9-6.20.8...fixes-v3
[2.10.9-6.20.8]: https://github.com/hajarbenmlih91-cloud/partikulier3/compare/v2.10.8-6.20.7-final...pre-release-2.10.9-6.20.8
[2.10.8-6.20.7-final]: https://github.com/hajarbenmlih91-cloud/partikulier3/compare/v2.10.8-6.20.7...v2.10.8-6.20.7-final
[2.10.8-6.20.7]: https://github.com/hajarbenmlih91-cloud/partikulier3/compare/v2.10.7-6.20.6...v2.10.8-6.20.7
[2.10.7-6.20.6]: https://github.com/hajarbenmlih91-cloud/partikulier3/compare/v2.10.6-6.20.5...v2.10.7-6.20.6
[2.10.6-6.20.5]: https://github.com/hajarbenmlih91-cloud/partikulier3/compare/v2.10.5-6.20.4...v2.10.6-6.20.5
[2.10.5-6.20.4]: https://github.com/hajarbenmlih91-cloud/partikulier3/releases/tag/v2.10.5-6.20.4
