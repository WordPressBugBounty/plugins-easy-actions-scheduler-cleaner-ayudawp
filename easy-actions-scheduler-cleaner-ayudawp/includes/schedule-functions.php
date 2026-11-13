<?php
/**
 * Schedule functions for Easy Actions Scheduler Cleaner
 *
 * Handles WP-Cron based scheduled cleanup with configurable
 * frequency and per-type thresholds.
 *
 * @package EasyActionsSchedulerCleanerAyudaWP
 * @since 1.1.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Initialize schedule system
 *
 * Registers the cron hook, the background-step hook and custom intervals.
 *
 * @since 1.1.0
 */
function easc_init_schedule() {
	// WP-Cron entry point: kicks off the background cleanup.
	add_action( 'easc_scheduled_cleanup', 'easc_run_scheduled_cleanup' );

	// Action Scheduler hook for each background step (one or more batches per call).
	add_action( 'easc_scheduled_cleanup_step', 'easc_run_scheduled_cleanup_step', 10, 1 );

	// Add custom cron intervals.
	add_filter( 'cron_schedules', 'easc_add_cron_intervals' );
}

/**
 * Add custom cron intervals (biweekly and monthly)
 *
 * @since 1.1.0
 * @param array $schedules Existing cron schedules.
 * @return array Modified cron schedules.
 */
function easc_add_cron_intervals( $schedules ) {
	if ( ! isset( $schedules['easc_biweekly'] ) ) {
		$schedules['easc_biweekly'] = array(
			'interval' => 14 * DAY_IN_SECONDS,
			'display'  => esc_html__( 'Every two weeks', 'easy-actions-scheduler-cleaner-ayudawp' ),
		);
	}

	if ( ! isset( $schedules['easc_monthly'] ) ) {
		$schedules['easc_monthly'] = array(
			'interval' => 30 * DAY_IN_SECONDS,
			'display'  => esc_html__( 'Monthly (every 30 days)', 'easy-actions-scheduler-cleaner-ayudawp' ),
		);
	}

	return $schedules;
}

/**
 * Map frequency setting to WP-Cron recurrence name
 *
 * @since 1.1.0
 * @param string $frequency The user-selected frequency.
 * @return string WP-Cron recurrence identifier.
 */
function easc_get_cron_recurrence( $frequency ) {
	$map = array(
		'daily'    => 'daily',
		'weekly'   => 'weekly',
		'biweekly' => 'easc_biweekly',
		'monthly'  => 'easc_monthly',
	);

	return isset( $map[ $frequency ] ) ? $map[ $frequency ] : 'weekly';
}

/**
 * Get interval in seconds for a given frequency
 *
 * @since 1.1.0
 * @param string $frequency Frequency identifier.
 * @return int Seconds.
 */
function easc_get_interval_seconds( $frequency ) {
	$intervals = array(
		'daily'    => DAY_IN_SECONDS,
		'weekly'   => WEEK_IN_SECONDS,
		'biweekly' => 14 * DAY_IN_SECONDS,
		'monthly'  => 30 * DAY_IN_SECONDS,
	);

	return isset( $intervals[ $frequency ] ) ? $intervals[ $frequency ] : WEEK_IN_SECONDS;
}

/**
 * Get human-readable frequency labels
 *
 * @since 1.1.0
 * @return array
 */
function easc_get_frequency_options() {
	return array(
		'daily'    => __( 'Daily', 'easy-actions-scheduler-cleaner-ayudawp' ),
		'weekly'   => __( 'Weekly', 'easy-actions-scheduler-cleaner-ayudawp' ),
		'biweekly' => __( 'Every two weeks', 'easy-actions-scheduler-cleaner-ayudawp' ),
		'monthly'  => __( 'Monthly', 'easy-actions-scheduler-cleaner-ayudawp' ),
	);
}

/**
 * Schedule or reschedule the cron event
 *
 * First execution is scheduled after the first full interval,
 * NOT immediately.
 *
 * @since 1.1.0
 * @param string $frequency Frequency identifier.
 */
function easc_schedule_cron_event( $frequency ) {
	// Clear existing event first.
	$timestamp = wp_next_scheduled( 'easc_scheduled_cleanup' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'easc_scheduled_cleanup' );
	}

	// Schedule first run in the future (after one full interval).
	$recurrence     = easc_get_cron_recurrence( $frequency );
	$first_run_time = time() + easc_get_interval_seconds( $frequency );

	wp_schedule_event( $first_run_time, $recurrence, 'easc_scheduled_cleanup' );
}

