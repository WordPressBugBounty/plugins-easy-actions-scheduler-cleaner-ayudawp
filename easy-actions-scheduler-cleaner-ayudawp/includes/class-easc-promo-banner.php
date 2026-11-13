<?php
/**
 * Easc Promo Banner
 *
 * Promotional banner with random AyudaWP service recommendations.
 *
 * @package EasyActionsSchedulerCleanerAyudaWP
 * @since 1.1.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Easc Promo Banner class
 *
 * Unique class name to avoid conflicts with other AyudaWP plugins.
 */
class Easc_Promo_Banner {

	/**
	 * CSS class prefix.
	 *
	 * @var string
	 */
	private $css_prefix;

	/**
	 * Constructor.
	 *
	 * @param string $css_prefix CSS class prefix.
	 */
	public function __construct( $css_prefix ) {
		$this->css_prefix = $css_prefix;
	}

	/**
	 * Get services catalog.
	 *
	 * @return array
	 */
	private function get_services_catalog() {
		return array(
			'maintenance' => array(
				'icon'        => 'dashicons-admin-tools',
				'title'       => __( 'Need help with your website?', 'easy-actions-scheduler-cleaner-ayudawp' ),
				'description' => __( 'Professional WordPress maintenance: security monitoring, regular backups, performance optimization, and priority support.', 'easy-actions-scheduler-cleaner-ayudawp' ),
				'button'      => __( 'Learn more', 'easy-actions-scheduler-cleaner-ayudawp' ),
				/* translators: Maintenance service URL. Change this URL in translations to use a localized landing page. */
				'url'         => __( 'https://mantenimiento.ayudawp.com/en/', 'easy-actions-scheduler-cleaner-ayudawp' ),
			),
			'consultancy' => array(
				'icon'        => 'dashicons-businessman',
				'title'       => __( 'WordPress consultancy', 'easy-actions-scheduler-cleaner-ayudawp' ),
				'description' => __( 'One-on-one online sessions to solve your WordPress doubts, get expert advice, and make better decisions for your project.', 'easy-actions-scheduler-cleaner-ayudawp' ),
				'button'      => __( 'Book a session', 'easy-actions-scheduler-cleaner-ayudawp' ),
				'url'         => 'https://servicios.ayudawp.com/producto/consultoria-online-wordpress/',
			),
			'hacked'      => array(
				'icon'        => 'dashicons-sos',
				'title'       => __( 'Hacked website?', 'easy-actions-scheduler-cleaner-ayudawp' ),
				'description' => __( 'Fast recovery service for compromised WordPress sites. We clean malware, fix vulnerabilities, and restore your site security.', 'easy-actions-scheduler-cleaner-ayudawp' ),
				'button'      => __( 'Get help now', 'easy-actions-scheduler-cleaner-ayudawp' ),
				'url'         => 'https://servicios.ayudawp.com/producto/wordpress-hackeado/',
			),
			'development' => array(
				'icon'        => 'dashicons-editor-code',
				'title'       => __( 'Custom development', 'easy-actions-scheduler-cleaner-ayudawp' ),
				'description' => __( 'Need a custom plugin, theme modifications, or specific functionality? We build tailored WordPress solutions for your needs.', 'easy-actions-scheduler-cleaner-ayudawp' ),
				'button'      => __( 'Request a quote', 'easy-actions-scheduler-cleaner-ayudawp' ),
				'url'         => 'https://servicios.ayudawp.com/producto/desarrollo-wordpress/',
			),
			'hosting'     => array(
				'icon'        => 'dashicons-cloud-saved',
				'title'       => __( 'Hosting built for WordPress', 'easy-actions-scheduler-cleaner-ayudawp' ),
				'description' => __( 'Google Cloud servers, automatic geo-located daily backups, and 24/7 expert support. Speed, security, and migration tools included.', 'easy-actions-scheduler-cleaner-ayudawp' ),
				'button'      => __( 'Learn more', 'easy-actions-scheduler-cleaner-ayudawp' ),
				/* translators: SiteGround affiliate URL. Change this URL in translations to use a localized landing page. */
				'url'         => __( 'https://stgrnd.co/telladowpbox', 'easy-actions-scheduler-cleaner-ayudawp' ),
			),
		);
	}

	/**
	 * Get random services.
	 *
	 * @param int $count Number of services to return.
	 * @return array
	 */
	private function get_random_services( $count = 3 ) {
		$services = $this->get_services_catalog();

		$random_keys = array_rand( $services, min( $count, count( $services ) ) );

		if ( ! is_array( $random_keys ) ) {
			$random_keys = array( $random_keys );
		}

		$result = array();
		foreach ( $random_keys as $key ) {
			$result[ $key ] = $services[ $key ];
		}

		return $result;
	}

	/**
	 * Render the promotional banner (horizontal layout).
	 */
	public function render() {
		$services = $this->get_random_services( 3 );
		$prefix   = $this->css_prefix;
		?>
		<!-- Promotional notice -->
		<div class="<?php echo esc_attr( $prefix ); ?>-promo-notice">
			<div class="<?php echo esc_attr( $prefix ); ?>-promo-columns">

				<?php foreach ( $services as $service ) : ?>
				<div class="<?php echo esc_attr( $prefix ); ?>-promo-column">
					<span class="dashicons <?php echo esc_attr( $service['icon'] ); ?>"></span>
					<h5><?php echo esc_html( $service['title'] ); ?></h5>
					<p><?php echo esc_html( $service['description'] ); ?></p>
					<a href="<?php echo esc_url( $service['url'] ); ?>" target="_blank" rel="noopener noreferrer" class="button button-primary">
						<?php echo esc_html( $service['button'] ); ?>
					</a>
				</div>
				<?php endforeach; ?>

			</div>
		</div>
		<?php
	}
}
