<?php
/**
 * Behaviour check for POST /community-code/v1/newsletter. Not PHPUnit: the repo has no WP test harness.
 *
 * Run from the repo root against a local site (never production):
 *   lando wp eval-file tests/cc-p1-forms/check-newsletter-endpoint.php
 *
 * Requests go through rest_do_request() in-process (no HTTP). The MailPoet API is replaced with an
 * in-memory fake via the `cc_p1_forms_mailpoet_api` filter, so no subscriber is created and no
 * email is sent. This verifies OUR logic against the MailPoet 5.38.0 API contract as read from
 * source; it does not prove real MailPoet behaves that way (run a manual signup on a local site).
 *
 * Exits non-zero if any assertion fails.
 *
 * @package CommunityCode\P1Forms
 */

// The fake mirrors MailPoet's camelCase method names on purpose; this is a standalone CLI script, not shipped code.
// phpcs:disable WordPress.Security.EscapeOutput, Squiz.PHP.Eval.Discouraged, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid, Squiz.Commenting.FunctionComment.Missing, Universal.Files.SeparateFunctionsFromOO.Mixed, WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.Files.FileName, Generic.Commenting.DocComment.MissingShort

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( "Run with: wp eval-file tests/cc-p1-forms/check-newsletter-endpoint.php\n" );
}

$GLOBALS['cc_failures'] = 0;
$cc_secret              = 'test-secret-' . wp_generate_password( 12, false );
$cc_secret_now          = $cc_secret;

