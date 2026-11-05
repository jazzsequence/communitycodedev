<?php
/**
 * POST /wp-json/community-code/v1/newsletter — MailPoet double opt-in signup for the P1 frontend.
 *
 * Written against the MailPoet public PHP API (\MailPoet\API\API::MP( 'v1' )) as read from the
 * installed plugin source, version 5.38.0 (lib/API/MP/v1/{API,Subscribers,APIException}.php).
 *
 * @package CommunityCode\P1Forms
 */

namespace CommunityCode\P1Forms;

/**
 * MailPoet APIException codes we branch on. Mirrors \MailPoet\API\MP\v1\APIException (5.38.0).
 * Copied rather than referenced so this file loads, and can be tested, when MailPoet is inactive.
 */
const MAILPOET_SUBSCRIBER_NOT_EXISTS = 4;
const MAILPOET_SUBSCRIBER_EXISTS     = 12;

/**
 * Existing-subscriber statuses we are willing to push back through the confirmation flow.
 *
 * Anything else (subscribed, unsubscribed, bounced, inactive) is a silent no-op: an unauthenticated
 * request must never override an explicit opt-out, resurrect a bounced address, or add an already
 * confirmed person to a list they have not confirmed. Adding 'unsubscribed' here would work through
 * double opt-in in 5.38.0 (subscribeToLists() sets status "unconfirmed" and mails a confirmation),
 * but it erases the subscriber's unsubscribed state on a request they did not make, so it is off.
 */
const NEWSLETTER_RESEND_STATUSES = [ 'unconfirmed' ];

/** Fields: max is in characters. first_name is optional. */
const NEWSLETTER_EMAIL_MAX      = 254;
const NEWSLETTER_FIRST_NAME_MAX = 80;

/**
 * Register the newsletter route.
 */
function register_newsletter_route(): void {
	register_rest_route(
		REST_NAMESPACE,
		'/newsletter',
		[
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => __NAMESPACE__ . '\\handle_newsletter',
			'permission_callback' => __NAMESPACE__ . '\\authorize',
			'args'                => [
				'email'      => [
					'type'              => 'string',
					'required'          => true,
					'validate_callback' => __NAMESPACE__ . '\\validate_newsletter_field',
					'sanitize_callback' => 'sanitize_email',
				],
				'first_name' => [
					'type'              => 'string',
					'required'          => false,
					'validate_callback' => __NAMESPACE__ . '\\validate_newsletter_field',
					'sanitize_callback' => 'sanitize_text_field',
				],
			],
		]
	);
}

/**
 * Validate `email` / `first_name`.
 *
 * @param mixed            $value   Raw value.
 * @param \WP_REST_Request $request Request (unused).
 * @param string           $key     Param name.
 * @return true|\WP_Error
 */
function validate_newsletter_field( $value, $request, $key ) {
	if ( null === $value && 'first_name' === $key ) {
		return true; // JSON null is "not provided".
	}

	if ( ! is_string( $value ) ) {
		return new \WP_Error( 'cc_p1_invalid', 'Invalid value.', [ 'status' => 400 ] );
	}

	if ( 'email' === $key ) {
		// is_email() on the raw value: it rejects whitespace, whereas sanitize_email() would quietly "fix" it.
		$ok = mb_strlen( $value, 'UTF-8' ) <= NEWSLETTER_EMAIL_MAX && is_email( $value );
		return $ok ? true : new \WP_Error( 'cc_p1_invalid', 'Please enter a valid email address.', [ 'status' => 400 ] );
	}

	// Length is judged after sanitising (which collapses whitespace); the raw cap only bounds the work done.
	$too_long = mb_strlen( $value, 'UTF-8' ) > 1000 || mb_strlen( sanitize_text_field( $value ), 'UTF-8' ) > NEWSLETTER_FIRST_NAME_MAX;
	return $too_long ? new \WP_Error( 'cc_p1_invalid', 'First name is too long.', [ 'status' => 400 ] ) : true;
}

