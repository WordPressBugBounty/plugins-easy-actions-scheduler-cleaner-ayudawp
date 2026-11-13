<?php
/**
 * Uninstall handler for Easy Actions Scheduler Cleaner
 *
 * @package EasyActionsSchedulerCleanerAyudaWP
 * @since 1.0.0
 */

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Delete plugin options.
delete_option( 'easy_actions_scheduler_cleaner_ayudawp_redirect' );
delete_option( 'easc_schedule_settings' );
delete_option( 'easc_notification_settings' );
delete_option( 'easc_last_report_sent' );

// Clean up transients.
delete_transient( 'easy_actions_scheduler_cleaner_ayudawp_status' );
delete_transient( 'easc_last_scheduled_run' );
delete_transient( 'easc_last_accumulation_alert' );
delete_transient( 'easc_last_missed_alert' );

// Unschedule cron events.
$easc_timestamp = wp_next_scheduled( 'easc_scheduled_cleanup' );
if ( $easc_timestamp ) {
	wp_unschedule_event( $easc_timestamp, 'easc_scheduled_cleanup' );
}

$easc_report_timestamp = wp_next_scheduled( 'easc_send_report_email' );
if ( $easc_report_timestamp ) {
	wp_unschedule_event( $easc_report_timestamp, 'easc_send_report_email' );
}

$easc_alert_timestamp = wp_next_scheduled( 'easc_check_accumulation' );
if ( $easc_alert_timestamp ) {
	wp_unschedule_event( $easc_alert_timestamp, 'easc_check_accumulation' );
}

// Clean up cache entries.
wp_cache_delete( 'easc_status_counts', 'easy_actions_scheduler_cleaner_ayudawp' );
wp_cache_delete( 'easc_logs_count', 'easy_actions_scheduler_cleaner_ayudawp' );
wp_cache_delete( 'easc_table_sizes', 'easy_actions_scheduler_cleaner_ayudawp' );
wp_cache_delete( 'easc_table_exists_' . md5( $GLOBALS['wpdb']->prefix ), 'easy_actions_scheduler_cleaner_ayudawp' );
wp_cache_delete( 'easc_logs_table_exists_' . md5( $GLOBALS['wpdb']->prefix ), 'easy_actions_scheduler_cleaner_ayudawp' );
