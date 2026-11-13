<?php
/**
 * Admin functions for Easy Actions Scheduler Cleaner
 *
 * @package EasyActionsSchedulerCleanerAyudaWP
 * @since 1.0.1
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Initialize admin functionality
 *
 * @since 1.0.1
 */
function easc_init_admin() {
	add_action( 'admin_menu', 'easc_add_admin_menu' );
	add_action( 'admin_init', 'easc_handle_actions' );
	add_action( 'admin_init', 'easc_handle_test_email' );
	add_action( 'admin_init', 'easc_handle_dismiss_large_site_notice' );
	add_action( 'admin_enqueue_scripts', 'easc_enqueue_scripts' );
	add_action( 'admin_notices', 'easc_maybe_show_large_site_notice' );
	add_filter( 'plugin_action_links_' . EASC_PLUGIN_BASENAME, 'easc_add_settings_link' );
}

/**
 * Show an admin notice when the site has accumulated a large number of
 * Action Scheduler records but the scheduled cleanup is not active.
 *
 * Dismissible per-user. The dismissal is reset automatically the next time
 * the threshold is exceeded after the user has cleaned things up.
 *
 * @since 1.3.0
 */
function easc_maybe_show_large_site_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// Hidden by user.
	if ( get_user_meta( get_current_user_id(), 'easc_large_site_notice_dismissed', true ) ) {
		return;
	}

	// Scheduled cleanup already active — nothing to suggest.
	$settings = easc_get_schedule_settings();
	if ( ! empty( $settings['enabled'] ) ) {
		return;
	}

	if ( ! easc_table_exists() ) {
		return;
	}

	if ( ! easc_is_large_site() ) {
		return;
	}

	$total      = easc_get_total_record_count();
	$plugin_url = admin_url( 'tools.php?page=easy-actions-scheduler-cleaner-ayudawp&tab=schedule' );
	$dismiss    = wp_nonce_url(
		add_query_arg( 'easc_dismiss_large_site', '1' ),
		'easc_dismiss_large_site'
	);

	?>
	<div class="notice notice-warning is-dismissible">
		<p>
			<strong><?php esc_html_e( 'Easy Actions Scheduler Cleaner:', 'easy-actions-scheduler-cleaner-ayudawp' ); ?></strong>
			<?php
			printf(
				/* translators: %s: total number of Action Scheduler records */
				esc_html__( 'we detected %s accumulated Action Scheduler records on this site and the scheduled cleanup is not active. We recommend enabling it to keep things under control automatically.', 'easy-actions-scheduler-cleaner-ayudawp' ),
				'<strong>' . esc_html( number_format_i18n( $total ) ) . '</strong>'
			);
			?>
		</p>
		<p>
			<a href="<?php echo esc_url( $plugin_url ); ?>" class="button button-primary">
				<?php esc_html_e( 'Enable scheduled cleanup', 'easy-actions-scheduler-cleaner-ayudawp' ); ?>
			</a>
			<a href="<?php echo esc_url( $dismiss ); ?>" class="button-link" style="margin-left:10px;">
				<?php esc_html_e( 'Dismiss', 'easy-actions-scheduler-cleaner-ayudawp' ); ?>
			</a>
		</p>
	</div>
	<?php
}

/**
 * Persist the dismissal of the large-site notice for the current user.
 *
 * @since 1.3.0
 */
function easc_handle_dismiss_large_site_notice() {
	if ( ! isset( $_GET['easc_dismiss_large_site'] ) ) {
		return;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'easc_dismiss_large_site' ) ) {
		return;
	}

	update_user_meta( get_current_user_id(), 'easc_large_site_notice_dismissed', 1 );

	wp_safe_redirect( remove_query_arg( array( 'easc_dismiss_large_site', '_wpnonce' ) ) );
	exit;
}

/**
 * Reset the per-user dismissal of the large-site notice for everyone.
 *
 * Called when scheduled cleanup is enabled or when records drop back below
 * the threshold, so the notice can fire again in the future if the situation
 * recurs.
 *
 * @since 1.3.0
 */
function easc_reset_large_site_notice_dismissals() {
	global $wpdb;
	// Direct delete on usermeta is the only way to reset for all users at once.
	$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->usermeta,
		array( 'meta_key' => 'easc_large_site_notice_dismissed' ) // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Array key for $wpdb->delete(), not a meta_query parameter.
	);
}

/**
 * Add admin menu
 *
 * Uses a shorter menu title for the submenu item.
 *
 * @since 1.0.0
 */
function easc_add_admin_menu() {
	add_management_page(
		esc_html__( 'Easy Actions Scheduler Cleaner', 'easy-actions-scheduler-cleaner-ayudawp' ),
		esc_html__( 'Actions Cleaner', 'easy-actions-scheduler-cleaner-ayudawp' ),
		'manage_options',
		'easy-actions-scheduler-cleaner-ayudawp',
		'easc_admin_page'
	);

	// Handle redirect after activation.
	if ( get_option( 'easy_actions_scheduler_cleaner_ayudawp_redirect', false ) ) {
		delete_option( 'easy_actions_scheduler_cleaner_ayudawp_redirect' );
		if ( ! isset( $_GET['activate-multi'] ) &&
			( ! isset( $_GET['_wpnonce'] ) || wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'activate-plugin_' . EASC_PLUGIN_BASENAME ) ) ) {
			wp_safe_redirect( admin_url( 'tools.php?page=easy-actions-scheduler-cleaner-ayudawp' ) );
			exit;
		}
	}
}

/**
 * Enqueue admin scripts and styles
 *
 * @since 1.0.0
 * @param string $hook Current admin page hook.
 */
function easc_enqueue_scripts( $hook ) {
	if ( 'tools_page_easy-actions-scheduler-cleaner-ayudawp' !== $hook ) {
		return;
	}

	wp_enqueue_style(
		'easc-admin-style',
		EASC_PLUGIN_URL . 'assets/admin-style.css',
		array(),
		EASC_PLUGIN_VERSION
	);

	wp_enqueue_script(
		'easc-admin-script',
		EASC_PLUGIN_URL . 'assets/admin-script.js',
		array(),
		EASC_PLUGIN_VERSION,
		true
	);
}

