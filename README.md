# Elementor SiteGround Cache Sync

> [!WARNING]
> **Temporary workaround.** This MU-plugin is meant only for sites **hosted on SiteGround** that run **Elementor**. It works around a current issue that may be fixed in future versions of Elementor or SiteGround Speed Optimizer. Check the related issues below after each update and **remove the plugin once it is no longer needed.**

## Symptom

After a routine update of *any* plugin, theme, translation or WordPress core, some visitors see pages without styles. The browser's Network tab shows `404` responses for `wp-content/uploads/elementor/css/post-{ID}.css?ver=...`. On sites served from more than one domain, the secondary domains stay broken until someone purges the cache by hand.

## Root cause

1. **Elementor deletes all generated CSS on every update.** On `upgrader_process_complete` (any plugin, theme, core or translation update), Elementor calls its Files Manager `clear_cache()`. It deletes every file in `uploads/elementor/css` plus the `_elementor_css` post meta, and regenerates the files lazily on the next uncached render.
2. **The page cache keeps serving the old HTML.** HTML rendered before the update still links to `post-{ID}.css?ver=<old>`. Until the page cache is purged, that HTML is served with `<link>` tags pointing to files that no longer exist, so the stylesheets return 404.
3. **SiteGround only purges one host.** SiteGround Speed Optimizer purges its Dynamic Cache for the `home_url()` host only. Additional domains (TranslatePress multiple domains, domain mapping plugins) keep their stale HTML.

