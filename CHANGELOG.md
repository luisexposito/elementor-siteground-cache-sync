# Changelog

All notable changes to this project are documented here.

## [1.3.0] - 2026-10-11

### Changed
- First public release, refactored into a single final class (`ESGCS_Cache_Sync`).
- The primary domain is purged through the public `sg_cachepress_purge_everything()` (falling back to `sg_cachepress_purge_cache()`); `Supercacher::flush_dynamic_cache()` is used only for additional domains (and as a fallback for the primary one).
- Purge URLs use the site's scheme instead of forcing `https`.
- Domains are normalized, validated and deduplicated from every source, including filtered values.
- Filter renamed to `esgcs_domains`; `mr_sg_mcs_domains` kept as a backward-compatible alias.

### Added
- Per-request debounce, so the purge runs at most once per request.
- Guard clauses: does nothing if SiteGround Speed Optimizer is not active or its purge method is missing.
- SiteGround Speed Optimizer is also detected when network-activated on multisite.
- Optional debug logging through `WP_DEBUG` or the `esgcs_debug` filter.

## [1.1.0]

- Internal version: purges the SiteGround Dynamic Cache on all mapped domains and schedules a delayed retry.