/**
 * Handle form actions
 *
 * @since 1.0.0
 */
function easc_handle_actions() {
	if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['easc_action'] ) ) {
		return;
	}

	if ( ! isset( $_POST['easc_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['easc_nonce'] ) ), 'easc_nonce_action' ) ) {
		wp_die( esc_html__( 'Security check failed. Please try again.', 'easy-actions-scheduler-cleaner-ayudawp' ) );
	}

	$action = sanitize_text_field( wp_unslash( $_POST['easc_action'] ) );

	switch ( $action ) {
		case 'cancel':
		case 'deactivate':
			easc_deactivate_plugin();
			break;
		case 'execute':
			easc_execute_cleanup();
			break;
		case 'save_schedule':
			easc_handle_save_schedule();
			break;
		case 'save_notifications':
			easc_handle_save_notifications();
			break;
	}
}

/**
 * Handle save schedule form submission
 *
 * @since 1.1.0
 */
function easc_handle_save_schedule() {
	// Verified here as well as in easc_handle_actions(), so the security check
	// lives in the same scope as the reads and does not depend on the caller.
	if ( ! isset( $_POST['easc_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['easc_nonce'] ) ), 'easc_nonce_action' ) ) {
		return;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$new_settings = array(
		'enabled'   => ! empty( $_POST['easc_schedule_enabled'] ),
		'frequency' => isset( $_POST['easc_frequency'] ) ? sanitize_text_field( wp_unslash( $_POST['easc_frequency'] ) ) : 'weekly',
		'types'     => array(),
	);

	$type_keys = array( 'complete', 'failed', 'canceled', 'past-due', 'pending_old', 'logs' );

	foreach ( $type_keys as $type_key ) {
		$safe_key = str_replace( '-', '_', $type_key );

		$new_settings['types'][ $type_key ] = array(
			'enabled'   => ! empty( $_POST[ 'easc_type_' . $safe_key . '_enabled' ] ),
			'threshold' => isset( $_POST[ 'easc_type_' . $safe_key . '_threshold' ] )
				? absint( wp_unslash( $_POST[ 'easc_type_' . $safe_key . '_threshold' ] ) )
				: 100,
		);

		if ( 'pending_old' === $type_key ) {
			$new_settings['types'][ $type_key ]['days'] = isset( $_POST['easc_type_pending_old_days'] )
				? absint( wp_unslash( $_POST['easc_type_pending_old_days'] ) )
				: 30;
		}
	}

	easc_save_schedule_settings( $new_settings );

	// Redirect back to schedule tab with success message.
	wp_safe_redirect(
		add_query_arg(
			array(
				'page'     => 'easy-actions-scheduler-cleaner-ayudawp',
				'tab'      => 'schedule',
				'saved'    => '1',
				'_wpnonce' => wp_create_nonce( 'easc_schedule_saved_nonce' ),
			),
			admin_url( 'tools.php' )
		)
	);
	exit;
}

/**
 * Handle save notifications form submission
 *
 * @since 1.2.0
 */
function easc_handle_save_notifications() {
	// Verified here as well as in easc_handle_actions(), so the security check
	// lives in the same scope as the reads and does not depend on the caller.
	if ( ! isset( $_POST['easc_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['easc_nonce'] ) ), 'easc_nonce_action' ) ) {
		return;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$new_settings = array(
		'report_enabled'       => ! empty( $_POST['easc_report_enabled'] ),
		'report_frequency'     => isset( $_POST['easc_report_frequency'] ) ? sanitize_text_field( wp_unslash( $_POST['easc_report_frequency'] ) ) : 'weekly',
		'report_emails'        => isset( $_POST['easc_report_emails'] ) ? sanitize_text_field( wp_unslash( $_POST['easc_report_emails'] ) ) : '',
		'alert_enabled'        => ! empty( $_POST['easc_alert_enabled'] ),
		'alert_threshold'      => isset( $_POST['easc_alert_threshold'] ) ? absint( wp_unslash( $_POST['easc_alert_threshold'] ) ) : 5000,
		'alert_missed_enabled' => ! empty( $_POST['easc_alert_missed_enabled'] ),
		'alert_emails'         => isset( $_POST['easc_alert_emails'] ) ? sanitize_text_field( wp_unslash( $_POST['easc_alert_emails'] ) ) : '',
	);

	easc_save_notification_settings( $new_settings );

	wp_safe_redirect(
		add_query_arg(
			array(
				'page'     => 'easy-actions-scheduler-cleaner-ayudawp',
				'tab'      => 'notifications',
				'saved'    => '1',
				'_wpnonce' => wp_create_nonce( 'easc_notifications_saved_nonce' ),
			),
			admin_url( 'tools.php' )
		)
	);
	exit;
}

/**
 * Handle test email requests (GET-based)
 *
 * Uses GET links with nonce to avoid nested form issues.
 *
 * @since 1.2.0
 */
function easc_handle_test_email() {
	if ( ! isset( $_GET['easc_test'] ) || ! isset( $_GET['_wpnonce'] ) ) {
		return;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$test_type = sanitize_text_field( wp_unslash( $_GET['easc_test'] ) );

	if ( ! in_array( $test_type, array( 'report', 'alert' ), true ) ) {
		return;
	}

	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'easc_test_' . $test_type ) ) {
		wp_die( esc_html__( 'Security check failed.', 'easy-actions-scheduler-cleaner-ayudawp' ) );
	}

	$success = false;

	if ( 'report' === $test_type ) {
		$success = easc_send_test_report();
	} elseif ( 'alert' === $test_type ) {
		$success = easc_send_test_alert();
	}

	wp_safe_redirect(
		add_query_arg(
			array(
				'page'      => 'easy-actions-scheduler-cleaner-ayudawp',
				'tab'       => 'notifications',
				'test_sent' => $success ? $test_type : 'error',
				'_wpnonce'  => wp_create_nonce( 'easc_test_sent_nonce' ),
			),
			admin_url( 'tools.php' )
		)
	);
	exit;
}

/**
 * Get translatable status label
 *
 * @since 1.0.0
 * @param string $status Action status.
 * @return string Translatable status label.
 */
function easc_get_status_label( $status ) {
	$status_labels = array(
		'complete'    => __( 'Completed Actions', 'easy-actions-scheduler-cleaner-ayudawp' ),
		'failed'      => __( 'Failed Actions', 'easy-actions-scheduler-cleaner-ayudawp' ),
		'canceled'    => __( 'Canceled Actions', 'easy-actions-scheduler-cleaner-ayudawp' ),
		'cancelled'   => __( 'Canceled Actions', 'easy-actions-scheduler-cleaner-ayudawp' ),
		'past-due'    => __( 'Past-Due Actions', 'easy-actions-scheduler-cleaner-ayudawp' ),
		'pending'     => __( 'Pending Actions', 'easy-actions-scheduler-cleaner-ayudawp' ),
		'in-progress' => __( 'In-Progress Actions', 'easy-actions-scheduler-cleaner-ayudawp' ),
		'running'     => __( 'Running Actions', 'easy-actions-scheduler-cleaner-ayudawp' ),
	);

	return isset( $status_labels[ $status ] ) ? $status_labels[ $status ] : ucfirst( $status ) . ' Actions';
}

/**
 * Get type labels for schedule settings
 *
 * @since 1.1.0
 * @return array
 */
function easc_get_schedule_type_labels() {
	return array(
		'complete'    => array(
			'label' => __( 'Completed actions', 'easy-actions-scheduler-cleaner-ayudawp' ),
			'desc'  => __( 'Actions already executed successfully', 'easy-actions-scheduler-cleaner-ayudawp' ),
		),
		'failed'      => array(
			'label' => __( 'Failed actions', 'easy-actions-scheduler-cleaner-ayudawp' ),
			'desc'  => __( 'Actions that encountered errors', 'easy-actions-scheduler-cleaner-ayudawp' ),
		),
		'canceled'    => array(
			'label' => __( 'Canceled actions', 'easy-actions-scheduler-cleaner-ayudawp' ),
			'desc'  => __( 'Actions that were manually canceled', 'easy-actions-scheduler-cleaner-ayudawp' ),
		),
		'past-due'    => array(
			'label' => __( 'Past-due actions', 'easy-actions-scheduler-cleaner-ayudawp' ),
			'desc'  => __( 'Actions that missed their execution time', 'easy-actions-scheduler-cleaner-ayudawp' ),
		),
		'pending_old' => array(
			'label' => __( 'Old pending actions', 'easy-actions-scheduler-cleaner-ayudawp' ),
			'desc'  => __( 'Pending actions older than the configured number of days', 'easy-actions-scheduler-cleaner-ayudawp' ),
		),
		'logs'        => array(
			'label' => __( 'Action logs', 'easy-actions-scheduler-cleaner-ayudawp' ),
			'desc'  => __( 'Historical log records that accumulate over time', 'easy-actions-scheduler-cleaner-ayudawp' ),
		),
	);
}

/**
 * Admin page content
 *
 * @since 1.0.1
 */
function easc_admin_page() {
	echo '<div class="wrap">';
	echo '<h1>' . esc_html__( 'Easy Actions Scheduler Cleaner', 'easy-actions-scheduler-cleaner-ayudawp' ) . '</h1>';

	// Check if we are showing results page.
	if ( isset( $_GET['results'] ) && '1' === $_GET['results'] ) {
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'easc_results_nonce' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'easy-actions-scheduler-cleaner-ayudawp' ) );
		}
		easc_render_results_page();
	} else {
		// Render tab navigation.
		easc_render_tabs();
	}

	// Render promo banner on all pages.
	$promo_banner = new Easc_Promo_Banner( 'easc' );
	$promo_banner->render();

	echo '</div>';
}

/**
 * Render tab navigation and content
 *
 * @since 1.1.0
 */
function easc_render_tabs() {
	$tabs = array(
		'cleanup'       => __( 'Manual cleanup', 'easy-actions-scheduler-cleaner-ayudawp' ),
		'schedule'      => __( 'Scheduled cleanup', 'easy-actions-scheduler-cleaner-ayudawp' ),
		'notifications' => __( 'Notifications', 'easy-actions-scheduler-cleaner-ayudawp' ),
	);

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Tab parameter is display-only, no data modification.
	$requested_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'cleanup';
	$current_tab   = array_key_exists( $requested_tab, $tabs ) ? $requested_tab : 'cleanup';

	echo '<nav class="nav-tab-wrapper easc-nav-tabs">';
	foreach ( $tabs as $tab_key => $tab_label ) {
		$active_class = ( $current_tab === $tab_key ) ? ' nav-tab-active' : '';
		$url          = add_query_arg(
			array(
				'page' => 'easy-actions-scheduler-cleaner-ayudawp',
				'tab'  => $tab_key,
			),
			admin_url( 'tools.php' )
		);
		echo '<a href="' . esc_url( $url ) . '" class="nav-tab' . esc_attr( $active_class ) . '">' . esc_html( $tab_label ) . '</a>';
	}
	echo '</nav>';

	echo '<div class="easc-tab-content">';

	switch ( $current_tab ) {
		case 'schedule':
			easc_render_schedule_page();
			break;
		case 'notifications':
			easc_render_notifications_page();
			break;
		default:
			easc_render_warning_page();
			break;
	}

	echo '</div>';
}

/**
 * Render warning page (manual cleanup tab) — softer tone
 *
 * @since 1.0.0
 */
function easc_render_warning_page() {
	$schedule_url = add_query_arg(
		array(
			'page' => 'easy-actions-scheduler-cleaner-ayudawp',
			'tab'  => 'schedule',
		),
		admin_url( 'tools.php' )
	);
	?>
	<div class="easc-info-box">
		<h2><?php esc_html_e( 'Manual cleanup', 'easy-actions-scheduler-cleaner-ayudawp' ); ?></h2>
		<p><?php esc_html_e( 'This will remove old records from your Action Scheduler database tables. The following record types can be safely removed:', 'easy-actions-scheduler-cleaner-ayudawp' ); ?></p>
		<ul class="easc-actions-list">
			<li><?php esc_html_e( 'Completed actions — already executed, only taking up space', 'easy-actions-scheduler-cleaner-ayudawp' ); ?></li>
			<li><?php esc_html_e( 'Failed actions — actions that encountered errors during execution', 'easy-actions-scheduler-cleaner-ayudawp' ); ?></li>
			<li><?php esc_html_e( 'Canceled actions — actions that were manually canceled', 'easy-actions-scheduler-cleaner-ayudawp' ); ?></li>
			<li><?php esc_html_e( 'Past-due actions — actions that missed their scheduled execution time', 'easy-actions-scheduler-cleaner-ayudawp' ); ?></li>
			<li><?php esc_html_e( 'Old pending actions — pending for more than 30 days', 'easy-actions-scheduler-cleaner-ayudawp' ); ?></li>
			<li><?php esc_html_e( 'Action logs — historical log records that accumulate over time', 'easy-actions-scheduler-cleaner-ayudawp' ); ?></li>
		</ul>

		<p class="easc-note">
			<?php esc_html_e( 'Note: This action cannot be undone. We recommend creating a database backup before proceeding.', 'easy-actions-scheduler-cleaner-ayudawp' ); ?>
		</p>

		<div class="easc-button-group easc-button-group-equal">
			<form method="post" style="display: inline;">
				<?php wp_nonce_field( 'easc_nonce_action', 'easc_nonce' ); ?>
				<input type="hidden" name="easc_action" value="execute">
				<input type="submit" class="button easc-execute" value="<?php esc_attr_e( 'Run Manual Cleanup', 'easy-actions-scheduler-cleaner-ayudawp' ); ?>" onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to proceed with the cleanup? This action cannot be undone.', 'easy-actions-scheduler-cleaner-ayudawp' ); ?>');">
			</form>

			<a href="<?php echo esc_url( $schedule_url ); ?>" class="button easc-schedule-btn">
				<?php esc_html_e( 'Schedule Automatic Cleanup', 'easy-actions-scheduler-cleaner-ayudawp' ); ?>
			</a>
		</div>
	</div>

	<?php
	easc_render_database_status();
}

/**
 * Render database status table with space estimation
 *
 * @since 1.0.0
 */
function easc_render_database_status() {
	$counts     = easc_get_database_status();
	$logs_count = easc_get_logs_count();
	$sizes      = easc_get_table_sizes();

	if ( ! $counts && ! $logs_count ) {
		return;
	}

	echo '<h3>' . esc_html__( 'Current database status', 'easy-actions-scheduler-cleaner-ayudawp' ) . '</h3>';
	echo '<table class="easc-results-table">';
	echo '<thead><tr>';
	echo '<th>' . esc_html__( 'Table/Status', 'easy-actions-scheduler-cleaner-ayudawp' ) . '</th>';
	echo '<th>' . esc_html__( 'Records', 'easy-actions-scheduler-cleaner-ayudawp' ) . '</th>';
	echo '</tr></thead><tbody>';

	$total = 0;

	if ( $counts ) {
		foreach ( $counts as $count ) {
			echo '<tr>';
			echo '<td>' . esc_html( easc_get_status_label( $count->status ) ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( $count->count ) ) . '</td>';
			echo '</tr>';
			$total += $count->count;
		}
	}

	if ( $logs_count > 0 ) {
		echo '<tr>';
		echo '<td>' . esc_html__( 'Action Logs', 'easy-actions-scheduler-cleaner-ayudawp' ) . '</td>';
		echo '<td>' . esc_html( number_format_i18n( $logs_count ) ) . '</td>';
		echo '</tr>';
		$total += $logs_count;
	}

	echo '<tr class="easc-row-total">';
	echo '<td>' . esc_html__( 'Total records', 'easy-actions-scheduler-cleaner-ayudawp' ) . '</td>';
	echo '<td>' . esc_html( number_format_i18n( $total ) ) . '</td>';
	echo '</tr>';

	// Space row.
	if ( $sizes['total_size'] > 0 ) {
		echo '<tr class="easc-row-space">';
		echo '<td>' . esc_html__( 'Estimated database space', 'easy-actions-scheduler-cleaner-ayudawp' ) . '</td>';
		echo '<td>' . esc_html( easc_format_size( $sizes['total_size'] ) ) . '</td>';
		echo '</tr>';
	}

	echo '</tbody></table>';
}

/**
 * Render results page (after manual cleanup)
 *
 * @since 1.0.0
 */
function easc_render_results_page() {
	// Verified here as well as in easc_admin_page(), so the security check lives
	// in the same scope as the reads and does not depend on the caller.
	if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'easc_results_nonce' ) ) {
		return;
	}

	// Counts come from the redirect this plugin builds after a manual cleanup.
	$complete    = isset( $_GET['complete'] ) ? absint( wp_unslash( $_GET['complete'] ) ) : 0;
	$failed      = isset( $_GET['failed'] ) ? absint( wp_unslash( $_GET['failed'] ) ) : 0;
	$canceled    = isset( $_GET['canceled'] ) ? absint( wp_unslash( $_GET['canceled'] ) ) : 0;
	$past_due    = isset( $_GET['past_due'] ) ? absint( wp_unslash( $_GET['past_due'] ) ) : 0;
	$old_pending = isset( $_GET['old_pending'] ) ? absint( wp_unslash( $_GET['old_pending'] ) ) : 0;
	$logs        = isset( $_GET['logs'] ) ? absint( wp_unslash( $_GET['logs'] ) ) : 0;
	$space_freed = isset( $_GET['space_freed'] ) ? floatval( wp_unslash( $_GET['space_freed'] ) ) : 0;

	$total_actions = $complete + $failed + $canceled + $past_due + $old_pending;
	$total_all     = $total_actions + $logs;

	$schedule_url = add_query_arg(
		array(
			'page' => 'easy-actions-scheduler-cleaner-ayudawp',
			'tab'  => 'schedule',
		),
		admin_url( 'tools.php' )
	);

	$plugins_url = admin_url();
	?>
	<div class="easc-success-box">
		<h2><?php esc_html_e( 'Cleanup completed successfully', 'easy-actions-scheduler-cleaner-ayudawp' ); ?></h2>
		<p><?php esc_html_e( 'The following records have been removed from the database:', 'easy-actions-scheduler-cleaner-ayudawp' ); ?></p>

		<table class="easc-results-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Record type', 'easy-actions-scheduler-cleaner-ayudawp' ); ?></th>
					<th><?php esc_html_e( 'Deleted', 'easy-actions-scheduler-cleaner-ayudawp' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td><?php esc_html_e( 'Completed Actions', 'easy-actions-scheduler-cleaner-ayudawp' ); ?></td>
					<td><?php echo esc_html( number_format_i18n( $complete ) ); ?></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Failed Actions', 'easy-actions-scheduler-cleaner-ayudawp' ); ?></td>
					<td><?php echo esc_html( number_format_i18n( $failed ) ); ?></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Canceled Actions', 'easy-actions-scheduler-cleaner-ayudawp' ); ?></td>
					<td><?php echo esc_html( number_format_i18n( $canceled ) ); ?></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Past-Due Actions', 'easy-actions-scheduler-cleaner-ayudawp' ); ?></td>
					<td><?php echo esc_html( number_format_i18n( $past_due ) ); ?></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Old Pending Actions (30+ days)', 'easy-actions-scheduler-cleaner-ayudawp' ); ?></td>
					<td><?php echo esc_html( number_format_i18n( $old_pending ) ); ?></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Action Logs', 'easy-actions-scheduler-cleaner-ayudawp' ); ?></td>
					<td><?php echo esc_html( number_format_i18n( $logs ) ); ?></td>
				</tr>
				<tr class="easc-row-total-success">
					<td><?php esc_html_e( 'Total records deleted', 'easy-actions-scheduler-cleaner-ayudawp' ); ?></td>
					<td><?php echo esc_html( number_format_i18n( $total_all ) ); ?></td>
				</tr>
				<?php if ( $space_freed > 0 ) : ?>
				<tr class="easc-row-space-saved">
					<td><?php esc_html_e( 'Estimated space freed', 'easy-actions-scheduler-cleaner-ayudawp' ); ?></td>
					<td><?php echo esc_html( easc_format_size( $space_freed ) ); ?></td>
				</tr>
				<?php endif; ?>
			</tbody>
		</table>

		<div class="easc-post-cleanup-info">
			<p><?php esc_html_e( 'What would you like to do next?', 'easy-actions-scheduler-cleaner-ayudawp' ); ?></p>
		</div>

		<div class="easc-button-group easc-button-group-equal">
			<a href="<?php echo esc_url( $schedule_url ); ?>" class="button easc-schedule-btn">
				<?php esc_html_e( 'Schedule Automatic Cleanup', 'easy-actions-scheduler-cleaner-ayudawp' ); ?>
			</a>
			<a href="<?php echo esc_url( $plugins_url ); ?>" class="button easc-neutral-btn">
				<?php esc_html_e( 'Go to Dashboard', 'easy-actions-scheduler-cleaner-ayudawp' ); ?>
			</a>
		</div>
	</div>
	<?php
}

/**
 * Render schedule page (scheduled cleanup tab)
 *
 * @since 1.1.0
 */
function easc_render_schedule_page() {
	$settings    = easc_get_schedule_settings();
	$type_labels = easc_get_schedule_type_labels();

	// Show saved notice. The nonce travels in the redirect this plugin builds
	// after saving, so it is verified before any other query arg is read.
	if ( isset( $_GET['_wpnonce'] )
		&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'easc_schedule_saved_nonce' )
		&& isset( $_GET['saved'] )
		&& '1' === sanitize_text_field( wp_unslash( $_GET['saved'] ) )
	) {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Schedule settings saved successfully.', 'easy-actions-scheduler-cleaner-ayudawp' ) . '</p></div>';
	}

	// Show last run info.
	$last_run = get_transient( 'easc_last_scheduled_run' );
	if ( $last_run ) {
		easc_render_last_run_info( $last_run );
	}
	?>
	<div class="easc-schedule-box">
		<h2><?php esc_html_e( 'Scheduled cleanup settings', 'easy-actions-scheduler-cleaner-ayudawp' ); ?></h2>
		<p><?php esc_html_e( 'Configure automatic cleanup to run periodically. Each action type will only be cleaned when its record count exceeds the threshold you set.', 'easy-actions-scheduler-cleaner-ayudawp' ); ?></p>

		<form method="post">
			<?php wp_nonce_field( 'easc_nonce_action', 'easc_nonce' ); ?>
			<input type="hidden" name="easc_action" value="save_schedule">

			<!-- Master toggle -->
			<div class="easc-schedule-master">
				<label class="easc-toggle-label">
					<input type="checkbox" name="easc_schedule_enabled" value="1" <?php checked( $settings['enabled'] ); ?> id="easc-schedule-toggle">
					<span><?php esc_html_e( 'Enable scheduled cleanup', 'easy-actions-scheduler-cleaner-ayudawp' ); ?></span>
				</label>
				<?php
				// Show next run info if enabled.
				$next_run = wp_next_scheduled( 'easc_scheduled_cleanup' );
				if ( $next_run && $settings['enabled'] ) {
					$next_run_local = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $next_run ), get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
					echo '<p class="easc-next-run">';
					/* translators: %s: Date and time of next scheduled run */
					echo esc_html( sprintf( __( 'Next scheduled run: %s', 'easy-actions-scheduler-cleaner-ayudawp' ), $next_run_local ) );
					echo '</p>';
				}
				?>
			</div>

			<!-- Schedule options (shown/hidden by JS) -->
			<div class="easc-schedule-options" id="easc-schedule-options">
				<!-- Frequency selector -->
				<div class="easc-field-row">
					<label for="easc-frequency"><strong><?php esc_html_e( 'Frequency', 'easy-actions-scheduler-cleaner-ayudawp' ); ?></strong></label>
					<select name="easc_frequency" id="easc-frequency">
						<?php foreach ( easc_get_frequency_options() as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $settings['frequency'], $key ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>

				<!-- Type settings -->
				<h3><?php esc_html_e( 'Action types to clean', 'easy-actions-scheduler-cleaner-ayudawp' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Enable each type and set the minimum number of records required to trigger cleanup.', 'easy-actions-scheduler-cleaner-ayudawp' ); ?></p>

				<table class="easc-schedule-table">
					<thead>
						<tr>
							<th class="easc-col-toggle"><?php esc_html_e( 'Clean', 'easy-actions-scheduler-cleaner-ayudawp' ); ?></th>
							<th class="easc-col-type"><?php esc_html_e( 'Action type', 'easy-actions-scheduler-cleaner-ayudawp' ); ?></th>
							<th class="easc-col-threshold"><?php esc_html_e( 'Minimum records', 'easy-actions-scheduler-cleaner-ayudawp' ); ?></th>
							<th class="easc-col-current"><?php esc_html_e( 'Current count', 'easy-actions-scheduler-cleaner-ayudawp' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $type_labels as $type_key => $type_info ) : ?>
							<?php
							$safe_key      = str_replace( '-', '_', $type_key );
							$type_settings = isset( $settings['types'][ $type_key ] ) ? $settings['types'][ $type_key ] : array();
							$is_enabled    = ! empty( $type_settings['enabled'] );
							$threshold     = isset( $type_settings['threshold'] ) ? (int) $type_settings['threshold'] : 100;

							// Get current count for this type.
							if ( 'pending_old' === $type_key ) {
								$days          = isset( $type_settings['days'] ) ? (int) $type_settings['days'] : 30;
								$current_count = easc_get_old_pending_count( $days );
							} elseif ( 'logs' === $type_key ) {
								$current_count = easc_get_logs_count();
							} else {
								$current_count = easc_get_count_by_status( $type_key );
							}

							$will_clean = $is_enabled && $current_count >= $threshold;
							?>
							<tr class="<?php echo $will_clean ? 'easc-will-clean' : ''; ?>">
								<td class="easc-col-toggle">
									<input type="checkbox" name="<?php echo esc_attr( 'easc_type_' . $safe_key . '_enabled' ); ?>" value="1" <?php checked( $is_enabled ); ?>>
								</td>
								<td class="easc-col-type">
									<?php echo esc_html( $type_info['label'] ); ?>
									<span class="easc-type-desc"><?php echo esc_html( $type_info['desc'] ); ?></span>
									<?php if ( 'pending_old' === $type_key ) : ?>
										<span class="easc-days-field">
											<label>
												<?php esc_html_e( 'Older than', 'easy-actions-scheduler-cleaner-ayudawp' ); ?>
												<input type="number" name="easc_type_pending_old_days" value="<?php echo esc_attr( $days ); ?>" min="7" max="365" class="small-text">
												<?php esc_html_e( 'days', 'easy-actions-scheduler-cleaner-ayudawp' ); ?>
											</label>
										</span>
									<?php endif; ?>
								</td>
								<td class="easc-col-threshold">
									<input type="number" name="<?php echo esc_attr( 'easc_type_' . $safe_key . '_threshold' ); ?>" value="<?php echo esc_attr( $threshold ); ?>" min="1" class="small-text">
								</td>
								<td class="easc-col-current">
									<?php echo esc_html( number_format_i18n( $current_count ) ); ?>
									<?php if ( $will_clean ) : ?>
										<span class="easc-badge easc-badge-ready" title="<?php esc_attr_e( 'Will be cleaned on next run', 'easy-actions-scheduler-cleaner-ayudawp' ); ?>">&#10003;</span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<div class="easc-button-group">
				<input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Save Settings', 'easy-actions-scheduler-cleaner-ayudawp' ); ?>">
			</div>
		</form>
	</div>

	<?php
	// Show database status below.
	easc_render_database_status();
}

/**
 * Render notifications page
 *
 * @since 1.2.0
 */
function easc_render_notifications_page() {
	$settings   = easc_get_notification_settings();
	$admin_email = get_option( 'admin_email' );

	// Show saved notice. The nonce travels in the redirect this plugin builds
	// after saving, so it is verified before any other query arg is read.
	if ( isset( $_GET['_wpnonce'] )
		&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'easc_notifications_saved_nonce' )
		&& isset( $_GET['saved'] )
		&& '1' === sanitize_text_field( wp_unslash( $_GET['saved'] ) )
	) {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Notification settings saved successfully.', 'easy-actions-scheduler-cleaner-ayudawp' ) . '</p></div>';
	}

	// Show test email result notice.
	if ( isset( $_GET['_wpnonce'] )
		&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'easc_test_sent_nonce' )
		&& isset( $_GET['test_sent'] )
	) {
		$test_result = sanitize_text_field( wp_unslash( $_GET['test_sent'] ) );
		if ( 'error' === $test_result ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Test email could not be sent. Please check your server mail configuration.', 'easy-actions-scheduler-cleaner-ayudawp' ) . '</p></div>';
		} else {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Test email sent successfully. Check your inbox.', 'easy-actions-scheduler-cleaner-ayudawp' ) . '</p></div>';
		}
	}
	?>
	<div class="easc-schedule-box">
		<h2><?php esc_html_e( 'Email notification settings', 'easy-actions-scheduler-cleaner-ayudawp' ); ?></h2>
		<p><?php esc_html_e( 'Configure email notifications to stay informed about your database status and cleanup activity.', 'easy-actions-scheduler-cleaner-ayudawp' ); ?></p>

		<form method="post">
			<?php wp_nonce_field( 'easc_nonce_action', 'easc_nonce' ); ?>
			<input type="hidden" name="easc_action" value="save_notifications">

			<!-- Report emails section -->
			<div class="easc-notification-section">
				<h3><?php esc_html_e( 'Reports', 'easy-actions-scheduler-cleaner-ayudawp' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Receive regular reports with database status, cleanup results, and estimated space savings.', 'easy-actions-scheduler-cleaner-ayudawp' ); ?></p>

				<div class="easc-schedule-master">
					<label class="easc-toggle-label">
						<input type="checkbox" name="easc_report_enabled" value="1" <?php checked( $settings['report_enabled'] ); ?> id="easc-report-toggle">
						<span><?php esc_html_e( 'Send scheduled status reports by email', 'easy-actions-scheduler-cleaner-ayudawp' ); ?></span>
					</label>
					<?php
					$next_report = wp_next_scheduled( 'easc_send_report_email' );
					if ( $next_report && $settings['report_enabled'] ) {
						$next_report_local = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $next_report ), get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
						echo '<p class="easc-next-run">';
						/* translators: %s: Date and time of next report */
						echo esc_html( sprintf( __( 'Next report: %s', 'easy-actions-scheduler-cleaner-ayudawp' ), $next_report_local ) );
						echo '</p>';
					}
					?>
				</div>

				<div class="easc-notification-options" id="easc-report-options">
					<div class="easc-field-row">
						<label for="easc-report-frequency"><strong><?php esc_html_e( 'Report frequency', 'easy-actions-scheduler-cleaner-ayudawp' ); ?></strong></label>
						<select name="easc_report_frequency" id="easc-report-frequency">
							<option value="daily" <?php selected( $settings['report_frequency'], 'daily' ); ?>><?php esc_html_e( 'Daily', 'easy-actions-scheduler-cleaner-ayudawp' ); ?></option>
							<option value="weekly" <?php selected( $settings['report_frequency'], 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'easy-actions-scheduler-cleaner-ayudawp' ); ?></option>
							<option value="monthly" <?php selected( $settings['report_frequency'], 'monthly' ); ?>><?php esc_html_e( 'Monthly', 'easy-actions-scheduler-cleaner-ayudawp' ); ?></option>
						</select>
					</div>

					<div class="easc-field-row">
						<label for="easc-report-emails"><strong><?php esc_html_e( 'Report recipients', 'easy-actions-scheduler-cleaner-ayudawp' ); ?></strong></label>
						<input type="text" name="easc_report_emails" id="easc-report-emails" value="<?php echo esc_attr( $settings['report_emails'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( $admin_email ); ?>">
						<p class="description">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: Admin email address */
									__( 'Comma-separated email addresses for management reports. Leave empty to use the site admin email (%s). If you enter custom addresses, the admin email will not receive reports unless you include it explicitly.', 'easy-actions-scheduler-cleaner-ayudawp' ),
									$admin_email
								)
							);
							?>
						</p>
					</div>

					<p class="easc-test-link-wrap">
						<?php
						$test_report_url = wp_nonce_url(
							add_query_arg(
								array(
									'page'      => 'easy-actions-scheduler-cleaner-ayudawp',
									'tab'       => 'notifications',
									'easc_test' => 'report',
								),
								admin_url( 'tools.php' )
							),
							'easc_test_report'
						);
						?>
						<a href="<?php echo esc_url( $test_report_url ); ?>" class="easc-test-link"><?php esc_html_e( 'Send test report', 'easy-actions-scheduler-cleaner-ayudawp' ); ?> &rarr;</a>
					</p>
				</div>
			</div>

			<!-- Alert emails section -->
			<div class="easc-notification-section">
				<h3><?php esc_html_e( 'Technical alerts', 'easy-actions-scheduler-cleaner-ayudawp' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Receive alerts when something needs your attention, such as record accumulation or missed cleanups.', 'easy-actions-scheduler-cleaner-ayudawp' ); ?></p>

				<div class="easc-schedule-master">
					<label class="easc-toggle-label">
						<input type="checkbox" name="easc_alert_enabled" value="1" <?php checked( $settings['alert_enabled'] ); ?> id="easc-alert-toggle">
						<span><?php esc_html_e( 'Alert when records accumulate without scheduled cleanup', 'easy-actions-scheduler-cleaner-ayudawp' ); ?></span>
					</label>
				</div>

				<div class="easc-notification-options" id="easc-alert-options">
					<div class="easc-field-row">
						<label for="easc-alert-threshold"><strong><?php esc_html_e( 'Accumulation threshold', 'easy-actions-scheduler-cleaner-ayudawp' ); ?></strong></label>
						<input type="number" name="easc_alert_threshold" id="easc-alert-threshold" value="<?php echo esc_attr( $settings['alert_threshold'] ); ?>" min="100" class="small-text">
						<span class="description"><?php esc_html_e( 'total records', 'easy-actions-scheduler-cleaner-ayudawp' ); ?></span>
						<p class="description"><?php esc_html_e( 'An alert will be sent when total records exceed this number and no scheduled cleanup is active. Sent at most once per week.', 'easy-actions-scheduler-cleaner-ayudawp' ); ?></p>
					</div>
				</div>

				<div class="easc-schedule-master">
					<label class="easc-toggle-label">
						<input type="checkbox" name="easc_alert_missed_enabled" value="1" <?php checked( $settings['alert_missed_enabled'] ); ?> id="easc-alert-missed-toggle">
						<span><?php esc_html_e( 'Alert when a scheduled cleanup fails to run', 'easy-actions-scheduler-cleaner-ayudawp' ); ?></span>
					</label>
					<p class="description" style="margin-top: 5px; margin-left: 26px;"><?php esc_html_e( 'Detects when WP-Cron fails to execute a scheduled cleanup. Useful for sites with DISABLE_WP_CRON or unreliable hosting.', 'easy-actions-scheduler-cleaner-ayudawp' ); ?></p>
				</div>

				<div class="easc-field-row" style="margin-top: 15px;">
					<label for="easc-alert-emails"><strong><?php esc_html_e( 'Alert recipients', 'easy-actions-scheduler-cleaner-ayudawp' ); ?></strong></label>
					<input type="text" name="easc_alert_emails" id="easc-alert-emails" value="<?php echo esc_attr( $settings['alert_emails'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( $admin_email ); ?>">
					<p class="description">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: Admin email address */
								__( 'Comma-separated email addresses for technical alerts. Leave empty to use the site admin email (%s). If you enter custom addresses, the admin email will not receive alerts unless you include it explicitly.', 'easy-actions-scheduler-cleaner-ayudawp' ),
								$admin_email
							)
						);
						?>
					</p>
				</div>

				<p class="easc-test-link-wrap">
					<?php
					$test_alert_url = wp_nonce_url(
						add_query_arg(
							array(
								'page'      => 'easy-actions-scheduler-cleaner-ayudawp',
								'tab'       => 'notifications',
								'easc_test' => 'alert',
							),
							admin_url( 'tools.php' )
						),
						'easc_test_alert'
					);
					?>
					<a href="<?php echo esc_url( $test_alert_url ); ?>" class="easc-test-link"><?php esc_html_e( 'Send test alert', 'easy-actions-scheduler-cleaner-ayudawp' ); ?> &rarr;</a>
				</p>
			</div>

			<div class="easc-button-group">
				<input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Save Settings', 'easy-actions-scheduler-cleaner-ayudawp' ); ?>">
			</div>
		</form>
	</div>
	<?php
}

