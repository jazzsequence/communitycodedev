<?php
/**
 * POST /wp-json/community-code/v1/contact — receiver for the P1 contact form.
 *
 * @package CommunityCode\P1Forms
 */

namespace CommunityCode\P1Forms;

/** Ninja Forms "Contact Me" form on communitycode.dev/contact. */
const CONTACT_FORM_ID = 1;

/**
 * Field limits. `max` is in characters (not bytes) so multibyte names are not penalised.
 * Kept here, next to the sanitizers, so validation and sanitisation cannot drift apart.
 */
const CONTACT_FIELDS = [
	'name'    => [
		'max'      => 100,
		'sanitize' => 'sanitize_text_field',
		'label'    => 'Name',
	],
	'email'   => [
		'max'      => 254,
		'sanitize' => 'sanitize_email',
		'label'    => 'Email',
	],
	'message' => [
		'max'      => 5000,
		'sanitize' => 'sanitize_textarea_field',
		'label'    => 'Message',
	],
];

/**
 * Register the contact route.
 */
function register_contact_route(): void {
	$args = [];
	foreach ( array_keys( CONTACT_FIELDS ) as $field ) {
		$args[ $field ] = [
			'type'              => 'string',
			'required'          => true,
			'validate_callback' => __NAMESPACE__ . '\\validate_contact_field',
			'sanitize_callback' => CONTACT_FIELDS[ $field ]['sanitize'],
		];
	}

	register_rest_route(
		REST_NAMESPACE,
		'/contact',
		[
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => __NAMESPACE__ . '\\handle_contact',
			'permission_callback' => __NAMESPACE__ . '\\authorize',
			'args'                => $args,
		]
	);
}

/**
 * Validate one contact field.
 *
 * A custom validate_callback replaces core's schema validation, so type and length are
 * checked here. We also check the *sanitised* value is non-empty: "<b></b>" passes a raw
 * length check but sanitises to nothing.
 *
 * @param mixed            $value   Raw value.
 * @param \WP_REST_Request $request Request (unused).
 * @param string           $key     Param name.
 * @return true|\WP_Error
 */
function validate_contact_field( $value, $request, $key ) {
	$rules = CONTACT_FIELDS[ $key ];

	if ( ! is_string( $value ) ) {
		return new \WP_Error( 'cc_p1_invalid', sprintf( '%s must be text.', $rules['label'] ), [ 'status' => 400 ] );
	}

	if ( mb_strlen( $value, 'UTF-8' ) > $rules['max'] ) {
		return new \WP_Error( 'cc_p1_invalid', sprintf( '%s must be %d characters or fewer.', $rules['label'], $rules['max'] ), [ 'status' => 400 ] );
	}

	if ( 'email' === $key ) {
		// is_email() on the raw value: it rejects whitespace, whereas sanitize_email() would quietly "fix" it.
		return is_email( $value ) ? true : new \WP_Error( 'cc_p1_invalid', 'Please enter a valid email address.', [ 'status' => 400 ] );
	}

	if ( '' === trim( call_user_func( $rules['sanitize'], $value ) ) ) {
		return new \WP_Error( 'cc_p1_invalid', sprintf( '%s is required.', $rules['label'] ), [ 'status' => 400 ] );
	}

	return true;
}

/**
 * Handle a validated, authorised contact submission.
 *
 * @param \WP_REST_Request $request Request with sanitised params.
 * @return \WP_REST_Response|\WP_Error
 */
function handle_contact( \WP_REST_Request $request ) {
	$name    = (string) $request->get_param( 'name' );
	$email   = (string) $request->get_param( 'email' );
	$message = (string) $request->get_param( 'message' );

	// Record first so a mail failure never loses the message. Failure to record is non-fatal.
	record_ninja_forms_submission( $name, $email, $message );

	if ( ! send_contact_email( $name, $email, $message ) ) {
		// Deliberately vague: never echo mailer internals or the submitted data back.
		return new \WP_Error( 'cc_p1_mail_failed', 'Your message could not be sent. Please try again later.', [ 'status' => 500 ] );
	}

	return new \WP_REST_Response( [ 'ok' => true ], 200 );
}

/**
 * Strip CR/LF so a value is safe in a mail header or subject (header injection).
 *
 * @param string $value Value to flatten.
 */