// MailPoet's exception class may be absent (plugin inactive locally); the plugin matches it by name.
if ( ! class_exists( 'MailPoet\API\MP\v1\APIException' ) ) {
	eval( 'namespace MailPoet\API\MP\v1; class APIException extends \Exception {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
}

/**
 * In-memory stand-in for \MailPoet\API\MP\v1\API: the four methods the route uses, same signatures.
 */
class CC_Fake_MailPoet {
	/** @var array<string,array> Subscribers by email. */
	public $subscribers = [];
	/** @var array[] Lists as getLists() returns them. */
	public $lists = [
		[
			'id' => '7',
			'name' => 'Newsletter',
		],
	];
	/** @var array[] Calls that would have changed something. */
	public $writes = [];
	/** @var int|null Make addSubscriber throw this MailPoet code. */
	public $add_throws = null;
	/** @var bool Simulate losing a race: add reports "exists" and the subscriber appears. */
	public $race = false;

	public function getLists(): array {
		return $this->lists;
	}

	public function getSubscriber( $email ): array {
		if ( ! isset( $this->subscribers[ $email ] ) ) {
			throw new \MailPoet\API\MP\v1\APIException( 'This subscriber does not exist.', 4 );
		}
		return $this->subscribers[ $email ];
	}

	public function addSubscriber( array $subscriber, $list_ids = [], $options = [] ): array {
		$this->writes[] = [ 'add', $subscriber, $list_ids, $options ];
		if ( null !== $this->add_throws ) {
			throw new \MailPoet\API\MP\v1\APIException( 'secret@example.com failed', $this->add_throws );
		}
		if ( $this->race ) {
			$this->subscribers[ $subscriber['email'] ] = [
				'status' => 'unconfirmed',
				'deleted_at' => null,
			];
			throw new \MailPoet\API\MP\v1\APIException( 'This subscriber already exists.', 12 );
		}
		return [ 'status' => 'unconfirmed' ];
	}

	public function subscribeToLists( $subscriber_id, array $list_ids, $options = [] ): array {
		$this->writes[] = [ 'subscribe', $subscriber_id, $list_ids, $options ];
		return [];
	}
}

$cc_fake = new CC_Fake_MailPoet();
add_filter(
	'cc_p1_forms_mailpoet_api',
	static function () use ( &$cc_fake ) {
		return $cc_fake;
	}
);
add_filter(
	'cc_p1_forms_secret',
	static function ( $secret, $constant ) use ( &$cc_secret_now ) {
		return 'CC_P1_NEWSLETTER_SECRET' === $constant ? $cc_secret_now : $secret;
	},
	10,
	2
);
// Neutralise any locally configured list id / double opt-in setting so the cases below are deterministic.
add_filter( 'cc_p1_forms_newsletter_list_id', '__return_zero' );
$cc_doi = true;
add_filter(
	'cc_p1_forms_mailpoet_double_opt_in',
	static function () use ( &$cc_doi ) {
		return $cc_doi;
	}
);

/**
 * Fire a request at the route.
 *
 * @param array|string $body   Body (array is JSON-encoded; string is sent verbatim).
 * @param string|null  $bearer Secret to send, or null for no Authorization header.
 */
function cc_nl_request( $body, ?string $bearer ): WP_REST_Response {
	$request = new WP_REST_Request( 'POST', '/community-code/v1/newsletter' );
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

cc_check( 'route registered', isset( rest_get_server()->get_routes()['/community-code/v1/newsletter'] ) );

// 1. Auth: 503 / 401, and auth beats validation.
$cc_secret_now = '';
cc_check( 'no secret configured -> 503', 503 === cc_nl_request( [ 'email' => 'a@example.com' ], 'anything' )->get_status() );
cc_check( 'no secret configured + bad input -> 503 (not 400)', 503 === cc_nl_request( [ 'email' => 'nope' ], null )->get_status() );
$res = cc_nl_request( [ 'email' => 'a@example.com' ], null );
cc_check( '503 is {"error"} + no-store', isset( $res->get_data()['error'] ) && 1 === count( $res->get_data() ) && 'no-store' === ( $res->get_headers()['Cache-Control'] ?? '' ) );
$cc_secret_now = $cc_secret;
cc_check( 'wrong secret -> 401', 401 === cc_nl_request( [ 'email' => 'a@example.com' ], 'wrong' )->get_status() );
cc_check( 'missing Authorization -> 401', 401 === cc_nl_request( [ 'email' => 'a@example.com' ], null )->get_status() );
cc_check( 'bad input + wrong secret -> 401 (not 400)', 401 === cc_nl_request( [ 'email' => 'nope' ], 'wrong' )->get_status() );
cc_check( 'contact secret does not open newsletter', 401 === cc_nl_request( [ 'email' => 'a@example.com' ], 'some-contact-secret' )->get_status() );
cc_check( 'nothing written when unauthorised', [] === $cc_fake->writes );

// 2. Validation -> 400 {"error"}.
$bad = [
	'missing email'      => [ 'first_name' => 'Ada' ],
	'bad email'          => [ 'email' => 'not-an-email' ],
	'email w/ newline'   => [ 'email' => "a@example.com\nBcc: x@example.com" ],
	'email too long'     => [ 'email' => str_repeat( 'a', 250 ) . '@example.com' ],
	'non-string email'   => [ 'email' => [ 'a@example.com' ] ],
	'first_name too long' => [
		'email' => 'a@example.com',
		'first_name' => str_repeat( 'a', 81 ),
	],
	'first_name array'   => [
		'email' => 'a@example.com',
		'first_name' => [ 'x' ],
	],
];
foreach ( $bad as $label => $body ) {
	$res = cc_nl_request( $body, $cc_secret );
	cc_check( "bad input ({$label}) -> 400 {error}", 400 === $res->get_status() && is_string( $res->get_data()['error'] ?? null ) && 1 === count( $res->get_data() ) );
}
cc_check( 'malformed JSON -> 400', 400 === cc_nl_request( '{nope', $cc_secret )->get_status() );
cc_check( 'nothing written for rejected input', [] === $cc_fake->writes );

// 3. New address -> 200, add with double opt-in, no welcome email.
$new = cc_nl_request( [
	'email' => 'new@example.com',
	'first_name' => "  Ada \n  Lovelace ",
], $cc_secret );
cc_check( 'new address -> 200 {"ok":true}', 200 === $new->get_status() && [ 'ok' => true ] === $new->get_data() );
cc_check( 'new: no-store', 'no-store' === ( $new->get_headers()['Cache-Control'] ?? '' ) );
$w = $cc_fake->writes[0] ?? [];
cc_check( 'new: one addSubscriber call', 1 === count( $cc_fake->writes ) && 'add' === ( $w[0] ?? '' ) );
cc_check( 'new: email + whitespace-collapsed first_name', ( $w[1] ?? [] ) === [
	'email' => 'new@example.com',
	'first_name' => 'Ada Lovelace',
] );
cc_check( 'new: the only list (id 7) used', [ 7 ] === array_map( 'intval', (array) ( $w[2] ?? [] ) ) );
cc_check( 'new: confirmation on, welcome off', true === ( $w[3]['send_confirmation_email'] ?? null ) && false === ( $w[3]['schedule_welcome_email'] ?? null ) );

$cc_fake->writes = [];
cc_nl_request( [
	'email' => 'noname@example.com',
	'first_name' => '',
], $cc_secret );
cc_check( 'new without first_name: key omitted', ! array_key_exists( 'first_name', $cc_fake->writes[0][1] ?? [ 'first_name' => 1 ] ) );

// 4. Existing subscribers: identical response, behaviour per status.
$cases = [
	'subscribed'  => [
		[
			'status' => 'subscribed',
			'deleted_at' => null,
		],
		0,
	],
	'unsubscribed' => [
		[
			'status' => 'unsubscribed',
			'deleted_at' => null,
		],
		0,
	],
	'bounced'     => [
		[
			'status' => 'bounced',
			'deleted_at' => null,
		],
		0,
	],
	'inactive'    => [
		[
			'status' => 'inactive',
			'deleted_at' => null,
		],
		0,
	],
	'trashed'     => [
		[
			'status' => 'unconfirmed',
			'deleted_at' => '2026-01-01 00:00:00',
		],
		0,
	],
	'unconfirmed' => [
		[
			'status' => 'unconfirmed',
			'deleted_at' => null,
		],
		1,
	],
];
foreach ( $cases as $status => [ $subscriber, $expected_writes ] ) {
	$cc_fake->writes                         = [];
	$cc_fake->subscribers['ex@example.com'] = $subscriber;
	$res                                     = cc_nl_request( [ 'email' => 'ex@example.com' ], $cc_secret );
	cc_check( "existing {$status}: response identical to new signup", $res->get_status() === $new->get_status() && $res->get_data() === $new->get_data() && $res->get_headers() === $new->get_headers() );
	cc_check( "existing {$status}: {$expected_writes} write(s)", count( $cc_fake->writes ) === $expected_writes && ( 1 !== $expected_writes || ( 'subscribe' === $cc_fake->writes[0][0] && true === $cc_fake->writes[0][3]['send_confirmation_email'] && false === $cc_fake->writes[0][3]['schedule_welcome_email'] ) ) );
}
$cc_fake->subscribers = [];

// 5. Race: addSubscriber says "exists" -> treated as existing (unconfirmed -> resend).
$cc_fake->writes = [];
$cc_fake->race   = true;
$res             = cc_nl_request( [ 'email' => 'race@example.com' ], $cc_secret );
$cc_fake->race   = false;
cc_check( 'race on add -> 200 and confirmation resent', 200 === $res->get_status() && 2 === count( $cc_fake->writes ) && 'subscribe' === $cc_fake->writes[1][0] );
$cc_fake->subscribers = [];

// 6. MailPoet failure -> 500 generic, no leakage.
$cc_fake->writes     = [];
$cc_fake->add_throws = 13; // FAILED_TO_SAVE_SUBSCRIBER (also what a MailPoet-side validation failure surfaces as).
$res                 = cc_nl_request( [ 'email' => 'fail@example.com' ], $cc_secret );
$cc_fake->add_throws = null;
$encoded             = wp_json_encode( $res->get_data() );
cc_check( 'MailPoet failure -> 500 {error}', 500 === $res->get_status() && is_string( $res->get_data()['error'] ?? null ) );
cc_check( '500 leaks neither email nor exception text', false === stripos( $encoded, 'example.com' ) && false === stripos( $encoded, 'failed' ) );

// 7. List resolution and config guards -> 503.
$cc_fake->lists = [ [ 'id' => '7' ], [ 'id' => '9' ] ];
cc_check( 'two lists, none configured -> 503', 503 === cc_nl_request( [ 'email' => 'l@example.com' ], $cc_secret )->get_status() );
$cc_fake->lists = [];
cc_check( 'no lists -> 503', 503 === cc_nl_request( [ 'email' => 'l@example.com' ], $cc_secret )->get_status() );
$cc_fake->lists = [ [ 'id' => '7' ], [ 'id' => '9' ] ];
add_filter( 'cc_p1_forms_newsletter_list_id', static fn() => 9, 20 );
$cc_fake->writes = [];
$res             = cc_nl_request( [ 'email' => 'l@example.com' ], $cc_secret );
cc_check( 'configured list id wins over several lists', 200 === $res->get_status() && [ 9 ] === array_map( 'intval', (array) ( $cc_fake->writes[0][2] ?? [] ) ) );
$cc_fake->lists = [ [ 'id' => '7' ] ];
$cc_doi         = false;
$cc_fake->writes = [];
cc_check( 'double opt-in disabled -> 503 and nothing written', 503 === cc_nl_request( [ 'email' => 'd@example.com' ], $cc_secret )->get_status() && [] === $cc_fake->writes );
$cc_doi = true;

if ( class_exists( '\MailPoet\API\API' ) ) {
	echo "SKIP  MailPoet-inactive 503 (MailPoet is loaded here, cannot simulate absence)\n";
}

$cc_failures = $GLOBALS['cc_failures'];
echo $cc_failures ? "\n{$cc_failures} check(s) FAILED\n" : "\nAll checks passed\n";
exit( $cc_failures ? 1 : 0 );
