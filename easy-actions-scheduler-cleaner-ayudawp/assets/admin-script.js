/**
 * Admin scripts for Easy Actions Scheduler Cleaner
 *
 * Handles toggle visibility for schedule and notification options.
 *
 * @package EasyActionsSchedulerCleanerAyudaWP
 * @since 1.2.0
 */

( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {

		/**
		 * Generic toggle handler: show/hide a target element based on checkbox state.
		 *
		 * @param {string} toggleId  ID of the checkbox.
		 * @param {string} targetId  ID of the element to show/hide.
		 */
		function setupToggle( toggleId, targetId ) {
			var toggle = document.getElementById( toggleId );
			var target = document.getElementById( targetId );

			if ( ! toggle || ! target ) {
				return;
			}

			function update() {
				if ( toggle.checked ) {
					target.classList.remove( 'easc-disabled' );
				} else {
					target.classList.add( 'easc-disabled' );
				}
			}

			update();
			toggle.addEventListener( 'change', update );
		}

		// Schedule tab toggle.
		setupToggle( 'easc-schedule-toggle', 'easc-schedule-options' );

		// Notification tab toggles.
		setupToggle( 'easc-report-toggle', 'easc-report-options' );
		setupToggle( 'easc-alert-toggle', 'easc-alert-options' );
	} );
} )();
