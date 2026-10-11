<?php
/**
 * Plugin Name:       Elementor SiteGround Cache Sync
 * Plugin URI:        https://github.com/luisexposito/elementor-siteground-cache-sync
 * Description:       Temporary workaround: purges the SiteGround Dynamic Cache on every domain of the site whenever Elementor clears its generated CSS, so cached pages never reference deleted stylesheets.
 * Version:           1.3.0
 * Requires at least: 5.9
 * Requires PHP:      7.4
 * Author:            Luis Felipe Expósito
 * Author URI:        https://exposito.tech
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package ElementorSiteGroundCacheSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'ESGCS_Cache_Sync' ) ) {

	/**
	 * Keeps the SiteGround Dynamic Cache in sync with Elementor's generated CSS.
	 *
	 * Elementor deletes every file in uploads/elementor/css on any
	 * upgrader_process_complete and regenerates them lazily. Pages already in the
	 * full-page cache keep linking to the deleted files (404). SiteGround's own
	 * automatic purge only covers the home_url() host, so this class purges every
	 * known domain right away and once more after a short delay.
	 */
	final class ESGCS_Cache_Sync {

		/**
		 * Plugin version.
		 */
		const VERSION = '1.3.0';

		/**
		 * WP-Cron hook used for the delayed purge.
		 */
		const DELAYED_HOOK = 'esgcs_delayed_purge';

		/**
		 * Delay in seconds before the second purge.
		 */
		const DELAY = 60;

		/**
		 * Fully qualified SiteGround Supercacher class.
		 */
		const SUPERCACHER = '\SiteGround_Optimizer\Supercacher\Supercacher';

		/**
		 * Whether a purge already ran during this request (debounce).
		 *
		 * @var bool
		 */
		private static $purged = false;

		/**
		 * Registers hooks.
		 *
		 * @return void
		 */
		public static function init() {
			add_action( 'elementor/core/files/clear_cache', array( __CLASS__, 'on_clear_cache' ), PHP_INT_MAX );
			add_action( self::DELAYED_HOOK, array( __CLASS__, 'on_delayed_purge' ) );
		}

		/**
		 * Handles Elementor's CSS clear: purge now and schedule one delayed retry.
		 *
		 * @return void
		 */
		public static function on_clear_cache() {
			if ( self::$purged ) {
				self::log( 'Purge already done in this request, skipping.' );
				return;
			}
			self::$purged = true;

			if ( ! self::purge_all() ) {
				return;
			}

			if ( false === wp_next_scheduled( self::DELAYED_HOOK ) ) {
				wp_schedule_single_event( time() + self::DELAY, self::DELAYED_HOOK );
				self::log( sprintf( 'Delayed purge scheduled in %d seconds.', self::DELAY ) );
			}
		}

		/**
		 * Handles the delayed WP-Cron purge.
		 *
		 * @return void
		 */
		public static function on_delayed_purge() {
			self::purge_all();
		}

		/**
		 * Purges the primary host through the public API and every additional
		 * host through flush_dynamic_cache(), then flushes the object cache.
		 *
		 * Primary host, in order of preference:
		 * 1. sg_cachepress_purge_everything(), which also clears the file cache
		 *    and Memcached.
		 * 2. sg_cachepress_purge_cache() without arguments.
		 * 3. flush_dynamic_cache() as a last resort.
		 *
		 * purge_cache_request() always takes the hostname from home_url()
		 * (core/Supercacher/Supercacher.php), so sg_cachepress_purge_cache( $url )
		 * cannot purge additional domains; only flush_dynamic_cache() can.
		 *
		 * @return bool True if SiteGround was available and a purge was attempted.
		 */
		private static function purge_all() {
			if ( ! self::is_sg_available() ) {
				self::log( 'SiteGround Speed Optimizer not available, nothing purged.' );
				return false;
			}

			$flush     = array( self::SUPERCACHER, 'flush_dynamic_cache' );
			$has_flush = self::load_supercacher() && is_callable( $flush );
			$scheme    = self::scheme();
			$primary   = self::normalize_host( home_url( '/' ) );

			$primary_done = self::purge_primary_public();

			foreach ( self::domains() as $domain ) {
				if ( $primary_done && $domain === $primary ) {
					continue;
				}

				if ( ! $has_flush ) {
					self::log( 'flush_dynamic_cache() unavailable, skipped ' . $domain );
					continue;
				}

				try {
					call_user_func( $flush, $domain, '/(.*)', $scheme . '://' . $domain . '/' );
					self::log( 'Purged dynamic cache for ' . $domain );
				} catch ( \Throwable $e ) {
					self::log( 'Purge failed for ' . $domain . ': ' . $e->getMessage() );
				}
			}

			if ( function_exists( 'wp_cache_flush' ) ) {
				wp_cache_flush();
			}

			return true;
		}

		/**
		 * Purges the home_url() host through SiteGround's public functions.
		 *
		 * @return bool True if a public purge function ran without error.
		 */
		private static function purge_primary_public() {
			$candidates = array( 'sg_cachepress_purge_everything', 'sg_cachepress_purge_cache' );

			foreach ( $candidates as $function ) {
				if ( ! function_exists( $function ) ) {
					continue;
				}

				try {
					call_user_func( $function );
					self::log( 'Purged primary host via ' . $function . '().' );
					return true;
				} catch ( \Throwable $e ) {
					self::log( $function . '() failed: ' . $e->getMessage() );
					return false;
				}
			}

			return false;
		}

		/**
		 * Whether SiteGround Speed Optimizer can be used for purging.
		 *
		 * True if a public purge function exists or the Supercacher class can be
		 * loaded (only from an active SiteGround plugin, see load_supercacher()).
		 *
		 * @return bool
		 */
		private static function is_sg_available() {
			if ( function_exists( 'sg_cachepress_purge_everything' ) || function_exists( 'sg_cachepress_purge_cache' ) ) {
				return true;
			}

			return self::load_supercacher();
		}

		/**
		 * Returns the URL scheme of the site.
		 *
		 * @return string Either 'https' or 'http'.
		 */
		private static function scheme() {
			$scheme = wp_parse_url( home_url( '/' ), PHP_URL_SCHEME );

			if ( 'https' === $scheme || 'http' === $scheme ) {
				return $scheme;
			}

			return is_ssl() ? 'https' : 'http';
		}

		/**
		 * Collects, normalizes and deduplicates every domain served by this site.
		 *
		 * Sources: home_url/site_url, TranslatePress multiple domains, the
		 * {prefix}dms_mappings table (Domain Mapping System) and the
		 * esgcs_domains filter (legacy alias: mr_sg_mcs_domains).
		 *
		 * @return string[] Lowercase hostnames without scheme, port or path.
		 */
		public static function domains() {
			$candidates = array( home_url( '/' ), site_url( '/' ) );

			$trp_settings = get_option( 'trp_settings', array() );
			if ( is_array( $trp_settings ) && ! empty( $trp_settings['trp-multiple-domains'] ) && is_array( $trp_settings['trp-multiple-domains'] ) ) {
				foreach ( $trp_settings['trp-multiple-domains'] as $mapping ) {
					if ( is_array( $mapping ) && ! empty( $mapping['enabled'] ) && ! empty( $mapping['domain'] ) ) {
						$candidates[] = (string) $mapping['domain'];
					}
				}
			}

			$candidates = array_merge( $candidates, self::dms_hosts() );

			/**
			 * Filters the list of domains to purge.
			 *
			 * @param string[] $candidates Domains or URLs.
			 */
			$candidates = (array) apply_filters( 'esgcs_domains', $candidates );

			/** This filter is documented above. Kept for backward compatibility. */
			$candidates = (array) apply_filters( 'mr_sg_mcs_domains', $candidates );

			$domains = array();
			foreach ( $candidates as $candidate ) {
				$host = self::normalize_host( $candidate );
				if ( '' !== $host ) {
					$domains[ $host ] = true;
				}
			}

			$domains = array_keys( $domains );
			sort( $domains );

			return $domains;
		}

		/**
		 * Reads mapped hosts from the Domain Mapping System table, if it exists.
		 *
		 * @return string[]
		 */
		private static function dms_hosts() {
			global $wpdb;

			$table = $wpdb->prefix . 'dms_mappings';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
				return array();
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_col( "SELECT host FROM {$table} WHERE host IS NOT NULL AND host != ''" );

			return is_array( $rows ) ? $rows : array();
		}

		/**
		 * Normalizes a domain or URL into a bare, validated hostname.
		 *
		 * Strips scheme, path, port and a leading "www." (SiteGround does the same
		 * when building its cache key: Supercacher::purge_cache_request() uses
		 * str_replace( 'www.', '', host )), lowercases it and validates it.
		 *
		 * @param mixed $value Domain or URL.
		 * @return string Hostname, or empty string if invalid.
		 */
		public static function normalize_host( $value ) {
			if ( ! is_string( $value ) ) {
				return '';
			}

			$value = strtolower( trim( $value ) );
			if ( '' === $value ) {
				return '';
			}

			if ( false === strpos( $value, '://' ) ) {
				$value = 'http://' . ltrim( $value, '/' );
			}

			$host = wp_parse_url( $value, PHP_URL_HOST );
			if ( ! is_string( $host ) ) {
				return '';
			}

			$host = rtrim( preg_replace( '/^www\./', '', $host ), '.' );

			if ( false === filter_var( $host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME ) || ( false === strpos( $host, '.' ) && 'localhost' !== $host ) ) {
				return '';
			}

			return $host;
		}

		/**
		 * Makes sure the SiteGround Supercacher class is loaded.
		 *
		 * MU-plugins load before regular plugins, so the class may not exist yet
		 * when this runs early. The SiteGround file is only included if the
		 * plugin is active: including an inactive plugin would boot code the
		 * site owner turned off and could purge a cache that is not in use.
		 *
		 * @return bool
		 */
		private static function load_supercacher() {
			if ( class_exists( self::SUPERCACHER ) ) {
				return true;
			}

			$file = WP_PLUGIN_DIR . '/sg-cachepress/sg-cachepress.php';
			if ( is_readable( $file ) && self::is_sg_active() ) {
				include_once $file;
			}

			return class_exists( self::SUPERCACHER );
		}

		/**
		 * Whether SiteGround Speed Optimizer is an active plugin.
		 *
		 * @return bool
		 */
		private static function is_sg_active() {
			$plugin = 'sg-cachepress/sg-cachepress.php';
			$active = (array) get_option( 'active_plugins', array() );

			if ( in_array( $plugin, $active, true ) ) {
				return true;
			}

			if ( is_multisite() ) {
				$network = (array) get_site_option( 'active_sitewide_plugins', array() );
				return isset( $network[ $plugin ] );
			}

			return false;
		}

		/**
		 * Writes a debug line when WP_DEBUG or the esgcs_debug filter is enabled.
		 *
		 * @param string $message Message.
		 * @return void
		 */
		private static function log( $message ) {
			$enabled = (bool) apply_filters( 'esgcs_debug', defined( 'WP_DEBUG' ) && WP_DEBUG );
			if ( $enabled ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( '[esgcs] ' . $message );
			}
		}
	}

	ESGCS_Cache_Sync::init();
}
