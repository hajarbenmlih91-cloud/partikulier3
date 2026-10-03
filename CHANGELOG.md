# Changelog — Partikulier 3

All notable changes to this monorepo (the `partikulier-core` plugin, the `partikulier` theme, tooling and documentation) are recorded here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the components follow [Semantic Versioning](https://semver.org/).

Releases ship as a **plugin-theme** pair tagged `v<plugin>-<theme>` (e.g. `v2.10.8-6.20.7`). Component-level details live in:

- [`plugin/partikulier-core/CHANGELOG.md`](plugin/partikulier-core/CHANGELOG.md)
- [`theme/partikulier/CHANGELOG.md`](theme/partikulier/CHANGELOG.md)

---

## [Unreleased]

### Added
- **User documentation** (`docs/user-stories/`): detailed FR/EN user stories by role with acceptance criteria, and a simplified FR/EN user guide.
- **Test plan** (`docs/user-stories/cahier-de-tests.html`, in French): 47 acceptance test cases (visitor/buyer, depositor, refused agent, owner, administrator, system actors). Each case gives the user type, an execution prompt, the expected result, an SQL check and a Playwright skeleton. A traceability matrix links each case to its user story.
- **Deployment environments** dev / uat / prd and a template for local secrets.
- Root `CHANGELOG.md` (this file); `docs/user-stories/README.md` updated.

### Changed
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

[Unreleased]: https://github.com/hajarbenmlih91-cloud/partikulier3/compare/v2.10.8-6.20.7-final...HEAD
[2.10.8-6.20.7-final]: https://github.com/hajarbenmlih91-cloud/partikulier3/compare/v2.10.8-6.20.7...v2.10.8-6.20.7-final
[2.10.8-6.20.7]: https://github.com/hajarbenmlih91-cloud/partikulier3/compare/v2.10.7-6.20.6...v2.10.8-6.20.7
[2.10.7-6.20.6]: https://github.com/hajarbenmlih91-cloud/partikulier3/compare/v2.10.6-6.20.5...v2.10.7-6.20.6
[2.10.6-6.20.5]: https://github.com/hajarbenmlih91-cloud/partikulier3/compare/v2.10.5-6.20.4...v2.10.6-6.20.5
[2.10.5-6.20.4]: https://github.com/hajarbenmlih91-cloud/partikulier3/releases/tag/v2.10.5-6.20.4