/**
 * Render last scheduled run info
 *
 * @since 1.1.0
 * @param array $last_run Last run data from transient.
 */
function easc_render_last_run_info( $last_run ) {
	$status = isset( $last_run['status'] ) ? $last_run['status'] : '';
	$time   = isset( $last_run['time'] ) ? $last_run['time'] : '';

	if ( empty( $status ) || empty( $time ) ) {
		return;
	}

	// Error state.
	if ( 'error' === $status ) {
		$error_msg = isset( $last_run['error'] ) ? $last_run['error'] : __( 'Unknown error', 'easy-actions-scheduler-cleaner-ayudawp' );
		echo '<div class="easc-last-run-info easc-last-run-error">';
		echo '<strong>' . esc_html__( 'Last scheduled run:', 'easy-actions-scheduler-cleaner-ayudawp' ) . '</strong> ';
		echo esc_html( $time ) . '<br>';
		echo esc_html( $error_msg );
		echo '</div>';
		return;
	}

	// Skipped state (no thresholds met).
	if ( 'skipped' === $status ) {
		echo '<div class="easc-last-run-info easc-last-run-skipped">';
		echo '<strong>' . esc_html__( 'Last scheduled run:', 'easy-actions-scheduler-cleaner-ayudawp' ) . '</strong> ';
		echo esc_html( $time ) . '<br>';
		echo esc_html__( 'No cleanup was needed. All record counts were below their configured thresholds.', 'easy-actions-scheduler-cleaner-ayudawp' );
		echo '</div>';
		return;
	}

	// Success state.
	$results     = isset( $last_run['results'] ) ? $last_run['results'] : array();
	$type_labels = easc_get_schedule_type_labels();

	$total = 0;
	foreach ( $results as $count ) {
		$total += (int) $count;
	}

	echo '<div class="easc-last-run-info easc-last-run-success">';
	echo '<strong>' . esc_html__( 'Last scheduled run:', 'easy-actions-scheduler-cleaner-ayudawp' ) . '</strong> ';
	echo esc_html( $time ) . ' &mdash; ';
	/* translators: %s: Number of records deleted */
	echo esc_html( sprintf( __( '%s records deleted', 'easy-actions-scheduler-cleaner-ayudawp' ), number_format_i18n( $total ) ) );

	// Show breakdown.
	if ( count( $results ) > 1 ) {
		echo '<span class="easc-last-run-details">(';
		$parts = array();
		foreach ( $results as $type_key => $deleted_count ) {
			$label   = isset( $type_labels[ $type_key ]['label'] ) ? $type_labels[ $type_key ]['label'] : $type_key;
			$parts[] = esc_html( $label ) . ': ' . esc_html( number_format_i18n( $deleted_count ) );
		}
		echo wp_kses_post( implode( ' | ', $parts ) );
		echo ')</span>';
	}

	echo '</div>';
}

/**
 * Add settings links to plugin action links
 *
 * @since 1.0.1
 * @param array $links Plugin action links.
 * @return array Modified plugin action links.
 */
function easc_add_settings_link( $links ) {
	$cleanup_link = '<a href="' . esc_url( admin_url( 'tools.php?page=easy-actions-scheduler-cleaner-ayudawp' ) ) . '">' . esc_html__( 'Run Cleanup', 'easy-actions-scheduler-cleaner-ayudawp' ) . '</a>';
	$schedule_link = '<a href="' . esc_url( admin_url( 'tools.php?page=easy-actions-scheduler-cleaner-ayudawp&tab=schedule' ) ) . '">' . esc_html__( 'Schedule Cleanup', 'easy-actions-scheduler-cleaner-ayudawp' ) . '</a>';
	array_unshift( $links, $schedule_link );
	array_unshift( $links, $cleanup_link );
	return $links;
}