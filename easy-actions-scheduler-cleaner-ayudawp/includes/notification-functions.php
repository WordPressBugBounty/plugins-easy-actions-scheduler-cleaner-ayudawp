<?php
/**
 * Notification functions for Easy Actions Scheduler Cleaner
 *
 * Handles email notifications: periodic cleanup reports,
 * accumulation alerts, and missed cleanup warnings.
 *
 * @package EasyActionsSchedulerCleanerAyudaWP
 * @since 1.2.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Initialize notification system
 *
 * @since 1.2.0
 */
function easc_init_notifications() {
	add_action( 'easc_send_report_email', 'easc_send_scheduled_report' );
	add_action( 'easc_check_accumulation', 'easc_check_accumulation_alert' );
	add_filter( 'cron_schedules', 'easc_add_notification_cron_intervals' );
}

/**
 * Add notification-specific cron interval (monthly)
 *
 * Daily and weekly are built into WordPress. We only add monthly.
 *
 * @since 1.2.0
 * @param array $schedules Existing cron schedules.
 * @return array Modified schedules.
 */
function easc_add_notification_cron_intervals( $schedules ) {
	if ( ! isset( $schedules['easc_monthly'] ) ) {
		$schedules['easc_monthly'] = array(
			'interval' => 30 * DAY_IN_SECONDS,
			'display'  => esc_html__( 'Monthly (every 30 days)', 'easy-actions-scheduler-cleaner-ayudawp' ),
		);
	}

	return $schedules;
}

/**
 * Get default notification settings
 *
 * @since 1.2.0
 * @return array
 */
function easc_get_default_notification_settings() {
	return array(
		'report_enabled'       => false,
		'report_frequency'     => 'weekly',
		'report_emails'        => '',
		'alert_enabled'        => false,
		'alert_threshold'      => 5000,
		'alert_missed_enabled' => false,
		'alert_emails'         => '',
	);
}

/**
 * Get notification settings
 *
 * @since 1.2.0
 * @return array
 */
function easc_get_notification_settings() {
	$defaults = easc_get_default_notification_settings();
	$settings = get_option( 'easc_notification_settings', array() );

	return wp_parse_args( $settings, $defaults );
}

/**
 * Save notification settings and manage cron events
 *
 * @since 1.2.0
 * @param array $new_settings Settings to save.
 */
function easc_save_notification_settings( $new_settings ) {
	$sanitized = array(
		'report_enabled'       => ! empty( $new_settings['report_enabled'] ),
		'report_frequency'     => 'weekly',
		'report_emails'        => '',
		'alert_enabled'        => ! empty( $new_settings['alert_enabled'] ),
		'alert_threshold'      => 5000,
		'alert_missed_enabled' => ! empty( $new_settings['alert_missed_enabled'] ),
		'alert_emails'         => '',
	);

	// Validate report frequency.
	$valid_frequencies = array( 'daily', 'weekly', 'monthly' );
	if ( isset( $new_settings['report_frequency'] ) && in_array( $new_settings['report_frequency'], $valid_frequencies, true ) ) {
		$sanitized['report_frequency'] = $new_settings['report_frequency'];
	}

	// Sanitize report emails.
	if ( ! empty( $new_settings['report_emails'] ) ) {
		$sanitized['report_emails'] = easc_sanitize_email_list( $new_settings['report_emails'] );
	}

	// Sanitize alert emails.
	if ( ! empty( $new_settings['alert_emails'] ) ) {
		$sanitized['alert_emails'] = easc_sanitize_email_list( $new_settings['alert_emails'] );
	}

	// Validate alert threshold.
	if ( isset( $new_settings['alert_threshold'] ) ) {
		$sanitized['alert_threshold'] = max( 100, absint( $new_settings['alert_threshold'] ) );
	}

	update_option( 'easc_notification_settings', $sanitized );

	// Manage report cron.
	if ( $sanitized['report_enabled'] ) {
		easc_schedule_report_cron( $sanitized['report_frequency'] );
	} else {
		easc_unschedule_report_cron();
	}

	// Manage accumulation check cron (runs daily if alerts enabled).
	if ( $sanitized['alert_enabled'] || $sanitized['alert_missed_enabled'] ) {
		if ( ! wp_next_scheduled( 'easc_check_accumulation' ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'easc_check_accumulation' );
		}
	} else {
		$timestamp = wp_next_scheduled( 'easc_check_accumulation' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'easc_check_accumulation' );
		}
	}
}

