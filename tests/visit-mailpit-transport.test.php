<?php
define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['visit_staging_environment'] = 'staging';
$GLOBALS['visit_staging_actions']     = array();
$GLOBALS['visit_staging_filters']     = array();
$GLOBALS['visit_staging_sent']        = array();
$GLOBALS['visit_staging_inject_cc']   = false;
putenv( 'VISIT_CONFIRMATION_TEST_RECIPIENT=ses-test@example.com' );
putenv( 'VISIT_APPLICATION_EMAIL_ENABLED=1' );

function wp_get_environment_type() {
	return $GLOBALS['visit_staging_environment'];
}

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['visit_staging_actions'][ $hook ][ $priority ][] = array( $callback, $accepted_args );
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['visit_staging_filters'][ $hook ][ $priority ][] = array( $callback, $accepted_args );
}

function has_action( $hook, $callback = false ) {
	foreach ( isset( $GLOBALS['visit_staging_actions'][ $hook ] ) ? $GLOBALS['visit_staging_actions'][ $hook ] : array() as $priority => $callbacks ) {
		foreach ( $callbacks as $registered ) {
			if ( $registered[0] === $callback ) {
				return $priority;
			}
		}
	}
	return false;
}

function sanitize_email( $email ) {
	return strtolower( trim( (string) $email ) );
}

function is_email( $email ) {
	return 1 === preg_match( '/^[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}$/i', (string) $email );
}

function apply_filters( $hook, $value, ...$args ) {
	$filters = isset( $GLOBALS['visit_staging_filters'][ $hook ] ) ? $GLOBALS['visit_staging_filters'][ $hook ] : array();
	ksort( $filters );
	foreach ( $filters as $callbacks ) {
		foreach ( $callbacks as $registered ) {
			$call_args = array_slice( array_merge( array( $value ), $args ), 0, $registered[1] );
			$value     = call_user_func_array( $registered[0], $call_args );
		}
	}
	return $value;
}

function my_smtp_mailer( $mailer ) {
	$mailer->Mailer     = 'smtp';
	$mailer->Host       = 'email-smtp.us-east-1.amazonaws.com';
	$mailer->Port       = 587;
	$mailer->SMTPAuth   = true;
	$mailer->SMTPSecure = 'tls';
	$mailer->Username   = 'test-user';
	$mailer->Password   = 'test-password';
}

add_action( 'phpmailer_init', 'my_smtp_mailer', 10, 1 );

class Visit_Staging_Mock_Mailer {
	public $Mailer;
	public $Host;
	public $Port;
	public $SMTPAuth;
	public $SMTPSecure;
	public $Username;
	public $Password;
	private $to = array();
	private $cc = array();
	private $bcc = array();
	public function __construct( $args ) {
		$to = is_array( $args['to'] ) ? $args['to'] : array( $args['to'] );
		foreach ( $to as $address ) { $this->to[] = array( trim( (string) $address ), '' ); }
		$headers = isset( $args['headers'] ) ? ( is_array( $args['headers'] ) ? implode( "\n", $args['headers'] ) : (string) $args['headers'] ) : '';
		if ( preg_match_all( '/^Cc:\\s*([^\\r\\n]+)/im', $headers, $matches ) ) { foreach ( $matches[1] as $address ) { $this->cc[] = array( trim( $address ), '' ); } }
		if ( preg_match_all( '/^Bcc:\\s*([^\\r\\n]+)/im', $headers, $matches ) ) { foreach ( $matches[1] as $address ) { $this->bcc[] = array( trim( $address ), '' ); } }
	}
	public function getToAddresses() { return $this->to; }
	public function getCcAddresses() { return $this->cc; }
	public function getBccAddresses() { return $this->bcc; }
}

function wp_mail( $to, $subject, $message, $headers = array() ) {
	$atts = array( 'to' => $to, 'subject' => $subject, 'message' => $message, 'headers' => $headers, 'attachments' => array() );
	if ( $GLOBALS['visit_staging_inject_cc'] ) { $atts['headers'][] = 'Cc: other@example.com'; }
	$atts = apply_filters( 'wp_mail', $atts );
	$pre  = apply_filters( 'pre_wp_mail', null, $atts );
	if ( null !== $pre ) { return $pre; }
	$mailer = new Visit_Staging_Mock_Mailer( $atts );
	$actions = isset( $GLOBALS['visit_staging_actions']['phpmailer_init'] ) ? $GLOBALS['visit_staging_actions']['phpmailer_init'] : array();
	ksort( $actions );
	foreach ( $actions as $callbacks ) {
		foreach ( $callbacks as $registered ) { call_user_func( $registered[0], $mailer ); }
	}
	$GLOBALS['visit_staging_sent'][] = $atts;
	return true;
}

require dirname( __DIR__ ) . '/visit-lp-draft/plugin/visit-staging-mailpit-transport.php';

$assert = static function ( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$assert( 10 === has_action( 'phpmailer_init', 'my_smtp_mailer' ), 'the parent SES mailer must stay registered at priority 10' );
$assert( isset( $GLOBALS['visit_staging_actions']['phpmailer_init'][ PHP_INT_MAX ] ), 'the staging recipient guard must run after the parent SES setup' );
$assert( false === wp_mail( 'other@example.com', 'anything', 'body' ), 'unrelated staging mail must be rejected' );
$assert( false === wp_mail( 'ses-test@example.com', 'subject marker cannot authorize', 'body' ), 'a subject alone must never authorize a send' );
$assert( 0 === count( $GLOBALS['visit_staging_sent'] ), 'rejected mail must not reach PHPMailer' );
$assert( false === ouchi_visit_staging_send_test_mail( 'other@example.com', 'confirmation', 'body' ), 'the helper must reject a non-fixed recipient' );
$assert( true === ouchi_visit_staging_send_test_mail( 'ses-test@example.com', 'confirmation', 'body' ), 'the scoped fixed-recipient send should use the existing SES setup' );
$assert( 1 === count( $GLOBALS['visit_staging_sent'] ), 'only the authorized helper call should reach PHPMailer' );
$assert( 'ses-test@example.com' === $GLOBALS['visit_staging_sent'][0]['to'], 'To must contain only the fixed test address' );
$assert( ! isset( $GLOBALS['visit_staging_sent'][0]['cc'] ) && ! isset( $GLOBALS['visit_staging_sent'][0]['bcc'] ), 'Cc and Bcc must be absent' );

$GLOBALS['visit_staging_inject_cc'] = true;
$assert( false === ouchi_visit_staging_send_test_mail( 'ses-test@example.com', 'confirmation', 'body' ), 'a Cc added by another filter must be rejected before send' );
$GLOBALS['visit_staging_inject_cc'] = false;
$assert( 1 === count( $GLOBALS['visit_staging_sent'] ), 'recipient rejection must prevent the second send' );
putenv( 'VISIT_APPLICATION_EMAIL_ENABLED=0' );
$assert( false === ouchi_visit_staging_send_test_mail( 'ses-test@example.com', 'confirmation', 'body' ), 'the helper must stay disabled when staging mail is disabled' );
putenv( 'VISIT_APPLICATION_EMAIL_ENABLED' );

$source = file_get_contents( dirname( __DIR__ ) . '/visit-lp-draft/plugin/visit-staging-mailpit-transport.php' );
$assert( false === strpos( $source, '127.0.0.1' ), 'the staging mail guard must not use Mailpit' );
$assert( false === strpos( $source, 'isMail(' ), 'the staging mail guard must not enable PHP mail() fallback' );

echo "Visit staging SES mail guard runtime checks passed.\n";
