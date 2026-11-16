<?php
/**
 * Plugin Name: おうち買取便 ステージング用Mailpitメール経路
 * Description: staging環境のWordPressメールをローカルMailpitへ固定し、外部メール配送へフォールバックさせません。
 * Version: 1.0.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'wp_get_environment_type' ) || 'staging' !== wp_get_environment_type() || ! function_exists( 'add_action' ) ) {
	return;
}

/**
 * Send every WordPress message on staging through the local-only Mailpit SMTP listener.
 * A connection failure is an email failure; PHPMailer must never fall back to PHP mail().
 */
add_action(
	'phpmailer_init',
	static function ( $mailer ) {
		if ( ! function_exists( 'wp_get_environment_type' ) || 'staging' !== wp_get_environment_type() ) {
			return;
		}
		if ( ! is_object( $mailer ) || ! method_exists( $mailer, 'isSMTP' ) ) {
			throw new RuntimeException( 'ステージングのメール経路を初期化できません。' );
		}

		$mailer->isSMTP();
		$mailer->Host          = '127.0.0.1';
		$mailer->Port          = 1025;
		$mailer->SMTPAuth      = false;
		$mailer->SMTPSecure    = '';
		$mailer->SMTPAutoTLS   = false;
		$mailer->SMTPKeepAlive = false;
		$mailer->SMTPDebug     = 0;
		$mailer->Timeout       = 5;
	},
	PHP_INT_MAX,
	1
);