/**
 * Sanitize a comma-separated list of emails
 *
 * @since 1.2.0
 * @param string $emails_string Comma-separated emails.
 * @return string Sanitized comma-separated emails.
 */
function easc_sanitize_email_list( $emails_string ) {
	$emails    = array_map( 'trim', explode( ',', $emails_string ) );
	$valid     = array();

	foreach ( $emails as $email ) {
		$sanitized = sanitize_email( $email );
		if ( is_email( $sanitized ) ) {
			$valid[] = $sanitized;
		}
	}

	return implode( ', ', array_unique( $valid ) );
}

/**
 * Get recipients for report emails
 *
 * Uses custom emails if set, otherwise falls back to admin_email.
 *
 * @since 1.2.0
 * @return array Array of email addresses.
 */
function easc_get_report_recipients() {
	$settings = easc_get_notification_settings();

	if ( ! empty( $settings['report_emails'] ) ) {
		return array_map( 'trim', explode( ',', $settings['report_emails'] ) );
	}

	return array( get_option( 'admin_email' ) );
}

/**
 * Get recipients for alert emails
 *
 * Uses custom emails if set, otherwise falls back to admin_email.
 *
 * @since 1.2.0
 * @return array Array of email addresses.
 */
function easc_get_alert_recipients() {
	$settings = easc_get_notification_settings();

	if ( ! empty( $settings['alert_emails'] ) ) {
		return array_map( 'trim', explode( ',', $settings['alert_emails'] ) );
	}

	return array( get_option( 'admin_email' ) );
}

/**
 * Schedule or reschedule report cron event
 *
 * @since 1.2.0
 * @param string $frequency Report frequency.
 */
function easc_schedule_report_cron( $frequency ) {
	easc_unschedule_report_cron();

	$recurrence_map = array(
		'daily'   => 'daily',
		'weekly'  => 'weekly',
		'monthly' => 'easc_monthly',
	);

	$interval_map = array(
		'daily'   => DAY_IN_SECONDS,
		'weekly'  => WEEK_IN_SECONDS,
		'monthly' => 30 * DAY_IN_SECONDS,
	);

	$recurrence = isset( $recurrence_map[ $frequency ] ) ? $recurrence_map[ $frequency ] : 'weekly';
	$interval   = isset( $interval_map[ $frequency ] ) ? $interval_map[ $frequency ] : WEEK_IN_SECONDS;

	wp_schedule_event( time() + $interval, $recurrence, 'easc_send_report_email' );
}

/**
 * Unschedule report cron event
 *
 * @since 1.2.0
 */
function easc_unschedule_report_cron() {
	$timestamp = wp_next_scheduled( 'easc_send_report_email' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'easc_send_report_email' );
	}
}

/**
 * Send scheduled report email
 *
 * Called by WP-Cron. Sends a summary of the current database state
 * and recent cleanup activity.
 *
 * @since 1.2.0
 */
function easc_send_scheduled_report() {
	$recipients = easc_get_report_recipients();
	if ( empty( $recipients ) ) {
		return;
	}

	$site_name = get_bloginfo( 'name' );
	$counts    = easc_get_database_status();
	$logs      = easc_get_logs_count();
	$sizes     = easc_get_table_sizes();
	$last_run  = get_transient( 'easc_last_scheduled_run' );

	/* translators: %s: Site name */
	$subject = sprintf( __( '[%s] Actions Scheduler Cleaner - Status report', 'easy-actions-scheduler-cleaner-ayudawp' ), $site_name );

	$html = easc_build_report_email_html( $counts, $logs, $sizes, $last_run );

	$headers = array( 'Content-Type: text/html; charset=UTF-8' );

	wp_mail( $recipients, $subject, $html, $headers );

	// Store last report sent time.
	update_option( 'easc_last_report_sent', current_time( 'mysql' ) );
}

