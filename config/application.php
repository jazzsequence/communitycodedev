<?php
/**
 * Your base production configuration goes in this file.
 *
 * A good default policy is to deviate from the production config as little as
 * possible. Try to define as much of your configuration in this file as you
 * can.
 */

use Roots\WPConfig\Config;
use function Env\env;

// USE_ENV_ARRAY + CONVERT_* + STRIP_QUOTES.
Env\Env::$options = 31;

/**
 * Directory containing all of the site's files
 *
 * @var string
 */
$root_dir = dirname( __DIR__ );

/**
 * Document Root
 *
 * @var string
 */
$webroot_dir = $root_dir . '/web';

/**
 * Use Dotenv to set required environment variables and load .env file in root
 * .env.local will override .env if it exists
 */
$env_files = file_exists( $root_dir . '/.env.local' )
	? [ '.env', '.env.pantheon', '.env.local' ]
	: [ '.env', '.env.pantheon' ];

$dotenv = Dotenv\Dotenv::createImmutable( $root_dir, $env_files, false );
if (
	// Check if a .env file exists.
	file_exists( $root_dir . '/.env' ) ||
	// Also check if we're using Lando and a .env.local file exists.
	( file_exists( $root_dir . '/.env.local' ) && isset( $_ENV['LANDO'] ) && 'ON' === $_ENV['LANDO'] )
) {
	$dotenv->load();
	if ( ! env( 'DATABASE_URL' ) ) {
		$dotenv->required( [ 'DB_NAME', 'DB_USER', 'DB_PASSWORD' ] );
	}
}

/**
 * Include Pantheon application settings.
 */
require_once __DIR__ . '/application.pantheon.php';

/**
 * Set up our global environment constant and load its config first
 * Default: production
 */
define( 'WP_ENV', env( 'WP_ENV' ) ?: 'production' );

/**
 * DB settings
 */
Config::define( 'DB_NAME', env( 'DB_NAME' ) );
Config::define( 'DB_USER', env( 'DB_USER' ) );
Config::define( 'DB_PASSWORD', env( 'DB_PASSWORD' ) );
Config::define( 'DB_HOST', env( 'DB_HOST' ) ?: 'localhost' );
Config::define( 'DB_CHARSET', 'utf8mb4' );
Config::define( 'DB_COLLATE', '' );
$table_prefix = env( 'DB_PREFIX' ) ?: 'wp_'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

if ( env( 'DATABASE_URL' ) ) {
	$dsn = (object) parse_url( env( 'DATABASE_URL' ) );

	Config::define( 'DB_NAME', substr( $dsn->path, 1 ) );
	Config::define( 'DB_USER', $dsn->user );
	Config::define( 'DB_PASSWORD', isset( $dsn->pass ) ? $dsn->pass : null );
	Config::define( 'DB_HOST', isset( $dsn->port ) ? "{$dsn->host}:{$dsn->port}" : $dsn->host );
}

/**
 * Pantheon modifications
 */
if ( isset( $_ENV['PANTHEON_ENVIRONMENT'] ) && ! isset( $_ENV['LANDO'] ) ) {
	Config::define( 'DB_HOST', $_ENV['DB_HOST'] . ':' . $_ENV['DB_PORT'] ); // phpcs:ignore
} else {
	/**
	 * URLs
	 */
	Config::define( 'WP_HOME', env( 'WP_HOME' ) );
	Config::define( 'WP_SITEURL', env( 'WP_SITEURL' ) );
	Config::define( 'DB_HOST', env( 'DB_HOST' ) ?: 'localhost' );
}

/**
 * Custom Content Directory
 */
Config::define( 'CONTENT_DIR', '/app' );
Config::define( 'WP_CONTENT_DIR', $webroot_dir . Config::get( 'CONTENT_DIR' ) );
Config::define( 'WP_CONTENT_URL', Config::get( 'WP_HOME' ) . Config::get( 'CONTENT_DIR' ) );

/**
 * Authentication Unique Keys and Salts
 */
Config::define( 'AUTH_KEY', env( 'AUTH_KEY' ) );
Config::define( 'SECURE_AUTH_KEY', env( 'SECURE_AUTH_KEY' ) );
Config::define( 'LOGGED_IN_KEY', env( 'LOGGED_IN_KEY' ) );
Config::define( 'NONCE_KEY', env( 'NONCE_KEY' ) );
Config::define( 'AUTH_SALT', env( 'AUTH_SALT' ) );
Config::define( 'SECURE_AUTH_SALT', env( 'SECURE_AUTH_SALT' ) );
Config::define( 'LOGGED_IN_SALT', env( 'LOGGED_IN_SALT' ) );
Config::define( 'NONCE_SALT', env( 'NONCE_SALT' ) );

/**
 * Custom Settings
 */
Config::define( 'AUTOMATIC_UPDATER_DISABLED', true );
// Disable the plugin and theme file editor in the admin.
Config::define( 'DISALLOW_FILE_EDIT', true );
// Disable plugin and theme updates and installation from the admin.
Config::define( 'DISALLOW_FILE_MODS', true );
// Limit the number of post revisions that Wordpress stores (true (default WP): store every revision).
Config::define( 'WP_POST_REVISIONS', env( 'WP_POST_REVISIONS' ) ?? true );
// Keep the default WordPress dashboard instead of the Altis Accelerate replacement.
Config::define( 'ALTIS_ACCELERATE_DASHBOARD', false );

/**
 * Debugging Settings
 */
