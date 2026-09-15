<?php
/**
 * Everything left over from the old spelling of the brand.
 *
 * The product is Haman, one "m". The plugin shipped as "Hamman" through
 * 1.9.0, which put the old spelling into three places that outlive an
 * upgrade: the option keys holding every setting, the REST namespace the
 * platform calls, and the order meta marking orders this plugin created.
 *
 * Renaming the plugin folder makes WordPress treat 2.0.0 as a different
 * plugin, so the old one deactivates and its settings are simply left
 * behind. Nothing is lost only because activate() moves them across.
 *
 * TODO(2027-03-01): delete this file and every call to it. By then every
 * install has been through at least one 2.x activation, so the options have
 * moved and the platform has stopped calling the old routes. Removing it
 * earlier breaks any site still on 1.9.0, because the platform and the
 * plugin upgrade independently and in either order.
 *
 * This is deliberately the only file in the plugin that contains the old
 * spelling. A search for it anywhere else is a mistake.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Haman_Legacy {

	/** The 1.x REST namespace. Still registered, alongside the new one. */
	const REST_NAMESPACE = 'hamman/v1';

	/** The 1.x HMAC header. Accepted as a fallback on inbound requests. */
	const SIGNATURE_HEADER = 'X-Hamman-Signature';

	/**
	 * Every option 1.x stored, by its old key. The new key is the same
	 * with one fewer "m", so the mapping is derived rather than repeated.
	 *
	 * Listed explicitly instead of scanning the options table: a wildcard
	 * would also drag across keys from some other plugin that happened to
	 * share the prefix.
	 */
	const OPTIONS = [
		'hamman_ai_name',
		'hamman_api_key',
		'hamman_api_url',
		'hamman_avatar_url',
		'hamman_chat_title',
		'hamman_chatbot_id',
		'hamman_delete_data_on_uninstall',
		'hamman_enabled',
		'hamman_field_mapping',
		'hamman_input_placeholder',
		'hamman_last_full_sync',
		'hamman_payment_link_hold_hours',
		'hamman_primary_color',
		'hamman_quick_questions',
		'hamman_rate_limit_block_minutes',
		'hamman_rate_limit_max_messages',
		'hamman_sync_pages',
		'hamman_sync_pdfs',
		'hamman_sync_products',
		'hamman_system_instruction',
		'hamman_webhook_secret',
		'hamman_welcome_text',
		'hamman_widget_position',
	];

	/** The 1.x cron hooks. Unscheduled on upgrade so they do not linger. */
	const CRON_HOOKS = [
		'hamman_hourly_sync',
		'hamman_cancel_stale_orders',
	];

	/** The 1.x post and order meta keys, still read when the new one is absent. */
	const META_KEYS = [
		'_hamman_created',
		'_hamman_chatbot_id',
		'_hamman_reported',
		'_hamman_tracking',
	];

	/** The new name for an old one: "hamman_api_key" -> "haman_api_key". */
	public static function new_key( string $old ): string {
		return str_replace( 'hamman', 'haman', $old );
	}

	/** The old name for a new one, so no other file has to spell it. */
	public static function old_key( string $new ): string {
		return str_replace( 'haman', 'hamman', $new );
	}

	/**
	 * Move every 1.x setting onto its new key.
	 *
	 * Runs on activation, before add_option() seeds defaults, so a migrated
	 * value is never overwritten by one. The old key is deleted only after
	 * the new one is written, so an interrupted upgrade leaves the value
	 * readable under one name or the other, never neither.
	 *
	 * An existing new key always wins: re-activating 2.0.0 after the
	 * customer has edited a setting must not put the 1.x value back.
	 *
	 * @return int how many settings were carried over
	 */
	public static function migrate_options(): int {
		$moved = 0;

		foreach ( self::OPTIONS as $old ) {
			$value = get_option( $old, null );
			if ( null === $value ) {
				continue;
			}

			$new = self::new_key( $old );
			if ( null === get_option( $new, null ) ) {
				update_option( $new, $value );
				$moved++;
			}

			delete_option( $old );
		}

		return $moved;
	}

	/**
	 * Move the order and post meta this plugin wrote.
	 *
	 * Capped per activation, because a shop with years of orders must not
	 * time out the activation request. Anything past the cap is left under
	 * the old key and stays readable, because meta() falls back to it -- so
	 * this is a tidy-up, not something correctness depends on.
	 */
	public static function migrate_meta( int $batch = 200 ): int {
		global $wpdb;
		$moved = 0;

		foreach ( self::META_KEYS as $old ) {
			$new = self::new_key( $old );
			$moved += (int) $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->postmeta} SET meta_key = %s WHERE meta_key = %s LIMIT %d",
					$new,
					$old,
					$batch
				)
			);
		}

		return $moved;
	}

	/** Drop the 1.x scheduled events, which nothing answers any more. */
	public static function clear_cron(): void {
		foreach ( self::CRON_HOOKS as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	/**
	 * Read post meta by its new key, falling back to the old one.
	 *
	 * For the rows migrate_meta() has not reached yet, and for anything a
	 * 1.9.0 install wrote between the platform upgrading and the plugin
	 * being updated.
	 */
	public static function meta( int $post_id, string $new_key, bool $single = true ) {
		$value = get_post_meta( $post_id, $new_key, $single );
		if ( '' !== $value && [] !== $value && null !== $value ) {
			return $value;
		}

		return get_post_meta( $post_id, self::old_key( $new_key ), $single );
	}

	/** Options to remove on uninstall, so 1.x leaves nothing behind either. */
	public static function uninstall_options(): array {
		return self::OPTIONS;
	}
}