/**
 * Send cleanup completion report
 *
 * Called after a scheduled cleanup finishes successfully.
 *
 * @since 1.2.0
 * @param array $results Cleanup results by type.
 * @param array $sizes_before Table sizes before cleanup.
 */
function easc_send_cleanup_report( $results, $sizes_before ) {
	$settings = easc_get_notification_settings();
	if ( empty( $settings['report_enabled'] ) ) {
		return;
	}

	$recipients = easc_get_report_recipients();
	if ( empty( $recipients ) ) {
		return;
	}

	// Get sizes after cleanup for comparison.
	easc_invalidate_space_cache();
	$sizes_after = easc_get_table_sizes();

	$space_freed = round( $sizes_before['total_size'] - $sizes_after['total_size'], 2 );
	if ( $space_freed < 0 ) {
		$space_freed = 0;
	}

	$site_name = get_bloginfo( 'name' );

	$subject = sprintf(
		/* translators: %1$s: Site name, %2$s: Space freed */
		__( '[%1$s] Cleanup completed - %2$s freed', 'easy-actions-scheduler-cleaner-ayudawp' ),
		$site_name,
		easc_format_size( $space_freed )
	);

	$html = easc_build_cleanup_report_html( $results, $sizes_before, $sizes_after, $space_freed );

	$headers = array( 'Content-Type: text/html; charset=UTF-8' );

	wp_mail( $recipients, $subject, $html, $headers );
}

/**
 * Check for accumulation alert conditions
 *
 * Called daily by WP-Cron. Checks if total records exceed
 * threshold and no scheduled cleanup is active.
 *
 * @since 1.2.0
 */
function easc_check_accumulation_alert() {
	$settings = easc_get_notification_settings();

	// Check accumulation threshold.
	if ( ! empty( $settings['alert_enabled'] ) ) {
		$schedule_settings = easc_get_schedule_settings();
		$is_scheduled      = ! empty( $schedule_settings['enabled'] );

		// Only alert if no scheduled cleanup is active.
		if ( ! $is_scheduled ) {
			$total = easc_get_total_record_count();

			if ( $total >= (int) $settings['alert_threshold'] ) {
				// Limit to one alert per week.
				$last_alert = get_transient( 'easc_last_accumulation_alert' );
				if ( ! $last_alert ) {
					easc_send_accumulation_alert( $total, (int) $settings['alert_threshold'] );
					set_transient( 'easc_last_accumulation_alert', time(), WEEK_IN_SECONDS );
				}
			}
		}
	}

	// Check for missed scheduled cleanup.
	if ( ! empty( $settings['alert_missed_enabled'] ) ) {
		easc_check_missed_cleanup();
	}
}

/**
 * Get total record count across all cleanable types
 *
 * @since 1.2.0
 * @return int Total cleanable records.
 */
function easc_get_total_record_count() {
	$counts = easc_get_database_status();
	$total  = 0;

	if ( $counts ) {
		foreach ( $counts as $row ) {
			$total += (int) $row->count;
		}
	}

	$total += easc_get_logs_count();

	return $total;
}

/**
 * Send accumulation alert email
 *
 * @since 1.2.0
 * @param int $total     Current total records.
 * @param int $threshold Configured threshold.
 */
function easc_send_accumulation_alert( $total, $threshold ) {
	$recipients = easc_get_alert_recipients();
	if ( empty( $recipients ) ) {
		return;
	}

	$site_name = get_bloginfo( 'name' );
	$sizes     = easc_get_table_sizes();

	$subject = sprintf(
		/* translators: %1$s: Site name, %2$s: Number of records */
		__( '[%1$s] Alert: %2$s Action Scheduler records accumulated', 'easy-actions-scheduler-cleaner-ayudawp' ),
		$site_name,
		number_format_i18n( $total )
	);

	$html = easc_build_accumulation_alert_html( $total, $threshold, $sizes );

	$headers = array( 'Content-Type: text/html; charset=UTF-8' );

	wp_mail( $recipients, $subject, $html, $headers );
}

/**
 * Check for missed scheduled cleanup
 *
 * Detects when a scheduled cleanup should have run but did not,
 * typically due to WP-Cron issues.
 *
 * @since 1.2.0
 */