function one_line( string $value ): string {
	return trim( preg_replace( '/[\r\n]+/', ' ', $value ) );
}

/**
 * Work out who gets the notification.
 *
 * Mirrors the form's own active Email action so P1 submissions land where /contact ones do.
 * Actions addressed to the visitor ({field:email} autoresponders) are skipped because their
 * recipient is not a fixed address. Falls back to the site admin address.
 *
 * @return string[] Recipient addresses.
 */
function contact_recipients(): array {
	$fallback = [ (string) get_option( 'admin_email' ) ];

	if ( ! function_exists( 'Ninja_Forms' ) ) {
		return $fallback;
	}

	try {
		foreach ( \Ninja_Forms()->form( CONTACT_FORM_ID )->get_actions() as $action ) {
			if ( 'email' !== $action->get_setting( 'type' ) || ! $action->get_setting( 'active' ) ) {
				continue;
			}

			$found = [];
			foreach ( explode( ',', (string) $action->get_setting( 'to' ) ) as $candidate ) {
				$candidate = trim( $candidate );
				if ( in_array( $candidate, [ '{wp:admin_email}', '{system:admin_email}' ], true ) ) {
					$candidate = $fallback[0];
				}
				if ( is_email( $candidate ) ) {
					$found[] = $candidate;
				}
			}

			if ( $found ) {
				return array_values( array_unique( $found ) );
			}
		}
	} catch ( \Throwable $e ) {
		// Fall through to the admin address.
		unset( $e );
	}

	return $fallback;
}

/**
 * Email the submission to the site owner.
 *
 * No From header is set: wp_mail() then uses the site's normal sender (WP Mail SMTP / wp_mail_from),
 * which is what SPF/DKIM/DMARC authorise. The visitor goes in Reply-To only.
 *
 * @param string $name    Sanitised name.
 * @param string $email   Validated email.
 * @param string $message Sanitised message.
 */
function send_contact_email( string $name, string $email, string $message ): bool {
	$name  = one_line( $name );
	$email = one_line( $email );

	$body = "Name: {$name}\nEmail: {$email}\n\nMessage:\n{$message}\n\n--\nSent from the communitycode.dev P1 contact form.\n";

	// One notification per authenticated submission, not bulk mail.
	return (bool) wp_mail( // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail
		contact_recipients(),
		sprintf( '[Community + Code] Message from %s', $name ),
		$body,
		[
			'Content-Type: text/plain; charset=UTF-8',
			'Reply-To: ' . $email,
		]
	);
}

/**
 * Store the submission as a Ninja Forms entry on the contact form, so it shows up under
 * Ninja Forms > Submissions next to submissions from the WordPress-rendered form.
 *
 * Uses the same model calls as Ninja Forms' own "Record Submission" action
 * (includes/Actions/Save.php): Ninja_Forms()->form( $id )->sub()->get() + update_field_value() + save().
 * Field ids are resolved from the form's field keys rather than hard-coded. Honours the
 * `ninja_forms_save_submission` filter. Deliberately logs nothing about the submission.
 *
 * @param string $name    Sanitised name.
 * @param string $email   Validated email.
 * @param string $message Sanitised message.
 * @return bool Whether an entry was stored.
 */
function record_ninja_forms_submission( string $name, string $email, string $message ): bool {
	if ( ! function_exists( 'Ninja_Forms' ) || ! apply_filters( 'ninja_forms_save_submission', true, CONTACT_FORM_ID ) ) {
		return false;
	}

	try {
		$ids = [];
		foreach ( \Ninja_Forms()->form( CONTACT_FORM_ID )->get_fields() as $field_id => $field ) {
			$ids[ $field->get_setting( 'key' ) ] = $field_id;
		}

		if ( ! isset( $ids['name'], $ids['email'], $ids['message'] ) ) {
			return false; // Form missing or restructured: skip rather than store a half-empty entry.
		}

		$sub = \Ninja_Forms()->form( CONTACT_FORM_ID )->sub()->get();
		$sub->update_field_value( $ids['name'], $name )
			->update_field_value( $ids['email'], $email )
			->update_field_value( $ids['message'], $message )
			->save();

		return true;
	} catch ( \Throwable $e ) {
		error_log( 'cc-p1-forms: could not record Ninja Forms submission (' . get_class( $e ) . ')' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		return false;
	}
}