/**
 * Unschedule the cron event and cancel any in-flight background steps
 *
 * @since 1.1.0
 */
function easc_unschedule_cron_event() {
	$timestamp = wp_next_scheduled( 'easc_scheduled_cleanup' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'easc_scheduled_cleanup' );
	}

	// Cancel any pending background step so a freshly-disabled schedule
	// does not keep running batches in the background.
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'easc_scheduled_cleanup_step', null, 'easc' );
	}
	delete_transient( 'easc_cleanup_state' );
}

/**
 * Order in which cleanup phases run.
 *
 * @since 1.3.0
 * @return array
 */
function easc_get_cleanup_phases() {
	return array( 'complete', 'failed', 'canceled', 'past-due', 'pending_old', 'logs' );
}

/**
 * Resolve the next phase, or 'finalize' when the last one finishes.
 *
 * @since 1.3.0
 * @param string $current_phase Current phase identifier.
 * @return string Next phase identifier or 'finalize'.
 */
function easc_next_cleanup_phase( $current_phase ) {
	$phases = easc_get_cleanup_phases();
	$idx    = array_search( $current_phase, $phases, true );

	if ( false === $idx || $idx >= count( $phases ) - 1 ) {
		return 'finalize';
	}

	return $phases[ $idx + 1 ];
}

/**
 * Run a single batch for the given cleanup phase.
 *
 * @since 1.3.0
 * @param string $phase      Phase identifier.
 * @param int    $batch_size Rows per batch.
 * @param array  $types      Type settings (used for pending_old days).
 * @return int Rows deleted in this batch.
 */
function easc_run_cleanup_phase_batch( $phase, $batch_size, $types ) {
	switch ( $phase ) {
		case 'complete':
		case 'failed':
		case 'canceled':
		case 'past-due':
			return easc_delete_actions_by_status_batch( $phase, $batch_size );
		case 'pending_old':
			$days = isset( $types['pending_old']['days'] ) ? (int) $types['pending_old']['days'] : 30;
			return easc_delete_old_pending_actions_batch( $batch_size, $days );
		case 'logs':
			return easc_delete_all_logs_batch( $batch_size );
	}
	return 0;
}

/**
 * Get the current pending count for a phase, used for threshold checks.
 *
 * @since 1.3.0
 * @param string $phase Phase identifier.
 * @param array  $types Type settings.
 * @return int
 */
function easc_get_cleanup_phase_count( $phase, $types ) {
	switch ( $phase ) {
		case 'complete':
		case 'failed':
		case 'canceled':
		case 'past-due':
			return easc_get_count_by_status( $phase );
		case 'pending_old':
			$days = isset( $types['pending_old']['days'] ) ? (int) $types['pending_old']['days'] : 30;
			return easc_get_old_pending_count( $days );
		case 'logs':
			return easc_get_logs_count();
	}
	return 0;
}

/**
 * Kick off the scheduled cleanup.
 *
 * On WP-Cron tick, captures initial state and queues the first background
 * step with Action Scheduler. Each step runs one or more batches and
 * reschedules itself until all phases finish. Falls back to the legacy
 * synchronous run if Action Scheduler is unavailable.
 *
 * @since 1.1.0
 */
function easc_run_scheduled_cleanup() {
	if ( ! easc_table_exists() ) {
		easc_save_last_run_log(
			'error',
			array(),
			__( 'Actions Scheduler table not found. The scheduled cleanup could not run.', 'easy-actions-scheduler-cleaner-ayudawp' )
		);
		return;
	}

	// Bail if a previous run is still in progress (state exists and not stale).
	$existing = get_transient( 'easc_cleanup_state' );
	if ( is_array( $existing ) && ! empty( $existing['started_at'] ) && ( time() - (int) $existing['started_at'] ) < DAY_IN_SECONDS ) {
		return;
	}

	// Invalidate caches before counting.
	wp_cache_delete( 'easc_status_counts', 'easy_actions_scheduler_cleaner_ayudawp' );
	wp_cache_delete( 'easc_logs_count', 'easy_actions_scheduler_cleaner_ayudawp' );

	$state = array(
		'started_at'      => time(),
		'sizes_before'    => easc_get_table_sizes(),
		'settings'        => easc_get_schedule_settings(),
		'results'         => array(),
		'skipped'         => array(),
		'phases_started'  => array(),
	);

	set_transient( 'easc_cleanup_state', $state, DAY_IN_SECONDS );

	// Fallback: AS not available — run legacy synchronous path so functionality
	// is preserved on installs where AS hooks did not load for some reason.
	if ( ! function_exists( 'as_schedule_single_action' ) ) {
		easc_run_scheduled_cleanup_sync( $state );
		return;
	}

	as_schedule_single_action( time(), 'easc_scheduled_cleanup_step', array( 'complete' ), 'easc' );
}