function easc_check_missed_cleanup() {
	$schedule_settings = easc_get_schedule_settings();
	if ( empty( $schedule_settings['enabled'] ) ) {
		return;
	}

	$next_run = wp_next_scheduled( 'easc_scheduled_cleanup' );
	if ( ! $next_run ) {
		// Event is missing entirely — it should exist if schedule is enabled.
		$last_missed_alert = get_transient( 'easc_last_missed_alert' );
		if ( ! $last_missed_alert ) {
			easc_send_missed_cleanup_alert( 'missing' );
			set_transient( 'easc_last_missed_alert', time(), WEEK_IN_SECONDS );
		}
		return;
	}

	// Check if the scheduled event is overdue by more than 1 hour.
	$overdue_threshold = HOUR_IN_SECONDS;
	if ( $next_run < ( time() - $overdue_threshold ) ) {
		$last_missed_alert = get_transient( 'easc_last_missed_alert' );
		if ( ! $last_missed_alert ) {
			easc_send_missed_cleanup_alert( 'overdue', $next_run );
			set_transient( 'easc_last_missed_alert', time(), WEEK_IN_SECONDS );
		}
	}
}

/**
 * Send missed cleanup alert email
 *
 * @since 1.2.0
 * @param string   $reason   Either 'missing' or 'overdue'.
 * @param int|null $expected Expected timestamp (for overdue).
 */
function easc_send_missed_cleanup_alert( $reason, $expected = null ) {
	$recipients = easc_get_alert_recipients();
	if ( empty( $recipients ) ) {
		return;
	}

	$site_name = get_bloginfo( 'name' );

	$subject = sprintf(
		/* translators: %s: Site name */
		__( '[%s] Alert: Scheduled cleanup may have failed', 'easy-actions-scheduler-cleaner-ayudawp' ),
		$site_name
	);

	$html = easc_build_missed_alert_html( $reason, $expected );

	$headers = array( 'Content-Type: text/html; charset=UTF-8' );

	wp_mail( $recipients, $subject, $html, $headers );
}

/* ========================================================================
 * Email HTML templates — all styles inline for email client compatibility
 * ======================================================================== */

/**
 * Get email header HTML with inline styles
 *
 * @since 1.2.0
 * @param string $title    Header title.
 * @param string $subtitle Optional subtitle.
 * @return string HTML.
 */
function easc_get_email_header( $title, $subtitle = '' ) {
	$html  = '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>';
	$html .= '<body style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;color:#333;line-height:1.6;margin:0;padding:0;background:#f5f5f5;">';
	$html .= '<div style="max-width:600px;margin:20px auto;background:#fff;border:1px solid #ddd;border-radius:6px;overflow:hidden;">';
	$html .= '<div style="background:#007cba;color:#fff;padding:20px 25px;">';
	$html .= '<h1 style="margin:0;font-size:18px;font-weight:600;color:#fff;">' . esc_html( $title ) . '</h1>';
	if ( $subtitle ) {
		$html .= '<p style="margin:5px 0 0;opacity:0.9;font-size:13px;color:#fff;">' . esc_html( $subtitle ) . '</p>';
	}
	$html .= '</div><div style="padding:25px;">';

	return $html;
}

/**
 * Get email footer HTML with inline styles
 *
 * @since 1.2.0
 * @return string HTML.
 */
function easc_get_email_footer() {
	$settings_url = admin_url( 'tools.php?page=easy-actions-scheduler-cleaner-ayudawp&tab=notifications' );

	$html  = '</div>'; // Close email-body.
	$html .= '<div style="padding:15px 25px;background:#f8f9fa;border-top:1px solid #eee;font-size:12px;color:#6c757d;">';
	$html .= '<p style="margin:0;font-size:12px;color:#6c757d;">' . wp_kses(
		sprintf(
			/* translators: %s: Plugin settings URL */
			__( 'Sent by <strong>Easy Actions Scheduler Cleaner</strong> &mdash; <a href="%s">Manage notification settings</a>', 'easy-actions-scheduler-cleaner-ayudawp' ),
			esc_url( $settings_url )
		),
		array(
			'strong' => array(),
			'a'      => array( 'href' => array() ),
		)
	) . '</p>';
	$html .= '</div></div></body></html>';

	return $html;
}

