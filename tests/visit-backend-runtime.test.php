<?php
define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['visit_test_environment'] = 'staging';
$GLOBALS['visit_test_mail_filters'] = array();
$GLOBALS['visit_test_mail_calls'] = array();
$GLOBALS['visit_test_mail_result'] = true;
$GLOBALS['visit_test_registered_filters'] = array();

class WP_REST_Request {
	private $method;
	private $route;
	public function __construct( $method, $route ) { $this->method = $method; $this->route = $route; }
	public function get_method() { return $this->method; }
	public function get_route() { return $this->route; }
}

class WP_REST_Response {
	private $data;
	private $headers;
	public function __construct( $data, $headers = array() ) { $this->data = $data; $this->headers = $headers; }
	public function get_data() { return $this->data; }
	public function get_headers() { return $this->headers; }
}

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {}
function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['visit_test_registered_filters'][ $hook ][] = $callback;
	if ( 'wp_mail' === $hook ) {
		$GLOBALS['visit_test_mail_filters'][ $priority ][] = $callback;
	}
}
function remove_filter( $hook, $callback, $priority = 10 ) {
	if ( 'wp_mail' !== $hook || empty( $GLOBALS['visit_test_mail_filters'][ $priority ] ) ) {
		return;
	}
	$GLOBALS['visit_test_mail_filters'][ $priority ] = array_values( array_filter(
		$GLOBALS['visit_test_mail_filters'][ $priority ],
		static function ( $registered ) use ( $callback ) { return $registered !== $callback; }
	) );
}
function register_deactivation_hook( $file, $callback ) {}
function wp_get_environment_type() { return $GLOBALS['visit_test_environment']; }
function sanitize_email( $email ) { return filter_var( (string) $email, FILTER_SANITIZE_EMAIL ); }
function is_email( $email ) { return false !== filter_var( (string) $email, FILTER_VALIDATE_EMAIL ); }
function wp_mail( $to, $subject, $message, $headers = '', $attachments = array() ) {
	$args = compact( 'to', 'subject', 'message', 'headers', 'attachments' );
	ksort( $GLOBALS['visit_test_mail_filters'] );
	foreach ( $GLOBALS['visit_test_mail_filters'] as $callbacks ) {
		foreach ( $callbacks as $callback ) {
			$args = call_user_func( $callback, $args );
		}
	}
	$GLOBALS['visit_test_mail_calls'][] = $args;
	return $GLOBALS['visit_test_mail_result'];
}
function ouchi_visit_staging_send_test_mail( $recipient, $subject, $body ) {
	return wp_mail( array( $recipient ), $subject, $body, array( 'Content-Type: text/plain; charset=UTF-8' ), array() );
}

require dirname( __DIR__ ) . '/visit-lp-draft/plugin/visit-application-backend.php';

$assert = static function ( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};
$instance = ( new ReflectionClass( 'Ouchi_Visit_Application_Backend' ) )->newInstanceWithoutConstructor();
$booted_instance = Ouchi_Visit_Application_Backend::boot();
$serve_confirmation_page = new ReflectionMethod( 'Ouchi_Visit_Application_Backend', 'serve_confirmation_page_response' );
$serve_confirmation_page->setAccessible( true );
$confirmation_filter_registered = false;
foreach ( $GLOBALS['visit_test_registered_filters']['rest_pre_serve_request'] ?? array() as $registered_callback ) {
	if ( is_array( $registered_callback ) && isset( $registered_callback[1] ) && 'serve_confirmation_page_response' === $registered_callback[1] ) {
		$confirmation_filter_registered = true;
		break;
	}
}
$assert( $confirmation_filter_registered, 'confirmation HTML response filter should be registered' );
$confirmation_html = '<!doctype html><html lang="ja"><main>confirmation page</main></html>';
$confirmation_response = new WP_REST_Response( $confirmation_html, array( 'Content-Type' => 'text/html; charset=UTF-8' ) );
$confirmation_request = new WP_REST_Request( 'GET', '/visit/v1/confirm' );
ob_start();
$confirmation_served = $serve_confirmation_page->invoke( $booted_instance, false, $confirmation_response, $confirmation_request, null );
$confirmation_output = ob_get_clean();
$assert( true === $confirmation_served, 'confirmation HTML should be served directly' );
$assert( $confirmation_html === $confirmation_output, 'confirmation HTML should not be JSON-encoded' );
$json_request = new WP_REST_Request( 'POST', '/visit/v1/confirm' );
$json_response = new WP_REST_Response( array( 'status' => 'complete' ), array( 'Content-Type' => 'application/json; charset=UTF-8' ) );
ob_start();
$json_served = $serve_confirmation_page->invoke( $booted_instance, false, $json_response, $json_request, null );
$json_output = ob_get_clean();
$assert( false === $json_served && '' === $json_output, 'confirmation POST JSON response should remain under the normal REST serializer' );
$send = new ReflectionMethod( 'Ouchi_Visit_Application_Backend', 'send_staging_test_mail' );
$send->setAccessible( true );
$test_recipient = 'visit-qa' . '@' . 'example.invalid';
putenv( 'VISIT_CONFIRMATION_TEST_RECIPIENT=' . $test_recipient );

