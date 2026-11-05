<?php
/**
 * Behaviour check for POST /community-code/v1/contact. Not PHPUnit: the repo has no WP test harness.
 *
 * Run from the repo root against a local site (never production):
 *   lando wp eval-file tests/cc-p1-forms/check-contact-endpoint.php
 *   lando wp eval-file tests/cc-p1-forms/check-contact-endpoint.php record
 *
 * Requests go through rest_do_request() in-process (no HTTP). wp_mail() is intercepted with
 * `pre_wp_mail`, so nothing is sent. By default Ninja Forms recording is switched off through the
 * `ninja_forms_save_submission` filter so the local DB is untouched; record leaves it on,
 * asserts the entry, and deletes it again.
 *
 * Exits non-zero if any assertion fails.
 *
 * @package CommunityCode\P1Forms
 */

// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.Security.EscapeOutput.OutputNotEscaped

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( "Run with: wp eval-file tests/cc-p1-forms/check-contact-endpoint.php\n" );
}

$cc_with_record = in_array( 'record', (array) ( $args ?? [] ), true ); // $args: positional args from `wp eval-file`.
$GLOBALS['cc_failures'] = 0;
$cc_mail        = [];
$cc_mail_result = true; // What the intercepted wp_mail() "returns".
$cc_secret      = 'test-secret-' . wp_generate_password( 12, false );
$cc_secret_now  = $cc_secret;

// Everything the endpoint sends goes here instead of out.
add_filter(
	'pre_wp_mail',
	static function ( $pre, $atts ) use ( &$cc_mail, &$cc_mail_result ) {
		$cc_mail[] = $atts;
		return $cc_mail_result;
	},
	10,
	2
);
add_filter(
	'cc_p1_forms_secret',
	static function () use ( &$cc_secret_now ) {
		return $cc_secret_now;
	}
);
if ( ! $cc_with_record ) {
	add_filter( 'ninja_forms_save_submission', '__return_false' );
}

/**
 * Fire a request at the route.
 *
 * @param array|string $body    Body (array is JSON-encoded; string is sent verbatim).
 * @param string|null  $bearer  Secret to send, or null for no Authorization header.
 */
function cc_check_request( $body, ?string $bearer ): WP_REST_Response {
	$request = new WP_REST_Request( 'POST', '/community-code/v1/contact' );
	$request->set_header( 'content-type', 'application/json' );
	if ( null !== $bearer ) {
		$request->set_header( 'authorization', 'Bearer ' . $bearer );
	}
	$request->set_body( is_array( $body ) ? wp_json_encode( $body ) : $body );
	return rest_do_request( $request );
}

/**
 * Assert an expectation and print the result.
 *
 * @param string $label Description.
 * @param bool   $ok    Whether it held.
 */
function cc_check( string $label, bool $ok ): void {
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . "\n";
	if ( ! $ok ) {
		++$GLOBALS['cc_failures'];
	}
}

$cc_valid = [
	'name'    => "Ada Lovelace\r\nBcc: evil@example.com",
	'email'   => 'ada@example.com',
	'message' => "Hello there.\nSecond line.",
];

// Pre-flight: the route must exist.
cc_check( 'route registered', isset( rest_get_server()->get_routes()['/community-code/v1/contact'] ) );

// 1. No secret configured -> 503, even when a (guessable) bearer is sent.
$cc_secret_now = '';
$res           = cc_check_request( $cc_valid, 'anything' );
cc_check( 'no secret configured -> 503', 503 === $res->get_status() );
cc_check( '503 body is {"error": string}', isset( $res->get_data()['error'] ) && 1 === count( $res->get_data() ) );
cc_check( '503 is Cache-Control: no-store', 'no-store' === ( $res->get_headers()['Cache-Control'] ?? '' ) );
$res = cc_check_request( $cc_valid, null );
cc_check( 'no secret configured + no header -> 503', 503 === $res->get_status() );
$cc_secret_now = $cc_secret;

// 2. Wrong / missing secret -> 401.
cc_check( 'wrong secret -> 401', 401 === cc_check_request( $cc_valid, 'wrong' )->get_status() );
cc_check( 'missing Authorization -> 401', 401 === cc_check_request( $cc_valid, null )->get_status() );
cc_check( 'valid input but no mail sent when unauthorised', 0 === count( $cc_mail ) );
// Auth must win over validation: bad input from an unauthenticated caller is 401/503, never 400.
cc_check( 'bad input + wrong secret -> 401 (not 400)', 401 === cc_check_request( [ 'name' => '' ], 'wrong' )->get_status() );
$cc_secret_now = '';
cc_check( 'bad input + no secret configured -> 503 (not 400)', 503 === cc_check_request( [ 'name' => '' ], null )->get_status() );
$cc_secret_now = $cc_secret;