/**
 * Build an email table opening tag with inline styles
 *
 * @since 1.2.0
 * @return string HTML table open with thead opening.
 */
function easc_email_table_open() {
	return '<table style="width:100%;border-collapse:collapse;margin:15px 0;" cellpadding="0" cellspacing="0"><thead><tr>';
}

/**
 * Build a table header cell with inline styles
 *
 * @since 1.2.0
 * @param string $text Header text.
 * @return string HTML th element.
 */
function easc_email_th( $text ) {
	return '<th style="padding:10px 12px;border-bottom:2px solid #dee2e6;text-align:left;font-size:13px;background:#f8f9fa;font-weight:600;color:#495057;">' . esc_html( $text ) . '</th>';
}

/**
 * Build a table cell with inline styles
 *
 * @since 1.2.0
 * @param string $text Cell text.
 * @return string HTML td element.
 */
function easc_email_td( $text ) {
	return '<td style="padding:10px 12px;border-bottom:1px solid #eee;text-align:left;font-size:13px;">' . esc_html( $text ) . '</td>';
}

/**
 * Build a total row with inline styles
 *
 * @since 1.2.0
 * @param string $label Row label.
 * @param string $value Row value.
 * @return string HTML tr element.
 */
function easc_email_total_row( $label, $value ) {
	$td_style = 'padding:10px 12px;text-align:left;font-size:13px;font-weight:600;background:#e8f5e8;color:#155724;border-top:2px solid #c3e6cb;';
	return '<tr><td style="' . $td_style . '">' . esc_html( $label ) . '</td><td style="' . $td_style . '">' . esc_html( $value ) . '</td></tr>';
}

/**
 * Build a CTA button with inline styles
 *
 * @since 1.2.0
 * @param string $url  Button URL.
 * @param string $text Button text.
 * @return string HTML paragraph with link.
 */
function easc_email_button( $url, $text ) {
	return '<p style="margin:15px 0;"><a href="' . esc_url( $url ) . '" style="display:inline-block;padding:10px 20px;background:#007cba;color:#ffffff;text-decoration:none;border-radius:4px;font-size:14px;font-weight:500;">' . esc_html( $text ) . '</a></p>';
}

/**
 * Build status report email HTML
 *
 * @since 1.2.0
 * @param array|false $counts   Database status counts.
 * @param int         $logs     Logs count.
 * @param array       $sizes    Table sizes.
 * @param array|false $last_run Last run data.
 * @return string Complete HTML email.
 */