/**
 * Run a single background step of the scheduled cleanup.
 *
 * One step processes batches of the given phase until it either runs out of
 * rows or hits the per-step time budget. Then it advances to the next phase
 * (or finalizes). This keeps each PHP request well under the server's
 * max_execution_time even on million-row tables.
 *
 * @since 1.3.0
 * @param string $phase Phase identifier or 'finalize'.
 */
function easc_run_scheduled_cleanup_step( $phase ) {
	$state = get_transient( 'easc_cleanup_state' );
	if ( ! is_array( $state ) ) {
		return;
	}

	if ( 'finalize' === $phase ) {
		easc_finalize_scheduled_cleanup( $state );
		delete_transient( 'easc_cleanup_state' );
		return;
	}

	$types       = $state['settings']['types'];
	$batch_size  = (int) apply_filters( 'easc_batch_size', 10000, 'scheduled' );
	$time_budget = (int) apply_filters( 'easc_cleanup_step_time_budget', 25 );
	$start       = microtime( true );

	// On the first step of a phase, check enabled flag and threshold once.
	if ( empty( $state['phases_started'][ $phase ] ) ) {
		$state['phases_started'][ $phase ] = true;

		if ( empty( $types[ $phase ]['enabled'] ) ) {
			easc_schedule_next_cleanup_step( $state, $phase );
			return;
		}

		$threshold = (int) $types[ $phase ]['threshold'];
		$count     = easc_get_cleanup_phase_count( $phase, $types );

		if ( $count < $threshold ) {
			$state['skipped'][ $phase ] = array(
				'count'     => $count,
				'threshold' => $threshold,
			);
			easc_schedule_next_cleanup_step( $state, $phase );
			return;
		}
	}

	// Run batches until the phase is exhausted or the time budget runs out.
	do {
		$deleted = easc_run_cleanup_phase_batch( $phase, $batch_size, $types );

		if ( $deleted > 0 ) {
			$state['results'][ $phase ] = ( isset( $state['results'][ $phase ] ) ? (int) $state['results'][ $phase ] : 0 ) + $deleted;
		}

		if ( ( microtime( true ) - $start ) >= $time_budget ) {
			set_transient( 'easc_cleanup_state', $state, DAY_IN_SECONDS );
			as_schedule_single_action( time() + 5, 'easc_scheduled_cleanup_step', array( $phase ), 'easc' );
			return;
		}
	} while ( $deleted > 0 );

	easc_schedule_next_cleanup_step( $state, $phase );
}

/**
 * Persist state and queue the next phase (or finalize).
 *
 * @since 1.3.0
 * @param array  $state         Cleanup state by reference value.
 * @param string $current_phase Phase that just finished or was skipped.
 */
function easc_schedule_next_cleanup_step( $state, $current_phase ) {
	$next = easc_next_cleanup_phase( $current_phase );
	set_transient( 'easc_cleanup_state', $state, DAY_IN_SECONDS );

	if ( function_exists( 'as_schedule_single_action' ) ) {
		as_schedule_single_action( time(), 'easc_scheduled_cleanup_step', array( $next ), 'easc' );
	}
}

/**
 * Finalize a scheduled cleanup: orphans, log entry, notification email.
 *
 * @since 1.3.0
 * @param array $state Cleanup state at finalize time.
 */
function easc_finalize_scheduled_cleanup( $state ) {
	if ( ! empty( $state['results'] ) ) {
		easc_clean_orphaned_data();
	}

	$status = ! empty( $state['results'] ) ? 'success' : 'skipped';

	easc_save_last_run_log( $status, $state['results'], '', $state['skipped'] );

	if ( 'success' === $status ) {
		$sizes_before = isset( $state['sizes_before'] ) ? $state['sizes_before'] : array( 'total_size' => 0 );
		easc_send_cleanup_report( $state['results'], $sizes_before );
	}
}

