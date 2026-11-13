<?php
/**
 * Database functions for Easy Actions Scheduler Cleaner
 *
 * @package EasyActionsSchedulerCleanerAyudaWP
 * @since 1.0.1
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Execute database cleanup (manual mode)
 *
 * @since 1.0.1
 */
function easc_execute_cleanup() {

	// Verify table exists.
	if ( ! easc_table_exists() ) {
		wp_die( esc_html__( 'Actions Scheduler table not found.', 'easy-actions-scheduler-cleaner-ayudawp' ) );
	}

	// Capture sizes before cleanup.
	$sizes_before = easc_get_table_sizes();

	$batch_size = 10000;

	// 1. Delete completed actions.
	$deleted_complete = easc_delete_actions_by_status( 'complete', $batch_size );

	// 2. Delete failed actions.
	$deleted_failed = easc_delete_actions_by_status( 'failed', $batch_size );

	// 3. Delete canceled actions.
	$deleted_canceled = easc_delete_actions_by_status( 'canceled', $batch_size );

	// 4. Delete past-due actions.
	$deleted_past_due = easc_delete_actions_by_status( 'past-due', $batch_size );

	// 5. Delete old pending actions (30+ days).
	$deleted_old_pending = easc_delete_old_pending_actions( $batch_size );

	// 6. Delete all action logs.
	$deleted_logs = easc_delete_all_logs( $batch_size );

	// Clean orphaned related tables.
	easc_clean_orphaned_data();

	// Calculate space freed.
	easc_invalidate_space_cache();
	$sizes_after = easc_get_table_sizes();
	$space_freed = round( $sizes_before['total_size'] - $sizes_after['total_size'], 2 );
	if ( $space_freed < 0 ) {
		$space_freed = 0;
	}

	// Redirect to results page.
	wp_safe_redirect(
		add_query_arg(
			array(
				'page'        => 'easy-actions-scheduler-cleaner-ayudawp',
				'results'     => '1',
				'complete'    => $deleted_complete,
				'failed'      => $deleted_failed,
				'canceled'    => $deleted_canceled,
				'past_due'    => $deleted_past_due,
				'old_pending' => $deleted_old_pending,
				'logs'        => $deleted_logs,
				'space_freed' => $space_freed,
				'_wpnonce'    => wp_create_nonce( 'easc_results_nonce' ),
			),
			admin_url( 'tools.php' )
		)
	);
	exit;
}

/**
 * Delete a single batch of actions by status
 *
 * Atomic operation: one DELETE LIMIT, returns affected rows.
 * Used by background processing to control batch pacing.
 *
 * @since 1.3.0
 * @param string $status     Action status.
 * @param int    $batch_size Batch size for deletion.
 * @return int Number of rows deleted in this batch.
 */
function easc_delete_actions_by_status_batch( $status, $batch_size ) {
	global $wpdb;

	// Direct database call necessary for bulk deletion operations.
	$rows_deleted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}actionscheduler_actions WHERE status = %s LIMIT %d",
			$status,
			$batch_size
		)
	);

	wp_cache_delete( 'easc_status_counts', 'easy_actions_scheduler_cleaner_ayudawp' );

	return (int) $rows_deleted;
}

/**
 * Delete actions by status (synchronous, loops until exhausted)
 *
 * @since 1.0.0
 * @param string $status     Action status.
 * @param int    $batch_size Batch size for deletion.
 * @return int Number of deleted actions.
 */
function easc_delete_actions_by_status( $status, $batch_size ) {
	$deleted = 0;

	do {
		$rows_deleted = easc_delete_actions_by_status_batch( $status, $batch_size );
		$deleted    += $rows_deleted;
	} while ( $rows_deleted > 0 );

	return $deleted;
}

/**
 * Delete a single batch of old pending actions
 *
 * @since 1.3.0
 * @param int $batch_size Batch size for deletion.
 * @param int $days       Number of days to consider old. Default 30.
 * @return int Number of rows deleted in this batch.
 */
function easc_delete_old_pending_actions_batch( $batch_size, $days = 30 ) {
	global $wpdb;

	$cutoff_date = gmdate( 'Y-m-d H:i:s', strtotime( '-' . absint( $days ) . ' days' ) );

	// Direct database call necessary for bulk deletion operations.
	$rows_deleted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}actionscheduler_actions WHERE status = 'pending' AND scheduled_date_gmt < %s LIMIT %d",
			$cutoff_date,
			$batch_size
		)
	);

	wp_cache_delete( 'easc_status_counts', 'easy_actions_scheduler_cleaner_ayudawp' );

	return (int) $rows_deleted;
}

/**
 * Delete old pending actions (synchronous, loops until exhausted)
 *
 * @since 1.0.0
 * @param int $batch_size Batch size for deletion.
 * @param int $days       Number of days to consider old. Default 30.
 * @return int Number of deleted actions.
 */
function easc_delete_old_pending_actions( $batch_size, $days = 30 ) {
	$deleted = 0;

	do {
		$rows_deleted = easc_delete_old_pending_actions_batch( $batch_size, $days );
		$deleted    += $rows_deleted;
	} while ( $rows_deleted > 0 );

	return $deleted;
}

/**
 * Delete a single batch of action logs
 *
 * @since 1.3.0
 * @param int $batch_size Batch size for deletion.
 * @return int Number of rows deleted in this batch.
 */
function easc_delete_all_logs_batch( $batch_size ) {
	global $wpdb;

	if ( ! easc_logs_table_exists() ) {
		return 0;
	}

	// Direct database call necessary for bulk deletion operations.
	$rows_deleted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}actionscheduler_logs LIMIT %d",
			$batch_size
		)
	);

	wp_cache_delete( 'easc_logs_count', 'easy_actions_scheduler_cleaner_ayudawp' );

	return (int) $rows_deleted;
}

/**
 * Delete all action logs (synchronous, loops until exhausted)
 *
 * @since 1.0.0
 * @param int $batch_size Batch size for deletion.
 * @return int Number of deleted logs.
 */
function easc_delete_all_logs( $batch_size ) {
	if ( ! easc_logs_table_exists() ) {
		return 0;
	}

	$deleted = 0;

	do {
		$rows_deleted = easc_delete_all_logs_batch( $batch_size );
		$deleted    += $rows_deleted;
	} while ( $rows_deleted > 0 );

	return $deleted;
}

/**
 * Clean orphaned data from related tables
 *
 * @since 1.0.0
 */
function easc_clean_orphaned_data() {
	global $wpdb;

	// Clean orphaned groups.
	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		"DELETE g FROM {$wpdb->prefix}actionscheduler_groups g LEFT JOIN {$wpdb->prefix}actionscheduler_actions a ON g.group_id = a.group_id WHERE a.group_id IS NULL"
	);

	// Clear caches after cleanup.
	wp_cache_delete( 'easc_status_counts', 'easy_actions_scheduler_cleaner_ayudawp' );
	wp_cache_delete( 'easc_logs_count', 'easy_actions_scheduler_cleaner_ayudawp' );
	wp_cache_delete( 'easc_table_exists_' . md5( $GLOBALS['wpdb']->prefix ), 'easy_actions_scheduler_cleaner_ayudawp' );
	easc_invalidate_space_cache();
}