function easc_build_report_email_html( $counts, $logs, $sizes, $last_run ) {
	$site_name   = get_bloginfo( 'name' );
	$type_labels = easc_get_schedule_type_labels();

	$html = easc_get_email_header(
		__( 'Actions Scheduler Status Report', 'easy-actions-scheduler-cleaner-ayudawp' ),
		$site_name . ' — ' . wp_date( get_option( 'date_format' ) )
	);

	// Current database state.
	$html .= '<p style="margin:0 0 15px;font-size:14px;">' . esc_html__( 'Here is the current state of your Action Scheduler database:', 'easy-actions-scheduler-cleaner-ayudawp' ) . '</p>';

	$html .= easc_email_table_open();
	$html .= easc_email_th( __( 'Record type', 'easy-actions-scheduler-cleaner-ayudawp' ) );
	$html .= easc_email_th( __( 'Count', 'easy-actions-scheduler-cleaner-ayudawp' ) );
	$html .= '</tr></thead><tbody>';

	$td_style = 'padding:10px 12px;border-bottom:1px solid #eee;text-align:left;font-size:13px;';
	$total    = 0;
	if ( $counts ) {
		foreach ( $counts as $row ) {
			$html  .= '<tr><td style="' . $td_style . '">' . esc_html( easc_get_status_label( $row->status ) ) . '</td>';
			$html  .= '<td style="' . $td_style . '">' . esc_html( number_format_i18n( $row->count ) ) . '</td></tr>';
			$total += (int) $row->count;
		}
	}
	if ( $logs > 0 ) {
		$html  .= '<tr><td style="' . $td_style . '">' . esc_html__( 'Action Logs', 'easy-actions-scheduler-cleaner-ayudawp' ) . '</td>';
		$html  .= '<td style="' . $td_style . '">' . esc_html( number_format_i18n( $logs ) ) . '</td></tr>';
		$total += $logs;
	}

	$html .= easc_email_total_row( __( 'Total records', 'easy-actions-scheduler-cleaner-ayudawp' ), number_format_i18n( $total ) );
	$html .= '</tbody></table>';

	// Space usage.
	$html .= '<p style="margin:0 0 15px;font-size:14px;">' . esc_html__( 'Database space usage:', 'easy-actions-scheduler-cleaner-ayudawp' ) . ' <strong>' . esc_html( easc_format_size( $sizes['total_size'] ) ) . '</strong></p>';

	// Last run info.
	if ( $last_run && isset( $last_run['status'] ) ) {
		if ( 'success' === $last_run['status'] && ! empty( $last_run['results'] ) ) {
			$deleted_total = array_sum( $last_run['results'] );
			$html         .= '<p style="margin:0 0 15px;font-size:14px;">' . esc_html(
				sprintf(
					/* translators: %1$s: Date, %2$s: Number of records */
					__( 'Last cleanup (%1$s): %2$s records deleted.', 'easy-actions-scheduler-cleaner-ayudawp' ),
					$last_run['time'],
					number_format_i18n( $deleted_total )
				)
			) . '</p>';
		} elseif ( 'skipped' === $last_run['status'] ) {
			$html .= '<p style="margin:0 0 15px;font-size:14px;">' . esc_html(
				sprintf(
					/* translators: %s: Date */
					__( 'Last cleanup (%s): Skipped, all counts were below thresholds.', 'easy-actions-scheduler-cleaner-ayudawp' ),
					$last_run['time']
				)
			) . '</p>';
		}
	}

	// Next run.
	$next_run = wp_next_scheduled( 'easc_scheduled_cleanup' );
	if ( $next_run ) {
		$next_local = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $next_run ), get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
		/* translators: %s: Date and time of next cleanup */
		$html .= '<p style="margin:0 0 15px;font-size:14px;">' . esc_html( sprintf( __( 'Next scheduled cleanup: %s', 'easy-actions-scheduler-cleaner-ayudawp' ), $next_local ) ) . '</p>';
	}

	$html .= easc_get_email_footer();

	return $html;
}

/**
 * Build cleanup completion report email HTML
 *
 * @since 1.2.0
 * @param array $results      Cleanup results by type.
 * @param array $sizes_before Table sizes before cleanup.
 * @param array $sizes_after  Table sizes after cleanup.
 * @param float $space_freed  MB freed.
 * @return string Complete HTML email.
 */