/**
 * Legacy synchronous scheduled cleanup (fallback when AS is unavailable).
 *
 * Same logic the plugin used before 1.3, kept for environments where
 * Action Scheduler did not load yet on cron tick.
 *
 * @since 1.3.0
 * @param array $state Pre-built state (used for sizes_before and settings).
 */
function easc_run_scheduled_cleanup_sync( $state ) {
	$settings   = $state['settings'];
	$batch_size = (int) apply_filters( 'easc_batch_size', 10000, 'scheduled' );
	$types      = $settings['types'];
	$results    = array();
	$skipped    = array();

	foreach ( easc_get_cleanup_phases() as $phase ) {
		if ( empty( $types[ $phase ]['enabled'] ) ) {
			continue;
		}

		$threshold = (int) $types[ $phase ]['threshold'];
		$count     = easc_get_cleanup_phase_count( $phase, $types );

		if ( $count < $threshold ) {
			$skipped[ $phase ] = array(
				'count'     => $count,
				'threshold' => $threshold,
			);
			continue;
		}

		do {
			$deleted = easc_run_cleanup_phase_batch( $phase, $batch_size, $types );
			if ( $deleted > 0 ) {
				$results[ $phase ] = ( isset( $results[ $phase ] ) ? (int) $results[ $phase ] : 0 ) + $deleted;
			}
		} while ( $deleted > 0 );
	}

	$state['results'] = $results;
	$state['skipped'] = $skipped;

	easc_finalize_scheduled_cleanup( $state );
	delete_transient( 'easc_cleanup_state' );
}

/**
 * Save last run log to transient
 *
 * @since 1.1.0
 * @param string $status  Run status: 'success', 'skipped', or 'error'.
 * @param array  $results Deletion results by type.
 * @param string $error   Error message if status is 'error'.
 * @param array  $skipped Skipped types with count/threshold info.
 */
function easc_save_last_run_log( $status, $results = array(), $error = '', $skipped = array() ) {
	$log_entry = array(
		'time'    => current_time( 'mysql' ),
		'status'  => $status,
		'results' => $results,
		'skipped' => $skipped,
		'error'   => $error,
	);

	set_transient( 'easc_last_scheduled_run', $log_entry, 90 * DAY_IN_SECONDS );
}

/**
 * Save schedule settings and manage cron event
 *
 * @since 1.1.0
 * @param array $new_settings The settings to save.
 */
function easc_save_schedule_settings( $new_settings ) {
	$defaults = easc_get_default_schedule_settings();

	// Sanitize and validate.
	$sanitized = array(
		'enabled'   => ! empty( $new_settings['enabled'] ),
		'frequency' => 'weekly',
		'types'     => array(),
	);

	// Validate frequency.
	$valid_frequencies = array_keys( easc_get_frequency_options() );
	if ( isset( $new_settings['frequency'] ) && in_array( $new_settings['frequency'], $valid_frequencies, true ) ) {
		$sanitized['frequency'] = $new_settings['frequency'];
	}

	// Sanitize type settings.
	foreach ( $defaults['types'] as $type_key => $type_defaults ) {
		$sanitized['types'][ $type_key ] = array(
			'enabled'   => ! empty( $new_settings['types'][ $type_key ]['enabled'] ),
			'threshold' => isset( $new_settings['types'][ $type_key ]['threshold'] )
				? max( 1, absint( $new_settings['types'][ $type_key ]['threshold'] ) )
				: $type_defaults['threshold'],
		);

		// Days setting only for pending_old.
		if ( 'pending_old' === $type_key ) {
			$sanitized['types'][ $type_key ]['days'] = isset( $new_settings['types'][ $type_key ]['days'] )
				? max( 7, absint( $new_settings['types'][ $type_key ]['days'] ) )
				: $type_defaults['days'];
		}
	}

	// Save the option.
	update_option( 'easc_schedule_settings', $sanitized );

	// Manage cron event.
	if ( $sanitized['enabled'] ) {
		easc_schedule_cron_event( $sanitized['frequency'] );

		// User just turned the scheduled cleanup on: clear any previous
		// "large site" notice dismissals so the next time it matters it shows again.
		if ( function_exists( 'easc_reset_large_site_notice_dismissals' ) ) {
			easc_reset_large_site_notice_dismissals();
		}
	} else {
		easc_unschedule_cron_event();
	}
}