$GLOBALS['visit_test_environment'] = 'staging';
$GLOBALS['visit_test_mail_result'] = true;
$assert( true === $send->invoke( $instance, 'mock success', 'synthetic test message' ), 'mocked mail success should be returned' );
$sent_args = end( $GLOBALS['visit_test_mail_calls'] );
$assert( array( $test_recipient ) === $sent_args['to'], 'recipient must be forced to the staging test address' );
$assert( array( 'Content-Type: text/plain; charset=UTF-8' ) === $sent_args['headers'], 'headers must not carry CC or BCC recipients' );
$assert( array() === $sent_args['attachments'], 'test mail must not carry attachments' );

$GLOBALS['visit_test_mail_result'] = false;
$assert( false === $send->invoke( $instance, 'mock failure', 'synthetic test message' ), 'mocked mail failure should be returned' );
$calls_before_non_staging = count( $GLOBALS['visit_test_mail_calls'] );
$GLOBALS['visit_test_environment'] = 'production';
$assert( false === $send->invoke( $instance, 'must not send', 'synthetic test message' ), 'mail must be disabled outside staging' );
$assert( $calls_before_non_staging === count( $GLOBALS['visit_test_mail_calls'] ), 'non-staging call must not invoke wp_mail' );

$GLOBALS['visit_test_environment'] = 'staging';
putenv( 'PAYOUT_ENC_KEY_B64=' . base64_encode( random_bytes( 32 ) ) );
$encrypt = new ReflectionMethod( 'Ouchi_Visit_Application_Backend', 'encrypt' );
$encrypt->setAccessible( true );
list( $ciphertext, $iv, $tag ) = $encrypt->invoke( $instance, 'SYNTHETIC-ACCOUNT-1234567' );
$decoded_iv = base64_decode( $iv, true );
$decoded_tag = base64_decode( $tag, true );
$assert( 12 === strlen( $decoded_iv ), 'AES-GCM IV should be 12 bytes' );
$assert( 16 === strlen( $decoded_tag ), 'AES-GCM tag should be 16 bytes' );
$plaintext = openssl_decrypt( base64_decode( $ciphertext, true ), 'aes-256-gcm', base64_decode( getenv( 'PAYOUT_ENC_KEY_B64' ), true ), OPENSSL_RAW_DATA, $decoded_iv, $decoded_tag );
$assert( 'SYNTHETIC-ACCOUNT-1234567' === $plaintext, 'AES-GCM ciphertext should round-trip with the test key' );

$encrypt_private_token = new ReflectionMethod( 'Ouchi_Visit_Application_Backend', 'encrypt_private_token' );
$encrypt_private_token->setAccessible( true );
$decrypt_private_token = new ReflectionMethod( 'Ouchi_Visit_Application_Backend', 'decrypt_private_token' );
$decrypt_private_token->setAccessible( true );
$synthetic_token = bin2hex( random_bytes( 32 ) );
list( $private_ciphertext, $private_iv, $private_tag ) = $encrypt_private_token->invoke( $instance, $synthetic_token );
$assert( 32 === strlen( $private_ciphertext ), 'private token ciphertext should fit the BINARY(32) poll-token column' );
$assert( 12 === strlen( $private_iv ) && 16 === strlen( $private_tag ), 'private token AES-GCM IV and tag lengths should match the schema' );
$assert( $synthetic_token === $decrypt_private_token->invoke( $instance, $private_ciphertext, $private_iv, $private_tag ), 'private token should round-trip to its 64-character hex representation' );

putenv( 'VISIT_CONFIRMATION_TEST_RECIPIENT' );
putenv( 'PAYOUT_ENC_KEY_B64' );
echo "Visit backend runtime mock checks passed.\n";
