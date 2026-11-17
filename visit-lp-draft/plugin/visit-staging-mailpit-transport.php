<?php
/**
 * Plugin Name: おうち買取便 ステージング用メール送信ガード
 * Description: staging環境では出張買取の固定テスト宛先のみSESへ通し、それ以外のメールは拒否します。
 * Version: 1.2.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'wp_get_environment_type' ) || 'staging' !== wp_get_environment_type() || ! function_exists( 'add_action' ) ) {
	return;
}

/** Send one staging test message only to the configured fixed recipient. */
function ouchi_visit_staging_send_test_mail( $recipient, $subject, $body ) {
	if ( 'staging' !== wp_get_environment_type() || ! function_exists( 'wp_mail' ) || ! function_exists( 'has_action' ) ) {
		return false;
	}
	$mail_enabled = defined( 'VISIT_APPLICATION_EMAIL_ENABLED' ) ? VISIT_APPLICATION_EMAIL_ENABLED : getenv( 'VISIT_APPLICATION_EMAIL_ENABLED' );
	if ( '1' !== (string) $mail_enabled ) {
		return false;
	}
	$setting   = defined( 'VISIT_CONFIRMATION_TEST_RECIPIENT' ) ? VISIT_CONFIRMATION_TEST_RECIPIENT : getenv( 'VISIT_CONFIRMATION_TEST_RECIPIENT' );
	$allowed   = sanitize_email( (string) $setting );
	$recipient = sanitize_email( (string) $recipient );
	if ( ! is_email( $allowed ) || ! is_email( $recipient ) || strtolower( $allowed ) !== strtolower( $recipient ) ) {
		return false;
	}
	if ( ! function_exists( 'my_smtp_mailer' ) || false === has_action( 'phpmailer_init', 'my_smtp_mailer' ) || ! empty( $GLOBALS['ouchi_visit_staging_ses_mail_context'] ) ) {
		return false;
	}

	$GLOBALS['ouchi_visit_staging_ses_mail_context'] = array(
		'active'    => true,
		'pending'   => true,
		'in_flight' => false,
		'recipient' => strtolower( $allowed ),
	);
	try {
		return (bool) wp_mail( $allowed, (string) $subject, (string) $body, array( 'Content-Type: text/plain; charset=UTF-8' ) );
	} catch ( Throwable $error ) {
		return false;
	} finally {
		unset( $GLOBALS['ouchi_visit_staging_ses_mail_context'] );
	}
}

/** Reject all staging mail except the single in-memory-authorized helper call. */
add_filter(
	'pre_wp_mail',
	static function ( $pre, $atts ) {
		if ( 'staging' !== wp_get_environment_type() ) {
			return $pre;
		}
		$context = isset( $GLOBALS['ouchi_visit_staging_ses_mail_context'] ) ? $GLOBALS['ouchi_visit_staging_ses_mail_context'] : null;
		if ( ! is_array( $context ) || empty( $context['active'] ) || empty( $context['pending'] ) ) {
			return false;
		}
		$to          = isset( $atts['to'] ) && is_scalar( $atts['to'] ) ? strtolower( trim( (string) $atts['to'] ) ) : '';
		$headers     = isset( $atts['headers'] ) ? $atts['headers'] : array();
		$header_text = is_array( $headers ) ? implode( "\n", array_map( 'strval', $headers ) ) : (string) $headers;
		if ( $to !== $context['recipient'] || preg_match( '/^\s*(?:cc|bcc)\s*:/im', $header_text ) ) {
			return false;
		}
		$GLOBALS['ouchi_visit_staging_ses_mail_context']['pending']   = false;
		$GLOBALS['ouchi_visit_staging_ses_mail_context']['in_flight'] = true;
		return null;
	},
	PHP_INT_MAX,
	2
);

add_filter(
	'wp_mail',
	static function ( $args ) {
		if ( 'staging' !== wp_get_environment_type() ) {
			return $args;
		}
		$context = isset( $GLOBALS['ouchi_visit_staging_ses_mail_context'] ) ? $GLOBALS['ouchi_visit_staging_ses_mail_context'] : null;
		if ( ! is_array( $context ) || empty( $context['active'] ) || empty( $context['in_flight'] ) ) {
			throw new RuntimeException( 'ステージングではこのメールを送信できません。' );
		}
		$to          = isset( $args['to'] ) && is_scalar( $args['to'] ) ? strtolower( trim( (string) $args['to'] ) ) : '';
		$headers     = isset( $args['headers'] ) ? $args['headers'] : array();
		$header_text = is_array( $headers ) ? implode( "\n", array_map( 'strval', $headers ) ) : (string) $headers;
		if ( $to !== $context['recipient'] || ! empty( $args['cc'] ) || ! empty( $args['bcc'] ) || preg_match( '/^\s*(?:cc|bcc)\s*:/im', $header_text ) ) {
			throw new RuntimeException( 'ステージングメールの宛先を確認できません。' );
		}
		return $args;
	},
	PHP_INT_MAX
);

/** Keep the existing parent-theme SES configuration and fail closed on recipients. */
add_action(
	'phpmailer_init',
	static function ( $mailer ) {
		if ( 'staging' !== wp_get_environment_type() ) {
			return;
		}
		$context = isset( $GLOBALS['ouchi_visit_staging_ses_mail_context'] ) ? $GLOBALS['ouchi_visit_staging_ses_mail_context'] : null;
		if ( ! is_array( $context ) || empty( $context['active'] ) || empty( $context['in_flight'] ) || ! is_object( $mailer ) ) {
			throw new RuntimeException( 'ステージングではこのメールを送信できません。' );
		}
		if ( ! method_exists( $mailer, 'getToAddresses' ) || ! method_exists( $mailer, 'getCcAddresses' ) || ! method_exists( $mailer, 'getBccAddresses' ) ) {
			throw new RuntimeException( 'メールの宛先を確認できません。' );
		}
		$to = $mailer->getToAddresses();
		if ( 1 !== count( $to ) || strtolower( (string) $to[0][0] ) !== $context['recipient'] || $mailer->getCcAddresses() || $mailer->getBccAddresses() ) {
			throw new RuntimeException( 'ステージングメールの宛先を確認できません。' );
		}

		$ses_host = is_string( $mailer->Host ) && 1 === preg_match( '/^email-smtp\.[a-z0-9-]+\.amazonaws\.com$/i', $mailer->Host );
		$ses_port = (int) $mailer->Port;
		$ses_tls  = strtolower( (string) $mailer->SMTPSecure );
		$ses_transport = ( 465 === $ses_port && in_array( $ses_tls, array( 'ssl', 'smtps' ), true ) )
			|| ( 587 === $ses_port && 'tls' === $ses_tls );
		$ses_ready = isset( $mailer->Mailer, $mailer->Port, $mailer->SMTPAuth, $mailer->SMTPSecure, $mailer->Username, $mailer->Password )
			&& 'smtp' === strtolower( (string) $mailer->Mailer )
			&& $ses_host
			&& $ses_transport
			&& true === $mailer->SMTPAuth
			&& '' !== (string) $mailer->Username
			&& '' !== (string) $mailer->Password;
		if ( ! $ses_ready ) {
			throw new RuntimeException( 'Amazon SESのステージング送信設定を確認できません。' );
		}
	},
	PHP_INT_MAX,
	1
);