// 3. Bad input -> 400 {"error": ...}.
$bad_inputs = [
	'missing name'      => [
		'email' => 'a@example.com',
		'message' => 'hi',
	],
	'empty name'        => [
		'name' => '',
		'email' => 'a@example.com',
		'message' => 'hi',
	],
	'tag-only name'     => [
		'name' => '<b></b>',
		'email' => 'a@example.com',
		'message' => 'hi',
	],
	'name too long'     => [
		'name' => str_repeat( 'a', 101 ),
		'email' => 'a@example.com',
		'message' => 'hi',
	],
	'bad email'         => [
		'name' => 'A',
		'email' => 'not-an-email',
		'message' => 'hi',
	],
	'email w/ newline'  => [
		'name' => 'A',
		'email' => "a@example.com\nBcc: x@example.com",
		'message' => 'hi',
	],
	'email too long'    => [
		'name' => 'A',
		'email' => str_repeat( 'a', 250 ) . '@example.com',
		'message' => 'hi',
	],
	'empty message'     => [
		'name' => 'A',
		'email' => 'a@example.com',
		'message' => '',
	],
	'message too long'  => [
		'name' => 'A',
		'email' => 'a@example.com',
		'message' => str_repeat( 'a', 5001 ),
	],
	'non-string name'   => [
		'name' => [ 'x' ],
		'email' => 'a@example.com',
		'message' => 'hi',
	],
	'numeric message'   => [
		'name' => 'A',
		'email' => 'a@example.com',
		'message' => 12345,
	],
];
foreach ( $bad_inputs as $label => $body ) {
	$res = cc_check_request( $body, $cc_secret );
	$ok  = 400 === $res->get_status() && is_string( $res->get_data()['error'] ?? null ) && 1 === count( $res->get_data() );
	cc_check( "bad input ({$label}) -> 400 {error}", $ok );
}
cc_check( 'malformed JSON -> 400', 400 === cc_check_request( '{not json', $cc_secret )->get_status() );
cc_check( 'no mail sent for any rejected request', 0 === count( $cc_mail ) );

// Boundary values that must be accepted.
$edge = cc_check_request(
	[
		'name'    => str_repeat( 'é', 100 ),
		'email'   => 'a@example.com',
		'message' => str_repeat( 'm', 5000 ),
	],
	$cc_secret
);
cc_check( 'max-length (100 multibyte chars / 5000) -> 200', 200 === $edge->get_status() );
$cc_mail = [];

// 4. Valid -> 200 {"ok": true}; mail shape.
$res = cc_check_request( $cc_valid, $cc_secret );
cc_check( 'valid -> 200', 200 === $res->get_status() );
cc_check( 'valid body is {"ok": true}', [ 'ok' => true ] === $res->get_data() );
cc_check( 'valid is Cache-Control: no-store', 'no-store' === ( $res->get_headers()['Cache-Control'] ?? '' ) );
cc_check( 'exactly one mail', 1 === count( $cc_mail ) );

$sent    = $cc_mail[0] ?? [
	'to' => [],
	'subject' => '',
	'message' => '',
	'headers' => [],
];
$headers = implode( "\n", (array) $sent['headers'] );
cc_check( 'recipient is a valid address', 1 <= count( (array) $sent['to'] ) && is_email( ( (array) $sent['to'] )[0] ) );
cc_check( 'subject prefix + name on one line', 1 === preg_match( '/^\[Community \+ Code\] Message from Ada Lovelace/', $sent['subject'] ) && false === strpbrk( $sent['subject'], "\r\n" ) );
cc_check( 'no From header (site default sender used)', 0 === preg_match( '/^from:/im', $headers ) );
cc_check( 'Reply-To is the visitor', false !== stripos( $headers, 'Reply-To: ada@example.com' ) );
cc_check( 'no injected Bcc header from name', 0 === preg_match( '/^bcc:/im', $headers ) );
cc_check( 'plain text body contains message', false !== strpos( $sent['message'], "Hello there.\nSecond line." ) );

// 5. wp_mail failure -> 500 without details.
$cc_mail        = [];
$cc_mail_result = false;
$res            = cc_check_request( $cc_valid, $cc_secret );
cc_check( 'wp_mail false -> 500 {error}', 500 === $res->get_status() && is_string( $res->get_data()['error'] ?? null ) );
cc_check( '500 error does not echo submitted data', false === stripos( wp_json_encode( $res->get_data() ), 'ada@example.com' ) );
$cc_mail_result = true;

// 6. Optional: Ninja Forms recording.
if ( $cc_with_record ) {
	$before = get_posts(
		[
			'post_type'      => 'nf_sub',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'DESC',
		]
	);
	$res    = cc_check_request(
		[
			'name'    => 'NF Record Check',
			'email'   => 'nf-record-check@example.com',
			'message' => 'Temporary entry created by check-contact-endpoint.php',
		],
		$cc_secret
	);
	cc_check( 'recording request -> 200', 200 === $res->get_status() );
	$after  = get_posts(
		[
			'post_type'      => 'nf_sub',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'DESC',
		]
	);
	$new_id = $after ? (int) $after[0] : 0;
	cc_check( 'a new nf_sub entry exists', $new_id > 0 && ( ! $before || $new_id > (int) $before[0] ) );
	if ( $new_id ) {
		$sub = Ninja_Forms()->form( 1 )->get_sub( $new_id );
		cc_check( 'entry holds the submitted name/email/message', 'NF Record Check' === $sub->get_field_value( 'name' ) && 'nf-record-check@example.com' === $sub->get_field_value( 'email' ) && false !== strpos( (string) $sub->get_field_value( 'message' ), 'Temporary entry' ) );
		wp_delete_post( $new_id, true );
		echo "      (deleted temporary nf_sub {$new_id})\n";
	}
} else {
	echo "SKIP  Ninja Forms recording (pass `record` to exercise it)\n";
}

$cc_failures = $GLOBALS['cc_failures'];
echo $cc_failures ? "\n{$cc_failures} check(s) FAILED\n" : "\nAll checks passed\n";
exit( $cc_failures ? 1 : 0 );
