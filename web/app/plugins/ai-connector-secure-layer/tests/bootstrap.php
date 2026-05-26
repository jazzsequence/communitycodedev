<?php
/**
 * Bootstrap for unit tests. No WordPress loaded.
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// WP AI Client class stubs — only for unit tests.
require_once __DIR__ . '/stubs/wp-ai-client-stubs.php';

// Mock pantheon_get_secret() for unit tests. Integration tests use the real wrapper
// from config/application.php (loaded via the WP bootstrap).
if ( ! function_exists( 'pantheon_get_secret' ) ) {
	function pantheon_get_secret( string $key ): ?string {
		return $GLOBALS['_test_pantheon_secrets'][ $key ] ?? null;
	}
}

// Plugin files under test — loaded directly (no Composer autoload for plugin code).
require_once dirname( __DIR__ ) . '/includes/secrets.php';
require_once dirname( __DIR__ ) . '/includes/class-lazy-auth.php';