function easc_build_cleanup_report_html( $results, $sizes_before, $sizes_after, $space_freed ) {
	$site_name   = get_bloginfo( 'name' );
	$type_labels = easc_get_schedule_type_labels();

	$html = easc_get_email_header(
		__( 'Cleanup completed successfully', 'easy-actions-scheduler-cleaner-ayudawp' ),
		$site_name . ' — ' . wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) )
	);

	$total_deleted = array_sum( $results );

	$html .= '<p style="margin:0 0 15px;font-size:14px;">' . esc_html(
		sprintf(
			/* translators: %s: Number of records deleted */
			__( 'A scheduled cleanup has been completed. %s records were removed from the database.', 'easy-actions-scheduler-cleaner-ayudawp' ),
			number_format_i18n( $total_deleted )
		)
	) . '</p>';

	if ( $space_freed > 0 ) {
		$html .= '<p style="margin:0 0 15px;font-size:14px;">' . esc_html__( 'Estimated space freed:', 'easy-actions-scheduler-cleaner-ayudawp' ) . ' <span style="display:inline-block;background:#28a745;color:#fff;padding:3px 10px;border-radius:12px;font-size:13px;font-weight:600;">' . esc_html( easc_format_size( $space_freed ) ) . '</span></p>';
	}

	// Results table.
	$html .= easc_email_table_open();
	$html .= easc_email_th( __( 'Record type', 'easy-actions-scheduler-cleaner-ayudawp' ) );
	$html .= easc_email_th( __( 'Deleted', 'easy-actions-scheduler-cleaner-ayudawp' ) );
	$html .= '</tr></thead><tbody>';

	$td_style = 'padding:10px 12px;border-bottom:1px solid #eee;text-align:left;font-size:13px;';
	foreach ( $results as $type_key => $deleted_count ) {
		$label = isset( $type_labels[ $type_key ]['label'] ) ? $type_labels[ $type_key ]['label'] : $type_key;
		$html .= '<tr><td style="' . $td_style . '">' . esc_html( $label ) . '</td>';
		$html .= '<td style="' . $td_style . '">' . esc_html( number_format_i18n( $deleted_count ) ) . '</td></tr>';
	}

	$html .= easc_email_total_row( __( 'Total deleted', 'easy-actions-scheduler-cleaner-ayudawp' ), number_format_i18n( $total_deleted ) );
	$html .= '</tbody></table>';

	// Space comparison.
	$html .= easc_email_table_open();
	$html .= easc_email_th( __( 'Database space', 'easy-actions-scheduler-cleaner-ayudawp' ) );
	$html .= easc_email_th( __( 'Size', 'easy-actions-scheduler-cleaner-ayudawp' ) );
	$html .= '</tr></thead><tbody>';
	$html .= '<tr><td style="' . $td_style . '">' . esc_html__( 'Before cleanup', 'easy-actions-scheduler-cleaner-ayudawp' ) . '</td><td style="' . $td_style . '">' . esc_html( easc_format_size( $sizes_before['total_size'] ) ) . '</td></tr>';
	$html .= '<tr><td style="' . $td_style . '">' . esc_html__( 'After cleanup', 'easy-actions-scheduler-cleaner-ayudawp' ) . '</td><td style="' . $td_style . '">' . esc_html( easc_format_size( $sizes_after['total_size'] ) ) . '</td></tr>';
	$html .= easc_email_total_row( __( 'Space freed', 'easy-actions-scheduler-cleaner-ayudawp' ), easc_format_size( $space_freed ) );
	$html .= '</tbody></table>';

	$html .= easc_get_email_footer();

	return $html;
}

/**
 * Build accumulation alert email HTML
 *
 * @since 1.2.0
 * @param int   $total     Current total records.
 * @param int   $threshold Configured threshold.
 * @param array $sizes     Table sizes.
 * @return string Complete HTML email.
 */
function easc_build_accumulation_alert_html( $total, $threshold, $sizes ) {
	$site_name = get_bloginfo( 'name' );

	$html = easc_get_email_header(
		__( 'Record accumulation alert', 'easy-actions-scheduler-cleaner-ayudawp' ),
		$site_name
	);

	$html .= '<div style="background:#fff3cd;border:1px solid #ffc107;border-radius:4px;padding:15px;margin:15px 0;">';
	$html .= '<p style="margin:0 0 10px;font-size:14px;"><strong>' . esc_html(
		sprintf(
			/* translators: %1$s: Current count, %2$s: Threshold */
			__( 'Your Action Scheduler database has accumulated %1$s records, exceeding your configured threshold of %2$s.', 'easy-actions-scheduler-cleaner-ayudawp' ),
			number_format_i18n( $total ),
			number_format_i18n( $threshold )
		)
	) . '</strong></p>';
	$html .= '<p style="margin:0;font-size:14px;">' . esc_html__( 'No automatic cleanup is currently scheduled. Consider enabling scheduled cleanup to keep your database optimized.', 'easy-actions-scheduler-cleaner-ayudawp' ) . '</p>';
	$html .= '</div>';

	$html .= '<p style="margin:0 0 15px;font-size:14px;">' . esc_html__( 'Current database space usage:', 'easy-actions-scheduler-cleaner-ayudawp' ) . ' <strong>' . esc_html( easc_format_size( $sizes['total_size'] ) ) . '</strong></p>';

	$settings_url = admin_url( 'tools.php?page=easy-actions-scheduler-cleaner-ayudawp&tab=schedule' );
	$html        .= easc_email_button( $settings_url, __( 'Configure scheduled cleanup', 'easy-actions-scheduler-cleaner-ayudawp' ) );

	$html .= easc_get_email_footer();

	return $html;
}

