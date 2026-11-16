<?php
define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['visit_mailpit_environment'] = 'staging';
$GLOBALS['visit_mailpit_actions']     = array();

function wp_get_environment_type() {
	return $GLOBALS['visit_mailpit_environment'];
}

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['visit_mailpit_actions'][ $hook ][ $priority ][] = array( $callback, $accepted_args );
}

require dirname( __DIR__ ) . '/visit-lp-draft/plugin/visit-staging-mailpit-transport.php';

$assert = static function ( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$assert( isset( $GLOBALS['visit_mailpit_actions']['phpmailer_init'][ PHP_INT_MAX ] ), 'staging must register the final PHPMailer transport hook' );
$hooks = $GLOBALS['visit_mailpit_actions']['phpmailer_init'][ PHP_INT_MAX ];
$assert( 1 === count( $hooks ), 'only one final Mailpit transport hook should be registered' );

$mailer = new class() {
	public $transport = 'mail';
	public $Host;
	public $Port;
	public $SMTPAuth;
	public $SMTPSecure;
	public $SMTPAutoTLS;
	public $SMTPKeepAlive;
	public $SMTPDebug;
	public $Timeout;
	public function isSMTP() { $this->transport = 'smtp'; }
};
call_user_func( $hooks[0][0], $mailer );
$assert( 'smtp' === $mailer->transport, 'PHPMailer must use SMTP instead of PHP mail()' );
$assert( '127.0.0.1' === $mailer->Host && 1025 === $mailer->Port, 'SMTP must target the loopback Mailpit listener' );
$assert( false === $mailer->SMTPAuth && '' === $mailer->SMTPSecure && false === $mailer->SMTPAutoTLS, 'Mailpit loopback transport must not negotiate external SMTP settings' );
$assert( false === $mailer->SMTPKeepAlive && 0 === $mailer->SMTPDebug && 5 === $mailer->Timeout, 'SMTP settings must be short-lived and silent' );

$production_mailer = clone $mailer;
$production_mailer->transport = 'mail';
$GLOBALS['visit_mailpit_environment'] = 'production';
call_user_func( $hooks[0][0], $production_mailer );
$assert( 'mail' === $production_mailer->transport, 'the Mailpit transport callback must not alter a non-staging environment' );

$source = file_get_contents( dirname( __DIR__ ) . '/visit-lp-draft/plugin/visit-staging-mailpit-transport.php' );
$assert( false !== strpos( $source, "'127.0.0.1'" ), 'the SMTP host must remain loopback-only' );
$assert( false === strpos( $source, 'isMail(' ), 'the staging transport must not enable the PHP mail transport' );

echo "Visit Mailpit transport runtime checks passed.\n";
