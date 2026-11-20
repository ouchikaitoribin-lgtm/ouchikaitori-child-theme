<?php
define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['visit_test_environment'] = 'staging';
$GLOBALS['visit_test_mail_filters'] = array();
$GLOBALS['visit_test_mail_calls'] = array();
$GLOBALS['visit_test_mail_result'] = true;
$GLOBALS['visit_test_registered_filters'] = array();
$GLOBALS['visit_test_parent_mailer_registered'] = false;

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

class VisitTestPDOStatement extends PDOStatement {
	private $owner;
	public function __construct( $owner ) { $this->owner = $owner; }
	public function execute( ?array $params = null ): bool {
		$this->owner->executed_parameters = $params;
		return true;
	}
}

class VisitTestPDO extends PDO {
	public $prepared_query;
	public $executed_parameters;
	public function __construct() {}
	public function prepare( string $query, array $options = array() ): PDOStatement|false {
		$this->prepared_query = $query;
		return new VisitTestPDOStatement( $this );
	}
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
function has_action( $hook, $callback = false ) {
	return 'phpmailer_init' === $hook && 'my_smtp_mailer' === $callback && $GLOBALS['visit_test_parent_mailer_registered'];
}
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
$enqueue_bank_check = new ReflectionMethod( 'Ouchi_Visit_Application_Backend', 'enqueue_bank_account_check' );
$enqueue_bank_check->setAccessible( true );
$task_queue_pdo = new VisitTestPDO();
$synthetic_public_token = str_repeat( 'b', 64 );
$enqueue_bank_check->invoke( $instance, $task_queue_pdo, $synthetic_public_token );
$assert( false !== strpos( $task_queue_pdo->prepared_query, 'INSERT INTO task_queue (public_token, task_type, status)' ), 'bank account check should be inserted into task_queue' );
$assert( array( ':public_token' => $synthetic_public_token, ':task_type' => 'bank_account_check', ':status' => 'pending' ) === $task_queue_pdo->executed_parameters, 'bank account task should use the application public token and pending status' );
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

$GLOBALS['visit_test_environment'] = 'production';
$GLOBALS['visit_test_parent_mailer_registered'] = true;
putenv( 'VISIT_APPLICATION_API_ENABLED=1' );
putenv( 'VISIT_APPLICATION_EMAIL_ENABLED=1' );
putenv( 'VISIT_PRODUCTION_APPLICATION_API_ENABLED=0' );
putenv( 'VISIT_PRODUCTION_APPLICATION_EMAIL_ENABLED=0' );
$send_application_mail = new ReflectionMethod( 'Ouchi_Visit_Application_Backend', 'send_application_mail' );
$send_application_mail->setAccessible( true );
$production_recipient = 'synthetic-customer@example.invalid';
$calls_before_staging_flags_only = count( $GLOBALS['visit_test_mail_calls'] );
$assert( false === $send_application_mail->invoke( $instance, $production_recipient, 'must not send', 'synthetic body' ), 'staging flags must not enable production delivery' );
$assert( $calls_before_staging_flags_only === count( $GLOBALS['visit_test_mail_calls'] ), 'staging flags alone must not call wp_mail in production' );
putenv( 'VISIT_PRODUCTION_APPLICATION_API_ENABLED=1' );
putenv( 'VISIT_PRODUCTION_APPLICATION_EMAIL_ENABLED=1' );
putenv( 'PAYOUT_ENC_KEY_B64=' . base64_encode( random_bytes( 32 ) ) );
$assert( true === $send_application_mail->invoke( $instance, $production_recipient, 'production subject', 'synthetic production body' ), 'production mail should use mocked wp_mail only when both production flags and parent mailer are present' );
$sent_args = end( $GLOBALS['visit_test_mail_calls'] );
$assert( array( $production_recipient ) === $sent_args['to'], 'production mail should use the supplied validated application email' );
$assert( array( 'Content-Type: text/plain; charset=UTF-8' ) === $sent_args['headers'], 'production mail should not add CC or BCC headers' );
$GLOBALS['visit_test_parent_mailer_registered'] = false;
$calls_before_missing_parent_mailer = count( $GLOBALS['visit_test_mail_calls'] );
$assert( false === $send_application_mail->invoke( $instance, $production_recipient, 'must not send', 'synthetic body' ), 'production mail must stop if the existing parent SMTP callback is unavailable' );
$assert( $calls_before_missing_parent_mailer === count( $GLOBALS['visit_test_mail_calls'] ), 'missing parent SMTP callback must not call wp_mail' );
$GLOBALS['visit_test_parent_mailer_registered'] = true;
$GLOBALS['visit_test_environment'] = 'local';
$calls_before_local = count( $GLOBALS['visit_test_mail_calls'] );
$assert( false === $send_application_mail->invoke( $instance, $production_recipient, 'must not send locally', 'synthetic body' ), 'local environments must never use production delivery' );
$assert( $calls_before_local === count( $GLOBALS['visit_test_mail_calls'] ), 'local environment must not call wp_mail' );

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

$confirmation_code = new ReflectionMethod( 'Ouchi_Visit_Application_Backend', 'confirmation_code' );
$confirmation_code->setAccessible( true );
$code_token = bin2hex( random_bytes( 32 ) );
$derived_code = $confirmation_code->invoke( $instance, $code_token );
$assert( preg_match( '/^[0-9]{4}$/', $derived_code ), 'confirmation code should contain exactly four digits, including possible leading zeroes' );
$assert( $derived_code === $confirmation_code->invoke( $instance, $code_token ), 'confirmation code should remain stable for encrypted-outbox retries' );

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
putenv( 'VISIT_PRODUCTION_APPLICATION_API_ENABLED' );
putenv( 'VISIT_PRODUCTION_APPLICATION_EMAIL_ENABLED' );
putenv( 'VISIT_APPLICATION_API_ENABLED' );
putenv( 'VISIT_APPLICATION_EMAIL_ENABLED' );
echo "Visit backend runtime mock checks passed.\n";
