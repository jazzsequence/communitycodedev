<?php
/**
 * Plugin Name: Login Protection
 * Description: Rate-limits failed login attempts and blocks IPs after 5 failures. Provides an admin page to view and manage blocked IPs.
 * Author: Chris Reynolds
 * License: MIT
 */

namespace CommunityCode\LoginProtection;

const MAX_ATTEMPTS     = 5;
const BLOCKED_OPTION   = 'cc_login_protection_blocked';
const TRANSIENT_PREFIX = 'cc_lp_attempts_';
const LOCKOUT_WINDOW   = 3600; // 1 hour rolling window for attempt counting.
const ADMIN_SLUG       = 'cc-login-protection';
const NONCE_UNBLOCK    = 'cc_lp_unblock';
const ACTION_UNBLOCK   = 'cc_lp_unblock_ip';

add_action( 'login_init', __NAMESPACE__ . '\\maybe_block_login', 1 );
add_action( 'wp_login_failed', __NAMESPACE__ . '\\record_failed_attempt' );
add_action( 'admin_menu', __NAMESPACE__ . '\\register_admin_page' );
add_action( 'admin_post_' . ACTION_UNBLOCK, __NAMESPACE__ . '\\handle_unblock_ip' );
add_action( 'admin_notices', __NAMESPACE__ . '\\render_admin_notices' );

/**
 * Get the real client IP from Pantheon/Cloudflare forwarded headers.
 */
function get_client_ip(): string {
	$forwarded = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '' ) );
	if ( $forwarded ) {
		return trim( explode( ',', $forwarded )[0] );
	}
	return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
}

/**
 * Transient key for an IP's attempt log.
 */
function attempt_key( string $ip ): string {
	return TRANSIENT_PREFIX . md5( $ip );
}

/**
 * If the requesting IP is blocked, return 403 immediately.
 */
function maybe_block_login(): void {
	$ip      = get_client_ip();
	$blocked = get_option( BLOCKED_OPTION, [] );

	if ( ! array_key_exists( $ip, $blocked ) ) {
		return;
	}

	wp_die(
		esc_html__( 'Access denied. Your IP address has been blocked due to too many failed login attempts.', 'community-code' ),
		esc_html__( '403 Forbidden', 'community-code' ),
		[ 'response' => 403 ]
	);
}

/**
 * Record a failed login attempt. Block the IP if the threshold is reached.
 *
 * @param string $username The username that was attempted.
 */
function record_failed_attempt( string $username ): void {
	$ip  = get_client_ip();
	$key = attempt_key( $ip );

	$attempts = get_transient( $key );
	$attempts = is_array( $attempts ) ? $attempts : [];

	$attempts[] = [
		'time'       => time(),
		'username'   => sanitize_user( $username, true ),
		'user_agent' => sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ),
		'referer'    => esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ?? '' ) ),
	];

	// Prune attempts outside the rolling window.
	$cutoff   = time() - LOCKOUT_WINDOW;
	$attempts = array_values(
		array_filter( $attempts, fn( array $a ): bool => $a['time'] >= $cutoff )
	);

	set_transient( $key, $attempts, LOCKOUT_WINDOW );

	if ( count( $attempts ) >= MAX_ATTEMPTS ) {
		block_ip( $ip, $attempts );
	}
}

/**
 * Add the IP to the persistent blocked list with metadata from the attempts.
 *
 * @param string  $ip       Client IP address.
 * @param array[] $attempts Recorded attempt data.
 */
function block_ip( string $ip, array $attempts ): void {
	$blocked  = get_option( BLOCKED_OPTION, [] );
	$last     = end( $attempts );
	$usernames = array_values( array_unique( array_column( $attempts, 'username' ) ) );

	$blocked[ $ip ] = [
		'ip'            => $ip,
		'blocked_at'    => time(),
		'attempt_count' => count( $attempts ),
		'user_agent'    => $last['user_agent'] ?? '',
		'last_username' => $last['username'] ?? '',
		'all_usernames' => $usernames,
		'referer'       => $last['referer'] ?? '',
	];

	update_option( BLOCKED_OPTION, $blocked, false );
}

/**
 * Register the admin page under Settings.
 */
function register_admin_page(): void {
	add_options_page(
		__( 'Login Protection', 'community-code' ),
		__( 'Login Protection', 'community-code' ),
		'manage_options',
		ADMIN_SLUG,
		__NAMESPACE__ . '\\render_admin_page'
	);
}

/**
 * Show success notice after unblocking an IP.
 */
function render_admin_notices(): void {
	$screen = get_current_screen();
	if ( ! $screen || 'settings_page_' . ADMIN_SLUG !== $screen->id ) {
		return;
	}

	$unblocked = sanitize_text_field( wp_unslash( $_GET['unblocked'] ?? '' ) );
	if ( '1' !== $unblocked ) {
		return;
	}

	$ip = sanitize_text_field( wp_unslash( $_GET['ip'] ?? '' ) );
	?>
	<div class="notice notice-success is-dismissible">
		<p>
			<?php
			printf(
				/* translators: %s: IP address */
				esc_html__( 'IP address %s has been unblocked.', 'community-code' ),
				'<code>' . esc_html( $ip ) . '</code>'
			);
			?>
		</p>
	</div>
	<?php
}

