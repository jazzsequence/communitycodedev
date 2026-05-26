<?php
/**
 * Admin settings page for AI Connector Secure Layer.
 */

namespace AICSL\Admin;

function register_menu(): void {
	add_options_page(
		__( 'AI Connector', 'ai-connector-secure-layer' ),
		__( 'AI Connector', 'ai-connector-secure-layer' ),
		'manage_options',
		'aicsl',
		__NAMESPACE__ . '\render_page'
	);
}

function enqueue_scripts( string $hook ): void {
	if ( $hook !== 'settings_page_aicsl' ) {
		return;
	}

	$plugin_url = plugin_dir_url( dirname( __FILE__ ) );

	wp_enqueue_style(
		'aicsl-admin',
		$plugin_url . 'assets/css/admin.css',
		[],
		AICSL_VERSION
	);

	wp_enqueue_script(
		'aicsl-setup',
		$plugin_url . 'assets/js/setup.js',
		[],
		AICSL_VERSION,
		true
	);

	wp_localize_script(
		'aicsl-setup',
		'aicslConfig',
		[
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'restUrl' => rest_url( 'aicsl/v1/' ),
			'hasKey'  => (bool) get_option( 'aicsl_ciphertext' ),
		]
	);

	wp_enqueue_script(
		'aicsl-connector',
		$plugin_url . 'assets/js/connector.js',
		[ 'aicsl-setup' ],
		AICSL_VERSION,
		true
	);
}

function render_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'AI Connector — Secure Key Storage', 'ai-connector-secure-layer' ); ?></h1>

		<p>
			<?php esc_html_e( 'Your API key is encrypted in the browser before being sent here. The server stores only ciphertext — the decryption key never leaves your browser session.', 'ai-connector-secure-layer' ); ?>
		</p>

		<?php if ( get_option( 'aicsl_ciphertext' ) ) : ?>
			<div class="notice notice-success inline">
				<p><?php esc_html_e( 'An encrypted API key is stored. Re-enter below to replace it.', 'ai-connector-secure-layer' ); ?></p>
			</div>
		<?php endif; ?>

		<div id="aicsl-setup-form">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="aicsl-api-key"><?php esc_html_e( 'LLM API Key', 'ai-connector-secure-layer' ); ?></label>
					</th>
					<td>
						<input
							type="password"
							id="aicsl-api-key"
							class="regular-text"
							placeholder="<?php esc_attr_e( 'sk-…', 'ai-connector-secure-layer' ); ?>"
							autocomplete="off"
						/>
						<p class="description">
							<?php esc_html_e( 'Encrypted client-side before transmission. Never stored in plaintext.', 'ai-connector-secure-layer' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<p class="submit">
				<button type="button" id="aicsl-save-key" class="button button-primary">
					<?php esc_html_e( 'Encrypt &amp; Save Key', 'ai-connector-secure-layer' ); ?>
				</button>
			</p>

			<div id="aicsl-status" aria-live="polite"></div>
		</div>

		<hr/>

		<h2><?php esc_html_e( 'Security Model', 'ai-connector-secure-layer' ); ?></h2>
		<ul class="aicsl-security-list">
			<li><?php esc_html_e( 'AES-256-GCM encryption happens in your browser via the Web Crypto API.', 'ai-connector-secure-layer' ); ?></li>
			<li><?php esc_html_e( 'The server stores only ciphertext. A database dump yields nothing usable.', 'ai-connector-secure-layer' ); ?></li>
			<li><?php esc_html_e( 'The decryption key lives in sessionStorage and is sent per-request over HTTPS. It is never stored on the server.', 'ai-connector-secure-layer' ); ?></li>
			<li><?php esc_html_e( 'Closing the browser tab clears the decryption key. You will need to re-enter your API key next session.', 'ai-connector-secure-layer' ); ?></li>
		</ul>
	</div>
	<?php
}