/**
 * Build missed cleanup alert email HTML
 *
 * @since 1.2.0
 * @param string   $reason   Either 'missing' or 'overdue'.
 * @param int|null $expected Expected timestamp.
 * @return string Complete HTML email.
 */
function easc_build_missed_alert_html( $reason, $expected = null ) {
	$site_name = get_bloginfo( 'name' );

	$html = easc_get_email_header(
		__( 'Missed cleanup alert', 'easy-actions-scheduler-cleaner-ayudawp' ),
		$site_name
	);

	$html .= '<div style="background:#f8d7da;border:1px solid #f5c6cb;border-radius:4px;padding:15px;margin:15px 0;">';

	if ( 'missing' === $reason ) {
		$html .= '<p style="margin:0 0 10px;font-size:14px;"><strong>' . esc_html__( 'The scheduled cleanup event is missing from WP-Cron.', 'easy-actions-scheduler-cleaner-ayudawp' ) . '</strong></p>';
		$html .= '<p style="margin:0;font-size:14px;">' . esc_html__( 'This usually means WP-Cron is not running properly. Please check your server configuration or re-save the schedule settings.', 'easy-actions-scheduler-cleaner-ayudawp' ) . '</p>';
	} else {
		$expected_local = $expected ? get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $expected ), get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) : '';
		$html          .= '<p style="margin:0 0 10px;font-size:14px;"><strong>' . esc_html(
			sprintf(
				/* translators: %s: Expected date and time */
				__( 'A scheduled cleanup was expected at %s but has not run yet.', 'easy-actions-scheduler-cleaner-ayudawp' ),
				$expected_local
			)
		) . '</strong></p>';
		$html .= '<p style="margin:0;font-size:14px;">' . esc_html__( 'This may indicate that WP-Cron is not being triggered. If you use DISABLE_WP_CRON, make sure your system cron job is configured properly.', 'easy-actions-scheduler-cleaner-ayudawp' ) . '</p>';
	}

	$html .= '</div>';

	$settings_url = admin_url( 'tools.php?page=easy-actions-scheduler-cleaner-ayudawp&tab=schedule' );
	$html        .= easc_email_button( $settings_url, __( 'Check cleanup settings', 'easy-actions-scheduler-cleaner-ayudawp' ) );

	$html .= easc_get_email_footer();

	return $html;
}

/**
 * Send a test report email
 *
 * @since 1.2.0
 * @return bool Whether the email was sent.
 */
function easc_send_test_report() {
	$recipients = easc_get_report_recipients();
	if ( empty( $recipients ) ) {
		return false;
	}

	$site_name = get_bloginfo( 'name' );
	$counts    = easc_get_database_status();
	$logs      = easc_get_logs_count();
	$sizes     = easc_get_table_sizes();
	$last_run  = get_transient( 'easc_last_scheduled_run' );

	/* translators: %s: Site name */
	$subject = sprintf( __( '[%s] Actions Scheduler Cleaner - Test report', 'easy-actions-scheduler-cleaner-ayudawp' ), $site_name );

	$html = easc_build_report_email_html( $counts, $logs, $sizes, $last_run );

	$headers = array( 'Content-Type: text/html; charset=UTF-8' );

	return wp_mail( $recipients, $subject, $html, $headers );
}

/**
 * Send a test alert email
 *
 * @since 1.2.0
 * @return bool Whether the email was sent.
 */
function easc_send_test_alert() {
	$recipients = easc_get_alert_recipients();
	if ( empty( $recipients ) ) {
		return false;
	}

	$site_name = get_bloginfo( 'name' );
	$total     = easc_get_total_record_count();
	$settings  = easc_get_notification_settings();
	$sizes     = easc_get_table_sizes();

	/* translators: %s: Site name */
	$subject = sprintf( __( '[%s] Actions Scheduler Cleaner - Test alert', 'easy-actions-scheduler-cleaner-ayudawp' ), $site_name );

	$html = easc_build_accumulation_alert_html( $total, (int) $settings['alert_threshold'], $sizes );

	$headers = array( 'Content-Type: text/html; charset=UTF-8' );

	return wp_mail( $recipients, $subject, $html, $headers );
}