/**
 * Handle an authorised, validated signup.
 *
 * Every outcome that is not an error answers identically, so the endpoint cannot be used to learn
 * who is already on the list. Timing is not equalised: a brand-new address additionally sends a
 * confirmation email, which takes longer than the no-op paths.
 *
 * @param \WP_REST_Request $request Request with sanitised params.
 * @return \WP_REST_Response|\WP_Error
 */
function handle_newsletter( \WP_REST_Request $request ) {
	$email      = (string) $request->get_param( 'email' );
	$first_name = (string) $request->get_param( 'first_name' );

	try {
		$api = mailpoet_api();
		if ( null === $api ) {
			return newsletter_unavailable( 'MailPoet is not active' );
		}

		// Double opt-in is a requirement; with it off MailPoet would subscribe addresses immediately.
		if ( ! mailpoet_double_opt_in_enabled() ) {
			return newsletter_unavailable( 'MailPoet signup confirmation (double opt-in) is disabled' );
		}

		$list_id = newsletter_list_id( $api );
		if ( $list_id < 1 ) {
			return newsletter_unavailable( 'no unambiguous newsletter list; set the cc_p1_newsletter_list_id secret' );
		}

		subscribe_to_newsletter( $api, $email, $first_name, $list_id );
	} catch ( \Throwable $e ) {
		// Class and code only: MailPoet exception messages can embed the address.
		error_log( 'cc-p1-forms: newsletter MailPoet failure (' . get_class( $e ) . ' code ' . (int) $e->getCode() . ')' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		return new \WP_Error( 'cc_p1_newsletter_failed', 'Your signup could not be completed. Please try again later.', [ 'status' => 500 ] );
	}

	return new \WP_REST_Response( [ 'ok' => true ], 200 );
}

/**
 * Generic 503 that also records a one-line, content-free reason for operators.
 *
 * @param string $reason Config problem; must never contain submitted data.
 */
function newsletter_unavailable( string $reason ): \WP_Error {
	error_log( 'cc-p1-forms: newsletter unavailable: ' . $reason ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	return new \WP_Error( 'cc_p1_newsletter_unavailable', 'This service is temporarily unavailable.', [ 'status' => 503 ] );
}

/**
 * The MailPoet v1 API object, or null when MailPoet is not available.
 *
 * The filter is a test seam (tests/cc-p1-forms/check-newsletter-endpoint.php) so no real
 * subscriber is ever created by a check run.
 *
 * @return object|null
 */
function mailpoet_api() {
	$api = apply_filters( 'cc_p1_forms_mailpoet_api', null );
	if ( is_object( $api ) ) {
		return $api;
	}

	if ( ! class_exists( '\MailPoet\API\API' ) ) {
		return null;
	}

	return \MailPoet\API\API::MP( 'v1' );
}

/**
 * Whether MailPoet's global "Enable sign-up confirmation" setting is on.
 *
 * SettingsController is internal to MailPoet (not part of the public API), so if it ever moves we
 * fail open on this one check rather than take the endpoint down; the 5.38.0 default is enabled.
 */
function mailpoet_double_opt_in_enabled(): bool {
	$override = apply_filters( 'cc_p1_forms_mailpoet_double_opt_in', null );
	if ( null !== $override ) {
		return (bool) $override;
	}

	if ( ! class_exists( '\MailPoet\Settings\SettingsController' ) ) {
		return true;
	}

	return (bool) \MailPoet\Settings\SettingsController::getInstance()->get( 'signup_confirmation.enabled' );
}

/**
 * Pick the list to subscribe to; never guess.
 *
 * Order: CC_P1_NEWSLETTER_LIST_ID, else the only list MailPoet has (getLists() returns
 * default-type lists only: WordPress Users and WooCommerce Customers segments are excluded),
 * else 0 which the caller turns into a 503.
 *
 * @param object $api MailPoet v1 API.
 * @return int List id, or 0 when it cannot be determined.
 */
function newsletter_list_id( $api ): int {
	$configured = apply_filters( 'cc_p1_forms_newsletter_list_id', defined( 'CC_P1_NEWSLETTER_LIST_ID' ) ? CC_P1_NEWSLETTER_LIST_ID : 0 );
	if ( is_numeric( $configured ) && (int) $configured > 0 ) {
		return (int) $configured;
	}

	$lists = $api->getLists();
	return ( is_array( $lists ) && 1 === count( $lists ) ) ? (int) reset( $lists )['id'] : 0;
}

/**
 * Whether an exception is MailPoet's APIException with a given code.
 *
 * Checked by class name so this works without MailPoet's classes being loaded.
 *
 * @param \Throwable $e    Exception.
 * @param int        $code MailPoet APIException code.
 */
function is_mailpoet_error( \Throwable $e, int $code ): bool {
	return is_a( $e, 'MailPoet\API\MP\v1\APIException' ) && $code === (int) $e->getCode();
}

/**
 * Look a subscriber up by email.
 *
 * @param object $api   MailPoet v1 API.
 * @param string $email Email.
 * @return array|null Subscriber data, or null if there is none.
 * @throws \Throwable Anything other than "subscriber does not exist".
 */
function find_mailpoet_subscriber( $api, string $email ): ?array {
	try {
		$subscriber = $api->getSubscriber( $email );
		return is_array( $subscriber ) ? $subscriber : null;
	} catch ( \Throwable $e ) {
		if ( is_mailpoet_error( $e, MAILPOET_SUBSCRIBER_NOT_EXISTS ) ) {
			return null;
		}
		throw $e;
	}
}

/**
 * Subscribe an address with double opt-in, idempotently.
 *
 * - New address: addSubscriber() with a confirmation email and no welcome email.
 * - Existing "unconfirmed": subscribeToLists() resends the confirmation (MailPoet itself stops
 *   after 3 confirmation emails per subscriber for anonymous callers, so this cannot be used to
 *   mail-bomb someone).
 * - Everything else: no-op (see NEWSLETTER_RESEND_STATUSES).
 *
 * @param object $api        MailPoet v1 API.
 * @param string $email      Validated email.
 * @param string $first_name Sanitised first name, may be empty.
 * @param int    $list_id    MailPoet list id.
 * @throws \Throwable On MailPoet failure; the caller maps it to a generic 500.
 */
function subscribe_to_newsletter( $api, string $email, string $first_name, int $list_id ): void {
	// Confirmation email on, welcome email off. Admin new-subscriber notification follows MailPoet's own setting.
	$options = [
		'send_confirmation_email' => true,
		'schedule_welcome_email'  => false,
	];

	$existing = find_mailpoet_subscriber( $api, $email );

	if ( null === $existing ) {
		$data = [ 'email' => $email ];
		if ( '' !== $first_name ) {
			$data['first_name'] = $first_name;
		}

		try {
			$api->addSubscriber( $data, [ $list_id ], $options );
			return;
		} catch ( \Throwable $e ) {
			// Lost a race with a concurrent signup for the same address: treat it as existing.
			if ( ! is_mailpoet_error( $e, MAILPOET_SUBSCRIBER_EXISTS ) ) {
				throw $e;
			}
			$existing = find_mailpoet_subscriber( $api, $email );
			if ( null === $existing ) {
				throw $e;
			}
		}
	}

	// A subscriber an admin moved to the trash was removed on purpose; subscribeToLists() would restore them.
	if ( ! empty( $existing['deleted_at'] ) || ! in_array( $existing['status'] ?? '', NEWSLETTER_RESEND_STATUSES, true ) ) {
		return;
	}

	$api->subscribeToLists( $email, [ $list_id ], $options );
}