Elementor 4.3.2 fixed the race that rendered pages with a missing `<link>` tag ([#32226](https://github.com/elementor/elementor/issues/32226)), but it does **not** change the delete-then-regenerate behaviour. Updating an unrelated plugin still wipes the generated CSS.

Recent Elementor 4.3.x releases skip the wipe for translation updates, update checks and empty update queues when the `e_optimized_css_files` experiment is active. A genuine plugin, theme or core update still calls `clear_cache()`, so the problem described here remains.

## What the plugin does

When Elementor fires `elementor/core/files/clear_cache`, the plugin:

1. Collects every domain the site serves:
   - `home_url()` and `site_url()`;
   - TranslatePress multiple domains (`trp_settings['trp-multiple-domains']`, enabled entries only);
   - the `{prefix}dms_mappings` table, if it exists (Domain Mapping System);
   - anything added through the `esgcs_domains` filter.
2. Normalizes the domains (no scheme, path, port or leading `www.`, lowercase, validated hostname) and deduplicates them. SiteGround strips `www.` from the host when it builds its cache key, so the plugin does the same.
3. Purges the **primary domain** (the `home_url()` host) through SiteGround's public API: `sg_cachepress_purge_everything()`, which also clears the file cache and Memcached, or `sg_cachepress_purge_cache()` (no arguments) if the former is missing.
4. Purges every **additional domain** through `SiteGround_Optimizer\Supercacher\Supercacher::flush_dynamic_cache( $host, '/(.*)', $url )`, using the site's own scheme. If no public function is available (or it fails) but `flush_dynamic_cache()` exists, the primary domain is purged this way too, as a fallback.
5. Flushes the object cache (`wp_cache_flush()`).
6. Schedules **one** extra purge 60 seconds later through WP-Cron, which follows the same steps, to catch HTML that was cached while the CSS was being regenerated. It never schedules a duplicate, and the delayed purge does not schedule another one, so it cannot loop.

The purge runs at most once per request. SiteGround counts as available when a public purge function exists or the `Supercacher` class can be loaded from the active SiteGround plugin; otherwise the plugin does nothing.

## Why an internal SiteGround method?

SiteGround Speed Optimizer has a public function that takes a URL, `sg_cachepress_purge_cache( $url )`. However, `purge_cache_request()` in `core/Supercacher/Supercacher.php` always takes the hostname from `home_url()` and uses the given URL only to derive the path. Calling it with `https://example.org/page/` on a site whose `home_url()` is `https://example.com` purges `/page/` on `example.com`, never on `example.org`. `sg_cachepress_purge_everything()` has the same limitation: it only covers the `home_url()` host.

That leaves `Supercacher::flush_dynamic_cache()` as the only method that purges an explicit host, so the plugin uses it for the additional domains only. Wherever it can, which means the primary domain, it uses the public API.

A public purge-by-hostname API, or a `purge_cache_request()` that derives the host from the URL it receives, would make this plugin unnecessary for the purge part. This case has been reported to SiteGround, asking for a public purge-by-hostname API or for `purge_cache_request()` to derive the host from the URL it receives.

## Requirements

- WordPress 5.9+ and PHP 7.4+.
- Hosting on SiteGround with **SiteGround Speed Optimizer** (`sg-cachepress`) active and the Dynamic Cache enabled.
- Elementor.

## Installation

Copy the file into the must-use plugins directory:

```bash
mkdir -p wp-content/mu-plugins
cp elementor-siteground-cache-sync.php wp-content/mu-plugins/
```

MU-plugins load automatically and cannot be deactivated from the dashboard. To remove it, delete the file.

## Verify it works

1. Add `define( 'WP_DEBUG', true );` and `define( 'WP_DEBUG_LOG', true );` (or enable the `esgcs_debug` filter), then trigger a purge with `wp elementor flush-css`. `wp-content/debug.log` should contain `[esgcs] Purged ...` lines for each domain.
2. Check that the delayed purge was scheduled once, and that it disappears after it runs:

   ```bash
   wp cron event list | grep esgcs_delayed_purge
   ```
3. List the domains the plugin detected:

   ```bash
   wp eval 'echo implode( ", ", ESGCS_Cache_Sync::domains() ), PHP_EOL;'
   ```

## Configuration

| Filter | Default | Purpose |
| --- | --- | --- |
| `esgcs_domains` | Detected domains | Add or remove domains (or URLs) to purge. |
| `mr_sg_mcs_domains` | — | Legacy alias of `esgcs_domains`, kept for backward compatibility. |
| `esgcs_debug` | `WP_DEBUG` | Log each step to the PHP error log with the `[esgcs]` prefix. |

```php
add_filter( 'esgcs_domains', function ( array $domains ) {
	$domains[] = 'example.org';
	return $domains;
} );

add_filter( 'esgcs_debug', '__return_true' );
```

## Measured results

Tested on SiteGround with Elementor 4.3.x, CSS Print Method = External File and the SiteGround Dynamic Cache on, firing 30 concurrent anonymous requests per domain while a plugin update ran. These measurements were taken with an earlier build of the plugin (same purge logic, before the 1.3.0 clean-up); the 1.3.0 release itself has not been re-measured.

| Scenario | Responses with cached HTML linking to 404 CSS |
| --- | --- |
| Without the plugin, single domain | 11–15 of 30 |
| Without the plugin, secondary domain | Broken until a manual purge |
| With the plugin, single domain | 1 of 30 (transient: the first request, served from cache in the same millisecond as the update) |
| With the plugin, multi-domain | 0 of 60 |

## Limitations

- **Internal API for additional domains.** `Supercacher::flush_dynamic_cache()` is an internal SiteGround Speed Optimizer method, not a public API (see above). A future release may rename or change it. The plugin checks that it exists. If it doesn't, the primary domain is still purged through the public API, but additional domains are not.
- **WP-Cron.** The delayed purge depends on WP-Cron. If WP-Cron is disabled and no system cron calls `wp-cron.php`, only the immediate purge runs.
- **No CDN purge.** It does not purge the SiteGround CDN. If the CDN caches HTML, purge it separately.
- It reduces the window during which cached HTML points at missing CSS. It does not stop Elementor from deleting the files; the real fix has to come from Elementor.

## Related Elementor issues

- [#35983](https://github.com/elementor/elementor/issues/35983): same trigger, generated CSS cleared on updates.
- [#33057](https://github.com/elementor/elementor/issues/33057): "Elementor deletes CSS files daily".
- [#32226](https://github.com/elementor/elementor/issues/32226): race condition on `_elementor_css` causing a missing `<link>` (fixed in 4.3.2).

## License

[GPL-2.0-or-later](LICENSE). © Luis Felipe Expósito.
