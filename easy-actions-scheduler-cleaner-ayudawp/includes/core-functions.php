<?php
/**
 * Core functions for Easy Actions Scheduler Cleaner
 *
 * @package EasyActionsSchedulerCleanerAyudaWP
 * @since 1.0.1
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin activation handler
 *
 * @since 1.0.1
 */
function easc_on_activation() {
	// Check if Actions Scheduler table exists.
	if ( ! easc_table_exists() ) {
		deactivate_plugins( EASC_PLUGIN_BASENAME );
		wp_die(
			esc_html__( 'Actions Scheduler table not found.', 'easy-actions-scheduler-cleaner-ayudawp' ),
			esc_html__( 'Plugin Activation Error', 'easy-actions-scheduler-cleaner-ayudawp' ),
			array( 'back_link' => true )
		);
	}

	// Schedule redirect to plugin page.
	add_option( 'easy_actions_scheduler_cleaner_ayudawp_redirect', true );
}

/**
 * Plugin deactivation handler
 *
 * Clears scheduled cron event and any in-flight background steps on deactivation.
 *
 * @since 1.1.0
 */
function easc_on_deactivation() {
	$timestamp = wp_next_scheduled( 'easc_scheduled_cleanup' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'easc_scheduled_cleanup' );
	}

	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'easc_scheduled_cleanup_step', null, 'easc' );
	}
	delete_transient( 'easc_cleanup_state' );
}

/**
 * Check if Actions Scheduler table exists
 *
 * @since 1.0.0
 * @return bool
 */
function easc_table_exists() {
	global $wpdb;

	$cache_key    = 'easc_table_exists_' . md5( $wpdb->prefix );
	$table_exists = wp_cache_get( $cache_key, 'easy_actions_scheduler_cleaner_ayudawp' );

	if ( false === $table_exists ) {
		$table_name = $wpdb->prefix . 'actionscheduler_actions';
		// Direct database call necessary to check table existence.
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		wp_cache_set( $cache_key, $table_exists, 'easy_actions_scheduler_cleaner_ayudawp', 300 );
	}

	return $table_exists;
}

/**
 * Check if Actions Scheduler logs table exists
 *
 * @since 1.0.0
 * @return bool
 */
function easc_logs_table_exists() {
	global $wpdb;

	$cache_key    = 'easc_logs_table_exists_' . md5( $wpdb->prefix );
	$table_exists = wp_cache_get( $cache_key, 'easy_actions_scheduler_cleaner_ayudawp' );

	if ( false === $table_exists ) {
		$table_name = $wpdb->prefix . 'actionscheduler_logs';
		// Direct database call necessary to check table existence.
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		wp_cache_set( $cache_key, $table_exists, 'easy_actions_scheduler_cleaner_ayudawp', 300 );
	}

	return $table_exists;
}

/**
 * Get current database status
 *
 * @since 1.0.0
 * @return array|false Database status counts or false on error
 */
function easc_get_database_status() {
	global $wpdb;

	$counts = wp_cache_get( 'easc_status_counts', 'easy_actions_scheduler_cleaner_ayudawp' );
	if ( false === $counts ) {
		// Direct database call necessary for complex GROUP BY operations.
		$counts = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT status, COUNT(*) as count FROM {$wpdb->prefix}actionscheduler_actions GROUP BY status"
		);
		wp_cache_set( 'easc_status_counts', $counts, 'easy_actions_scheduler_cleaner_ayudawp', 300 );
	}

	return $counts;
}

/**
 * Get count by specific status
 *
 * @since 1.1.0
 * @param string $status Action status.
 * @return int
 */
function easc_get_count_by_status( $status ) {
	$counts = easc_get_database_status();
	if ( ! $counts ) {
		return 0;
	}

	foreach ( $counts as $row ) {
		if ( $row->status === $status ) {
			return (int) $row->count;
		}
	}

	return 0;
}

/**
 * Get current logs count
 *
 * @since 1.0.0
 * @return int Logs count or 0 on error
 */
function easc_get_logs_count() {
	global $wpdb;

	if ( ! easc_logs_table_exists() ) {
		return 0;
	}

	$count = wp_cache_get( 'easc_logs_count', 'easy_actions_scheduler_cleaner_ayudawp' );
	if ( false === $count ) {
		// Direct database call necessary for COUNT operations.
		$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_logs" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $count ? (int) $count : 0;
		wp_cache_set( 'easc_logs_count', $count, 'easy_actions_scheduler_cleaner_ayudawp', 300 );
	}

	return $count;
}

/**
 * Is this a "large" site that probably needs scheduled cleanup?
 *
 * Default threshold of 500 000 records (actions + logs) is the point where
 * synchronous manual cleanup starts to be uncomfortable on shared hosting.
 * Filterable via `easc_large_site_threshold`. Relies on
 * `easc_get_total_record_count()` from notification-functions.php (loaded
 * after this file but resolved at call time, not parse time).
 *
 * @since 1.3.0
 * @return bool
 */
function easc_is_large_site() {
	$threshold = (int) apply_filters( 'easc_large_site_threshold', 500000 );
	return easc_get_total_record_count() >= $threshold;
}

/**
 * Get old pending actions count
 *
 * @since 1.1.0
 * @param int $days Number of days to consider old. Default 30.
 * @return int
 */
function easc_get_old_pending_count( $days = 30 ) {
	global $wpdb;

	$cutoff_date = gmdate( 'Y-m-d H:i:s', strtotime( '-' . absint( $days ) . ' days' ) );

	// Direct database call necessary for filtered COUNT.
	$count = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions WHERE status = 'pending' AND scheduled_date_gmt < %s",
			$cutoff_date
		)
	);

	return $count ? (int) $count : 0;
}

/**
 * Deactivate plugin and redirect
 *
 * @since 1.0.0
 */
function easc_deactivate_plugin() {
	deactivate_plugins( EASC_PLUGIN_BASENAME );
	wp_safe_redirect( admin_url( 'plugins.php?deactivate=true&plugin_status=all&paged=1' ) );
	exit;
}

/**
 * Get default schedule settings
 *
 * @since 1.1.0
 * @return array
 */
function easc_get_default_schedule_settings() {
	return array(
		'enabled'   => false,
		'frequency' => 'weekly',
		'types'     => array(
			'complete'    => array(
				'enabled'   => true,
				'threshold' => 100,
			),
			'failed'      => array(
				'enabled'   => true,
				'threshold' => 50,
			),
			'canceled'    => array(
				'enabled'   => true,
				'threshold' => 50,
			),
			'past-due'    => array(
				'enabled'   => true,
				'threshold' => 50,
			),
			'pending_old' => array(
				'enabled'   => true,
				'threshold' => 100,
				'days'      => 30,
			),
			'logs'        => array(
				'enabled'   => true,
				'threshold' => 500,
			),
		),
	);
}

/**
 * Get schedule settings
 *
 * @since 1.1.0
 * @return array
 */
function easc_get_schedule_settings() {
	$defaults = easc_get_default_schedule_settings();
	$settings = get_option( 'easc_schedule_settings', array() );

	return wp_parse_args( $settings, $defaults );
}
