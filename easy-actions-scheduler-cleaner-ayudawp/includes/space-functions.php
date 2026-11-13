<?php
/**
 * Space estimation functions for Easy Actions Scheduler Cleaner
 *
 * Provides database table size calculations for estimating
 * disk space savings from cleanup operations.
 *
 * @package EasyActionsSchedulerCleanerAyudaWP
 * @since 1.2.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get table sizes in MB for Action Scheduler tables
 *
 * Queries information_schema to get actual disk usage
 * including data and index sizes.
 *
 * @since 1.2.0
 * @return array Table sizes with keys: actions_size, logs_size, groups_size, total_size (all in MB).
 */
function easc_get_table_sizes() {
	global $wpdb;

	$cache_key = 'easc_table_sizes';
	$sizes     = wp_cache_get( $cache_key, 'easy_actions_scheduler_cleaner_ayudawp' );

	if ( false !== $sizes ) {
		return $sizes;
	}

	$tables = array(
		'actions' => $wpdb->prefix . 'actionscheduler_actions',
		'logs'    => $wpdb->prefix . 'actionscheduler_logs',
		'groups'  => $wpdb->prefix . 'actionscheduler_groups',
	);

	$sizes = array(
		'actions_size' => 0,
		'logs_size'    => 0,
		'groups_size'  => 0,
		'total_size'   => 0,
	);

	foreach ( $tables as $key => $table_name ) {
		// Direct query to information_schema is necessary.
		$size = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT ROUND( ( data_length + index_length ) / 1024 / 1024, 2 )
				FROM information_schema.tables
				WHERE table_schema = %s AND table_name = %s',
				$wpdb->dbname,
				$table_name
			)
		);

		$sizes[ $key . '_size' ] = $size ? (float) $size : 0;
	}

	$sizes['total_size'] = round( $sizes['actions_size'] + $sizes['logs_size'] + $sizes['groups_size'], 2 );

	wp_cache_set( $cache_key, $sizes, 'easy_actions_scheduler_cleaner_ayudawp', 300 );

	return $sizes;
}

/**
 * Format size in MB for display
 *
 * Returns a human-readable string with appropriate unit (KB or MB).
 *
 * @since 1.2.0
 * @param float $size_mb Size in megabytes.
 * @return string Formatted size string.
 */
function easc_format_size( $size_mb ) {
	if ( $size_mb < 0.01 ) {
		return '< 0.01 MB';
	}

	if ( $size_mb < 1 ) {
		return round( $size_mb * 1024, 1 ) . ' KB';
	}

	return number_format_i18n( $size_mb, 2 ) . ' MB';
}

/**
 * Estimate space per record (rough average)
 *
 * Calculates an average row size to estimate space savings
 * for specific record types.
 *
 * @since 1.2.0
 * @param string $table_type Either 'actions' or 'logs'.
 * @return float Average size per record in MB, or 0.
 */
function easc_get_avg_record_size( $table_type = 'actions' ) {
	$sizes = easc_get_table_sizes();

	if ( 'logs' === $table_type ) {
		$total_count = easc_get_logs_count();
		$table_size  = $sizes['logs_size'];
	} else {
		$counts      = easc_get_database_status();
		$total_count = 0;
		if ( $counts ) {
			foreach ( $counts as $row ) {
				$total_count += (int) $row->count;
			}
		}
		$table_size = $sizes['actions_size'];
	}

	if ( $total_count < 1 ) {
		return 0;
	}

	return $table_size / $total_count;
}

/**
 * Estimate space that would be freed by cleanup
 *
 * Calculates estimated MB that would be freed based on
 * current record counts and average record sizes.
 *
 * @since 1.2.0
 * @return array Estimated space per type and total.
 */
function easc_estimate_cleanup_savings() {
	$avg_action_size = easc_get_avg_record_size( 'actions' );
	$avg_log_size    = easc_get_avg_record_size( 'logs' );

	$counts = easc_get_database_status();
	$status_counts = array();
	if ( $counts ) {
		foreach ( $counts as $row ) {
			$status_counts[ $row->status ] = (int) $row->count;
		}
	}

	$cleanable_statuses = array( 'complete', 'failed', 'canceled', 'past-due' );
	$estimated          = array();
	$total              = 0;

	foreach ( $cleanable_statuses as $status ) {
		$count               = isset( $status_counts[ $status ] ) ? $status_counts[ $status ] : 0;
		$size                = round( $count * $avg_action_size, 2 );
		$estimated[ $status ] = $size;
		$total              += $size;
	}

	// Old pending (30+ days).
	$old_pending_count        = easc_get_old_pending_count( 30 );
	$estimated['pending_old'] = round( $old_pending_count * $avg_action_size, 2 );
	$total                   += $estimated['pending_old'];

	// Logs.
	$logs_count        = easc_get_logs_count();
	$estimated['logs'] = round( $logs_count * $avg_log_size, 2 );
	$total            += $estimated['logs'];

	$estimated['total'] = round( $total, 2 );

	return $estimated;
}

/**
 * Invalidate space cache
 *
 * Should be called after cleanup operations.
 *
 * @since 1.2.0
 */
function easc_invalidate_space_cache() {
	wp_cache_delete( 'easc_table_sizes', 'easy_actions_scheduler_cleaner_ayudawp' );
}