/**
 * Render the blocked IPs admin page.
 */
function render_admin_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'community-code' ) );
	}

	$blocked = get_option( BLOCKED_OPTION, [] );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Login Protection', 'community-code' ); ?></h1>
		<p>
			<?php
			printf(
				/* translators: %d: max failed attempts */
				esc_html__( 'IPs are blocked after %d consecutive failed login attempts within a 1-hour window. Blocks persist until manually removed.', 'community-code' ),
				(int) MAX_ATTEMPTS
			);
			?>
		</p>

		<?php if ( empty( $blocked ) ) : ?>
			<div class="notice notice-info inline">
				<p><?php esc_html_e( 'No IPs are currently blocked.', 'community-code' ); ?></p>
			</div>
		<?php else : ?>
			<p>
				<?php
				printf(
					/* translators: %d: number of blocked IPs */
					esc_html( _n( '%d IP currently blocked.', '%d IPs currently blocked.', count( $blocked ), 'community-code' ) ),
					(int) count( $blocked )
				);
				?>
			</p>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th scope="col" style="width:130px"><?php esc_html_e( 'IP Address', 'community-code' ); ?></th>
						<th scope="col" style="width:160px"><?php esc_html_e( 'Blocked At', 'community-code' ); ?></th>
						<th scope="col" style="width:80px"><?php esc_html_e( 'Attempts', 'community-code' ); ?></th>
						<th scope="col" style="width:150px"><?php esc_html_e( 'Usernames Tried', 'community-code' ); ?></th>
						<th scope="col"><?php esc_html_e( 'User Agent', 'community-code' ); ?></th>
						<th scope="col" style="width:180px"><?php esc_html_e( 'Referer', 'community-code' ); ?></th>
						<th scope="col" style="width:80px"><?php esc_html_e( 'Actions', 'community-code' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $blocked as $ip => $data ) : ?>
						<?php
						$date_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
						$blocked_at  = isset( $data['blocked_at'] ) ? wp_date( $date_format, (int) $data['blocked_at'] ) : '—';
						$usernames   = $data['all_usernames'] ?? [ $data['last_username'] ?? '' ];
						$usernames   = array_filter( $usernames );
						?>
						<tr>
							<td><code><?php echo esc_html( $data['ip'] ?? $ip ); ?></code></td>
							<td><?php echo esc_html( $blocked_at ); ?></td>
							<td><?php echo esc_html( $data['attempt_count'] ?? '—' ); ?></td>
							<td>
								<?php if ( $usernames ) : ?>
									<code><?php echo esc_html( implode( ', ', $usernames ) ); ?></code>
								<?php else : ?>
									—
								<?php endif; ?>
							</td>
							<td>
								<small><?php echo esc_html( $data['user_agent'] ?? '—' ); ?></small>
							</td>
							<td>
								<?php if ( ! empty( $data['referer'] ) ) : ?>
									<small><?php echo esc_html( $data['referer'] ); ?></small>
								<?php else : ?>
									—
								<?php endif; ?>
							</td>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<?php wp_nonce_field( NONCE_UNBLOCK . '_' . $ip, 'cc_lp_nonce' ); ?>
									<input type="hidden" name="action" value="<?php echo esc_attr( ACTION_UNBLOCK ); ?>">
									<input type="hidden" name="ip" value="<?php echo esc_attr( $ip ); ?>">
									<button type="submit" class="button button-small button-link-delete">
										<?php esc_html_e( 'Unblock', 'community-code' ); ?>
									</button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Handle the unblock IP form submission.
 */
function handle_unblock_ip(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Permission denied.', 'community-code' ) );
	}

	$ip    = sanitize_text_field( wp_unslash( $_POST['ip'] ?? '' ) );
	$nonce = sanitize_text_field( wp_unslash( $_POST['cc_lp_nonce'] ?? '' ) );

	if ( ! $ip || ! wp_verify_nonce( $nonce, NONCE_UNBLOCK . '_' . $ip ) ) {
		wp_die( esc_html__( 'Invalid or expired request.', 'community-code' ) );
	}

	$blocked = get_option( BLOCKED_OPTION, [] );
	unset( $blocked[ $ip ] );
	update_option( BLOCKED_OPTION, $blocked, false );

	// Clear the attempt transient so they start with a clean slate.
	delete_transient( attempt_key( $ip ) );

	wp_safe_redirect(
		add_query_arg(
			[
				'page'      => ADMIN_SLUG,
				'unblocked' => '1',
				'ip'        => $ip,
			],
			admin_url( 'options-general.php' )
		)
	);
	exit;
}