if ( $_ENV['PANTHEON_ENVIRONMENT'] === 'dev' || isset( $_ENV['LANDO'] ) ) {
	Config::define( 'WP_DEBUG_DISPLAY', true );
	Config::define( 'WP_DEBUG_LOG', true );
	Config::define( 'SCRIPT_DEBUG', true );
	ini_set( 'display_errors', '1' ); // phpcs:ignore WordPress.PHP.IniSet.display_errors_Disallowed
} else {
	Config::define( 'WP_DEBUG_DISPLAY', false );
	Config::define( 'WP_DEBUG_LOG', false );
	Config::define( 'SCRIPT_DEBUG', false );
	ini_set( 'display_errors', '0' ); // phpcs:ignore WordPress.PHP.IniSet.display_errors_Disallowed
}

/**
 * Force SSL on all urls
 */
Config::define( 'FORCE_SSL_ADMIN', true );
Config::define( 'FORCE_SSL_LOGIN', true );
Config::define( 'FORCE_SSL', true );

/**
 * Allow WordPress to detect HTTPS when used behind a reverse proxy or a load balancer
 * See https://codex.wordpress.org/Function_Reference/is_ssl#Notes
 */
if ( php_sapi_name() === 'cli' && ! isset( $_SERVER['HTTPS'] ) ) {
	// Ensure CLI contexts (e.g., wp-cli reindex) treat the site as HTTPS.
	$_SERVER['HTTPS'] = 'on';
	$_SERVER['SERVER_PORT'] = 443;
	$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
}
if ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https' ) {
	$_SERVER['HTTPS'] = 'on';
}

/**
 * Local wrapper for pantheon_get_secret for local development.
 *
 * On Pantheon, uses the native pantheon_get_secret function.
 * Locally, reads from secrets.json file pulled via Pantheon Secrets terminus plugin.
 *
 * @param string $key Secret key to retrieve.
 * @return string|null Secret value or null if not found.
 */
if ( ! function_exists( 'pantheon_get_secret' ) ) {
	function pantheon_get_secret( $key ) {
		$secrets_file = dirname( __DIR__ ) . '/secrets.json';

		// If secrets.json doesn't exist, return null
		if ( ! file_exists( $secrets_file ) ) {
			error_log( sprintf( 'pantheon_get_secret: secrets.json not found at %s', $secrets_file ) );
			return null;
		}

		// Read and decode secrets.json
		$secrets_json = file_get_contents( $secrets_file );
		$secrets = json_decode( $secrets_json, true );

		// Check for JSON decode errors
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			error_log( sprintf( 'pantheon_get_secret: JSON decode error - %s', json_last_error_msg() ) );
			return null;
		}

		// Handle Pantheon Secrets JSON structure: Secrets.{key}.Value
		if ( isset( $secrets['Secrets'][ $key ]['Value'] ) ) {
			return $secrets['Secrets'][ $key ]['Value'];
		}

		// Fallback to simple key-value structure
		if ( isset( $secrets[ $key ] ) ) {
			return $secrets[ $key ];
		}

		error_log( sprintf( 'pantheon_get_secret: Key "%s" not found in secrets.json', $key ) );
		return null;
	}
}

/**
 * Object Cache Pro
 */
$token = getenv( 'OCP_LICENSE' ); // Get the license from the Pantheon environment variables.

// If working locally, set $token based on the local auth.json file.
if ( isset( $_ENV['LANDO'] ) && 'ON' === $_ENV['LANDO'] ) {
	$auth_json = ABSPATH . '/auth.json';
	if ( file_exists( $auth_json ) ) {
		$auth_json = json_decode( file_get_contents( $auth_json ) );
		$token = $auth_json['http-basic']['objectcache.pro']['password'];
	}
}

Config::define( 'WP_REDIS_CONFIG', [
	'token' => $token,
	'host' => getenv( 'CACHE_HOST' ) ?: '127.0.0.1',
	'port' => getenv( 'CACHE_PORT' ) ?: 6379,
	'database' => getenv( 'CACHE_DB' ) ?: 0,
	'password' => getenv( 'CACHE_PASSWORD' ) ?: null,
	'maxttl' => 86400 * 7,
	'timeout' => 0.5,
	'read_timeout' => 0.5,
	'split_alloptions' => true,
	'prefetch' => true,
	'debug' => false,
	'save_commands' => false,
	'analytics' => [
		'enabled' => true,
		'persist' => true,
		'retention' => 3600, // 1 hour
		'footnote' => true,
	],
	'prefix' => 'ocppantheon', // This prefix can be changed. Setting a prefix helps avoid conflict when switching from other plugins like wp-redis.
	'serializer' => 'igbinary',
	'compression' => 'zstd',
	'async_flush' => true,
	'strict' => true,
] );

/**
 * ElasticSearch
 */
$ep_prefix = pantheon_get_secret( 'ep_index_prefix' ) ?? 'pantheon-se-demo-1'; // Get the index prefix from Pantheon secrets.
$ep_host = pantheon_get_secret( 'ep_host' ) ?? ''; // Get the host from Pantheon secrets.
$ep_token = pantheon_get_secret( 'ep_token' ) ?? ''; // Get the token from Pantheon secrets.
Config::define( 'EP_INDEX_PREFIX', $ep_prefix );
Config::define( 'EP_HOST', $ep_host );
Config::define( 'EP_CREDENTIALS', "$ep_prefix:$ep_token" );

$env_config = __DIR__ . '/environments/' . WP_ENV . '.php';

if ( file_exists( $env_config ) ) {
	require_once $env_config;
}

Config::apply();

/**
 * Bootstrap WordPress
 */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $webroot_dir . '/wp/' );
}
