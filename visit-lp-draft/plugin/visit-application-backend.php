<?php
/**
 * Plugin Name: おうち買取便 出張買取申込バックエンド（ステージング専用）
 * Description: 出張買取の仮申込、確認リンク、確認後のDB登録を行います。ステージング環境以外では受付を停止します。
 * Version: 0.2.0
 * Requires at least: 5.6
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Ouchi_Visit_Application_Backend {
	const API_NAMESPACE = 'visit/v1';
	const DRAFT_TTL = 18000; // 添付された宅配申込ソースのフロー期限（5時間）に合わせる。
	const RATE_LIMIT = 8;
	const RATE_WINDOW = 3600;
	const COLLECTION_LETTERS = 'ABCD EFGH JKMN PRST UVWX';
	const COLLECTION_DIGITS = '23456789';

	/** @var self|null */
	private static $instance = null;
	private $readiness_checked = false;
	private $readiness_result = false;

	public static function boot() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function activate() {
		if ( ! wp_next_scheduled( 'ouchi_visit_cleanup_expired_drafts' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'ouchi_visit_cleanup_expired_drafts' );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'ouchi_visit_cleanup_expired_drafts' );
	}

	private function __construct() {
		add_action( 'init', array( $this, 'ensure_cleanup_schedule' ), 20 );
		add_action( 'ouchi_visit_cleanup_expired_drafts', array( $this, 'cleanup_expired_drafts' ) );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_filter( 'visit_application_submit_endpoint', array( $this, 'submit_endpoint' ) );
		add_filter( 'visit_application_status_endpoint', array( $this, 'status_endpoint' ) );
		add_filter( 'visit_application_availability_endpoint', array( $this, 'availability_endpoint' ) );
		add_filter( 'visit_pending_application_for_public_token', array( $this, 'pending_application' ), 10, 2 );
		add_action( 'admin_init', array( $this, 'register_capacity_setting' ) );
		add_action( 'admin_menu', array( $this, 'register_capacity_page' ) );
	}

	public function ensure_cleanup_schedule() {
		if ( $this->enabled() && ! wp_next_scheduled( 'ouchi_visit_cleanup_expired_drafts' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'ouchi_visit_cleanup_expired_drafts' );
		}
	}

	public function cleanup_expired_drafts() {
		if ( ! $this->enabled() ) { return; }
		try {
			$pdo = $this->db();
			$pdo->exec( "DELETE FROM visit_application_drafts WHERE draft_status <> 'confirmed' AND expires_at <= UTC_TIMESTAMP()" );
		} catch ( Throwable $e ) {
			error_log( '[visit-application] expired draft cleanup failed' );
		}
	}

	public function submit_endpoint( $endpoint ) {
		return $this->database_ready() ? rest_url( self::API_NAMESPACE . '/application' ) : '';
	}

	public function status_endpoint( $endpoint ) {
		return $this->database_ready() ? rest_url( self::API_NAMESPACE . '/status' ) : '';
	}

	public function availability_endpoint( $endpoint ) {
		return $this->database_ready() ? rest_url( self::API_NAMESPACE . '/availability' ) : '';
	}

	private function enabled() {
		$api_enabled = defined( 'VISIT_APPLICATION_API_ENABLED' ) ? VISIT_APPLICATION_API_ENABLED : getenv( 'VISIT_APPLICATION_API_ENABLED' );
		$mail_enabled = defined( 'VISIT_APPLICATION_EMAIL_ENABLED' ) ? VISIT_APPLICATION_EMAIL_ENABLED : getenv( 'VISIT_APPLICATION_EMAIL_ENABLED' );
		$key_b64 = defined( 'PAYOUT_ENC_KEY_B64' ) ? PAYOUT_ENC_KEY_B64 : getenv( 'PAYOUT_ENC_KEY_B64' );
		$key = base64_decode( (string) $key_b64, true );
		$test_recipient = defined( 'VISIT_CONFIRMATION_TEST_RECIPIENT' ) ? VISIT_CONFIRMATION_TEST_RECIPIENT : getenv( 'VISIT_CONFIRMATION_TEST_RECIPIENT' );
		return '1' === (string) $api_enabled
			&& '1' === (string) $mail_enabled
			&& function_exists( 'wp_get_environment_type' )
			&& 'staging' === wp_get_environment_type()
			&& extension_loaded( 'pdo_mysql' )
			&& function_exists( 'openssl_encrypt' )
			&& function_exists( 'mb_convert_kana' )
			&& '' !== (string) $test_recipient
			&& false !== $key && 32 === strlen( $key );
	}

	public static function is_enabled() {
		$instance = self::boot();
		return $instance->database_ready();
	}

	private function database_ready() {
		if ( $this->readiness_checked ) {
			return $this->readiness_result;
		}
		$this->readiness_checked = true;
		if ( ! $this->enabled() || $this->slot_capacity() < 1 || empty( $this->service_weekdays() ) ) {
			return false;
		}
		try {
			$this->check_schema( $this->db() );
			$this->readiness_result = true;
			return $this->readiness_result;
		} catch ( Throwable $e ) {
			return false;
		}
	}

	public function register_capacity_setting() {
		register_setting( 'visit_slot_capacity_settings', 'visit_booking_slot_capacity', array(
			'type'              => 'integer',
			'sanitize_callback' => array( $this, 'sanitize_slot_capacity' ),
			'default'           => 0,
		) );
		register_setting( 'visit_slot_capacity_settings', 'visit_booking_service_weekdays', array(
			'type'              => 'array',
			'sanitize_callback' => array( $this, 'sanitize_service_weekdays' ),
			'default'           => array(),
		) );
	}

	public function sanitize_slot_capacity( $value ) {
		if ( ! is_scalar( $value ) ) {
			return 0;
		}
		$capacity = filter_var( $value, FILTER_VALIDATE_INT );
		return false === $capacity || $capacity < 1 ? 0 : (int) $capacity;
	}

	private function slot_capacity() {
		return max( 0, (int) get_option( 'visit_booking_slot_capacity', 0 ) );
	}

	public function sanitize_service_weekdays( $value ) {
		$value = is_array( $value ) ? $value : array();
		$days = array();
		foreach ( $value as $day ) {
			if ( ! is_scalar( $day ) || ! preg_match( '/^[0-6]$/', (string) $day ) ) {
				continue;
			}
			$days[] = (int) $day;
		}
		$days = array_values( array_unique( $days ) );
		sort( $days );
		return $days;
	}

	private function service_weekdays() {
		$value = get_option( 'visit_booking_service_weekdays', array() );
		return $this->sanitize_service_weekdays( $value );
	}

	private function is_service_date( $date ) {
		$earliest = function_exists( 'visit_booking_get_earliest_date' ) ? visit_booking_get_earliest_date() : ( new DateTimeImmutable( 'tomorrow', wp_timezone() ) )->format( 'Y-m-d' );
		$parsed = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $date, wp_timezone() );
		return $parsed && $parsed->format( 'Y-m-d' ) === $date && $date >= $earliest && in_array( (int) $parsed->format( 'w' ), $this->service_weekdays(), true );
	}

	public function register_capacity_page() {
		add_options_page( '出張買取の予約枠上限', '出張買取の予約枠上限', 'manage_options', 'visit-slot-capacity', array( $this, 'render_capacity_page' ) );
	}

	public function render_capacity_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1>出張買取の予約枠上限</h1>
			<p>全対応エリア共通で、各時間帯に受け付ける申込数と受付曜日を設定します。上限未設定または受付曜日が未選択の場合、出張買取APIと空き枠表示は無効です。</p>
			<form action="options.php" method="post">
				<?php settings_fields( 'visit_slot_capacity_settings' ); ?>
				<label for="visit-booking-slot-capacity">各時間帯の受付上限</label>
				<input id="visit-booking-slot-capacity" name="visit_booking_slot_capacity" type="number" min="1" step="1" value="<?php echo esc_attr( (string) $this->slot_capacity() ); ?>">
				<fieldset>
					<legend>受付曜日</legend>
					<input type="hidden" name="visit_booking_service_weekdays[]" value="">
					<?php foreach ( array( 0 => '日', 1 => '月', 2 => '火', 3 => '水', 4 => '木', 5 => '金', 6 => '土' ) as $day_number => $day_label ) : ?>
						<label><input type="checkbox" name="visit_booking_service_weekdays[]" value="<?php echo esc_attr( (string) $day_number ); ?>" <?php checked( in_array( $day_number, $this->service_weekdays(), true ) ); ?>><?php echo esc_html( $day_label ); ?></label>
					<?php endforeach; ?>
				</fieldset>
				<p class="description">単一時間帯を選択した申込は、メール確認後に空きがあれば1枠を確保します。複数時間帯を選択した申込は希望として記録し、枠は確保しません。</p>
				<?php submit_button( '設定を保存' ); ?>
			</form>
		</div>
		<?php
	}

	public function register_routes() {
		if ( ! $this->database_ready() ) {
			return;
		}
		register_rest_route( self::API_NAMESPACE, '/availability', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'availability' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( self::API_NAMESPACE, '/application', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'submit' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( self::API_NAMESPACE, '/status', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'status' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( self::API_NAMESPACE, '/confirm', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'confirmation_page' ),
				'permission_callback' => '__return_true',
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'confirm' ),
				'permission_callback' => '__return_true',
			),
		) );
	}

	private function slot_range_utc( $date, $slot ) {
		if ( ! preg_match( '/^(\d{2}:\d{2})〜(\d{2}:\d{2})$/u', (string) $slot, $match ) ) {
			throw new DomainException( '回収希望時間帯を選び直してください。' );
		}
		$start = DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $date . ' ' . $match[1], wp_timezone() );
		$end = DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $date . ' ' . $match[2], wp_timezone() );
		if ( ! $start || ! $end || $start >= $end ) {
			throw new DomainException( '回収希望時間帯を選び直してください。' );
		}
		$utc = new DateTimeZone( 'UTC' );
		return array( $start->setTimezone( $utc )->format( 'Y-m-d H:i:s' ), $end->setTimezone( $utc )->format( 'Y-m-d H:i:s' ) );
	}

	private function lock_service_date( PDO $pdo, $date ) {
		$insert = $pdo->prepare( 'INSERT IGNORE INTO visit_slot_reservation_day_locks (service_date) VALUES (?)' );
		$insert->execute( array( $date ) );
		$lock = $pdo->prepare( 'SELECT service_date FROM visit_slot_reservation_day_locks WHERE service_date = ? FOR UPDATE' );
		$lock->execute( array( $date ) );
		if ( ! $lock->fetchColumn() ) {
			throw new RuntimeException( '予約枠をロックできません。' );
		}
	}

	private function reserved_count( PDO $pdo, $start_at, $end_at ) {
		$stmt = $pdo->prepare( 'SELECT COUNT(*) FROM visit_purchase_details WHERE confirmed_start_at < ? AND confirmed_end_at > ?' );
		$stmt->execute( array( $end_at, $start_at ) );
		return (int) $stmt->fetchColumn();
	}

	private function assert_selected_slots_available( PDO $pdo, $data, $lock_date ) {
		if ( $lock_date ) {
			$this->lock_service_date( $pdo, $data['pickup_date'] );
		}
		$capacity = $this->slot_capacity();
		foreach ( $data['pickup_times'] as $slot ) {
			list( $start_at, $end_at ) = $this->slot_range_utc( $data['pickup_date'], $slot );
			if ( $this->reserved_count( $pdo, $start_at, $end_at ) >= $capacity ) {
				throw new DomainException( '選択した時間帯は満枠になりました。空きのある時間帯を選び直してください。' );
			}
		}
	}

	public function availability( WP_REST_Request $request ) {
		try {
			if ( ! $this->database_ready() ) {
				return $this->response_error( '空き枠を確認できません。', 503 );
			}
			$month = sanitize_text_field( (string) $request->get_param( 'month' ) );
			$region_slug = sanitize_key( (string) $request->get_param( 'region_slug' ) );
			$area = sanitize_text_field( (string) $request->get_param( 'visit_area' ) );
			$regions_file = get_stylesheet_directory() . '/visit-lp-draft/visit/regions.php';
			$regions = is_readable( $regions_file ) ? require $regions_file : array();
			if ( ! preg_match( '/^\d{4}-\d{2}$/', $month ) || ! isset( $regions[ $region_slug ] ) || ! in_array( $area, $regions[ $region_slug ]['areas'], true ) ) {
				return $this->response_error( '対応地域または対象月を確認してください。', 400 );
			}
			$month_start = DateTimeImmutable::createFromFormat( '!Y-m-d', $month . '-01', wp_timezone() );
			if ( ! $month_start || $month_start->format( 'Y-m' ) !== $month ) {
				return $this->response_error( '対象月を確認してください。', 400 );
			}
			$slots = function_exists( 'visit_booking_get_shared_slots' ) ? visit_booking_get_shared_slots() : array( '10:00〜12:00', '12:00〜14:00', '14:00〜16:00', '16:00〜18:00', '18:00〜20:00' );
			$pdo = $this->db();
			$this->check_schema( $pdo );
			$utc = new DateTimeZone( 'UTC' );
			$month_start_utc = $month_start->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
			$month_end_utc = $month_start->modify( 'first day of next month' )->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
			$reservations_query = $pdo->prepare( 'SELECT confirmed_start_at, confirmed_end_at FROM visit_purchase_details WHERE confirmed_start_at < ? AND confirmed_end_at > ?' );
			$reservations_query->execute( array( $month_end_utc, $month_start_utc ) );
			$reservations = $reservations_query->fetchAll();
			$last_day = (int) $month_start->modify( 'last day of this month' )->format( 'j' );
			$days = array();
			for ( $day = 1; $day <= $last_day; $day++ ) {
				$date = $month_start->setDate( (int) $month_start->format( 'Y' ), (int) $month_start->format( 'n' ), $day )->format( 'Y-m-d' );
				$day_slots = array();
				foreach ( $slots as $slot ) {
					list( $start_at, $end_at ) = $this->slot_range_utc( $date, $slot );
					$reserved = 0;
					foreach ( $reservations as $reservation ) {
						if ( $reservation['confirmed_start_at'] < $end_at && $reservation['confirmed_end_at'] > $start_at ) {
							$reserved++;
						}
					}
					$day_slots[] = array( 'time' => $slot, 'available' => $this->is_service_date( $date ) && $reserved < $this->slot_capacity() );
				}
				$days[ $date ] = array( 'available' => $this->is_service_date( $date ) && count( array_filter( $day_slots, static function ( $entry ) { return $entry['available']; } ) ) > 0, 'slots' => $day_slots );
			}
			return rest_ensure_response( array( 'month' => $month, 'days' => $days ) );
		} catch ( Throwable $e ) {
			error_log( '[visit-application] availability lookup failed' );
			return $this->response_error( '空き枠を確認できません。時間をおいて再度お試しください。', 503 );
		}
	}

	private function response_error( $message, $status ) {
		return new WP_Error( 'visit_application_error', $message, array( 'status' => $status ) );
	}

	private function db() {
		if ( ! $this->enabled() ) {
			throw new RuntimeException( '出張買取APIは無効です。' );
		}
		if ( ! defined( 'OTP_DB_HOST' ) || ! defined( 'OTP_DB_NAME' ) || ! defined( 'OTP_DB_USER' ) || ! defined( 'OTP_DB_PASS' ) || ! defined( 'OTP_DB_CHARSET' ) ) {
			throw new RuntimeException( '既存のアプリケーションDB設定がありません。' );
		}
		if ( 'contact_db' !== (string) OTP_DB_NAME ) {
			throw new RuntimeException( '出張買取APIの接続先DBを確認できません。' );
		}
		$host = (string) OTP_DB_HOST;
		$charset = (string) OTP_DB_CHARSET;
		if ( '' === $host || ! preg_match( '/^[A-Za-z0-9._:\-]+$/', $host ) || ! preg_match( '/^[A-Za-z0-9_]+$/', $charset ) ) {
			throw new RuntimeException( '既存のアプリケーションDB設定が不正です。' );
		}
		$dsn = sprintf( 'mysql:host=%s;dbname=contact_db;charset=%s', $host, $charset );
		return new PDO( $dsn, (string) OTP_DB_USER, (string) OTP_DB_PASS, array(
			PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			PDO::ATTR_EMULATE_PREPARES   => false,
		) );
	}

	private function assert_same_origin() {
		$origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) : '';
		$origin_parts = $origin ? wp_parse_url( $origin ) : array();
		$site_parts = wp_parse_url( home_url() );
		$origin_port = isset( $origin_parts['port'] ) ? (int) $origin_parts['port'] : ( ( isset( $origin_parts['scheme'] ) && 'https' === strtolower( $origin_parts['scheme'] ) ) ? 443 : 80 );
		$site_port = isset( $site_parts['port'] ) ? (int) $site_parts['port'] : ( ( isset( $site_parts['scheme'] ) && 'https' === strtolower( $site_parts['scheme'] ) ) ? 443 : 80 );
		if ( empty( $origin_parts['host'] ) || empty( $site_parts['host'] ) || strtolower( $origin_parts['host'] ) !== strtolower( $site_parts['host'] ) || strtolower( (string) $origin_parts['scheme'] ) !== strtolower( (string) $site_parts['scheme'] ) || $origin_port !== $site_port ) {
			throw new DomainException( 'リクエストの送信元を確認できません。' );
		}
	}

	private function assert_nonce( WP_REST_Request $request ) {
		$nonce = (string) $request->get_param( 'visit_application_nonce' );
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'visit_application_submit' ) ) {
			throw new DomainException( '画面の有効期限が切れました。ページを再読み込みしてください。' );
		}
	}

	private function rate_limit() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
		$key = 'visit_submit_' . substr( hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) ), 0, 32 );
		$count = (int) get_transient( $key );
		if ( $count >= self::RATE_LIMIT ) {
			throw new DomainException( '送信回数が上限に達しました。時間をおいてお試しください。' );
		}
		set_transient( $key, $count + 1, self::RATE_WINDOW );
	}

	private function digits( $value ) {
		$value = mb_convert_kana( (string) $value, 'n', 'UTF-8' );
		return preg_replace( '/\D/', '', $value );
	}

	private function normalized_kana( $value ) {
		$value = mb_convert_kana( (string) $value, 'KVc', 'UTF-8' );
		return preg_replace( '/[\s　]+/u', '', $value );
	}

	private function validate_payload( WP_REST_Request $request ) {
		$data = array();
		$fields = array( 'last_name', 'first_name', 'last_name_kana', 'first_name_kana', 'email', 'email_confirm', 'phone', 'postal_code', 'prefecture', 'city', 'street', 'building', 'birth_date', 'occupation', 'bank_code', 'bank_name', 'branch_code', 'branch_name', 'account_type', 'account_number', 'account_holder_kana', 'building_type', 'nameplate', 'nameplate_name', 'pickup_place_details', 'pickup_notes', 'pickup_latitude', 'pickup_longitude', 'box_count', 'campaign_code', 'register_choice', 'receive_choice', 'visit_area', 'visit_region_slug', 'handoff_method', 'pickup_date', 'pickup_times', 'contactless_consent_version', 'contactless_consent' );
		foreach ( $fields as $field ) {
			$value = $request->get_param( $field );
			$data[ $field ] = is_scalar( $value ) ? trim( (string) $value ) : '';
		}
		$email = sanitize_email( strtolower( $data['email'] ) );
		if ( ! is_email( $email ) || $email !== sanitize_email( strtolower( $data['email_confirm'] ) ) ) {
			throw new DomainException( 'メールアドレスを確認してください。' );
		}
		$data['email'] = $email;
		$data['visit_region_slug'] = sanitize_key( $data['visit_region_slug'] );
		$regions_file = get_stylesheet_directory() . '/visit-lp-draft/visit/regions.php';
		$regions = is_readable( $regions_file ) ? require $regions_file : array();
		if ( ! isset( $regions[ $data['visit_region_slug'] ] ) ) {
			throw new DomainException( '対応地域を確認してください。' );
		}
		$data['last_name'] = sanitize_text_field( $data['last_name'] );
		$data['first_name'] = sanitize_text_field( $data['first_name'] );
		$data['last_name_kana'] = $this->normalized_kana( $data['last_name_kana'] );
		$data['first_name_kana'] = $this->normalized_kana( $data['first_name_kana'] );
		$data['account_holder_kana'] = $this->normalized_kana( $data['account_holder_kana'] );
		$expected_holder = $this->normalized_kana( $data['last_name_kana'] . $data['first_name_kana'] );
		if ( $expected_holder !== $data['account_holder_kana'] ) {
			throw new DomainException( '口座名義（カナ）はお申込者のセイ・メイと一致させてください。' );
		}
		$region = $regions[ $data['visit_region_slug'] ];
		$city_prefix = isset( $region['city'] ) ? (string) $region['city'] : '';
		$city_area = 0 === strpos( $data['city'], $city_prefix ) ? substr( $data['city'], strlen( $city_prefix ) ) : '';
		if ( '' === $city_prefix || $region['prefecture'] !== $data['prefecture'] || ! in_array( $data['visit_area'], $region['areas'], true ) || $data['visit_area'] !== $city_area ) {
			throw new DomainException( '対応エリアを確認してください。' );
		}
		if ( ! in_array( $data['handoff_method'], array( 'face', 'contactless' ), true ) ) {
			throw new DomainException( '受け渡し方法を選択してください。' );
		}
		if ( ! in_array( $data['building_type'], array( 'detached', 'multi_unit' ), true ) || ! in_array( $data['nameplate'], array( 'yes', 'no' ), true ) ) {
			throw new DomainException( '集荷場所の情報を確認してください。' );
		}
		if ( 'contactless' === $data['handoff_method'] && ( 'yes' !== $data['contactless_consent'] || '' === $data['pickup_place_details'] ) ) {
			throw new DomainException( '非対面回収の注意事項への同意と置き場所の入力が必要です。' );
		}
		if ( 'contactless' === $data['handoff_method'] ) {
			$current_version = function_exists( 'visit_booking_get_contactless_terms_version' ) ? visit_booking_get_contactless_terms_version() : 'contactless-v1-draft';
			if ( ! hash_equals( (string) $current_version, (string) $data['contactless_consent_version'] ) ) {
				throw new DomainException( '注意事項が更新されています。内容を確認してから再度お申し込みください。' );
			}
			$data['contactless_consent_version'] = (string) $current_version;
			$data['contactless_consent_text'] = function_exists( 'visit_booking_get_contactless_terms_text' ) ? (string) visit_booking_get_contactless_terms_text() : '';
			$data['contactless_consented_at_utc'] = gmdate( 'Y-m-d H:i:s' );
		} else {
			$data['contactless_consent_text'] = '';
			$data['contactless_consented_at_utc'] = null;
		}
		if ( '' === $data['last_name'] || '' === $data['first_name'] || '' === $data['last_name_kana'] || '' === $data['first_name_kana'] || '' === $data['phone'] || '' === $data['postal_code'] || '' === $data['street'] || '' === $data['birth_date'] || '' === $data['occupation'] ) {
			throw new DomainException( '必須項目を入力してください。' );
		}
		$birth = DateTimeImmutable::createFromFormat( '!Y-m-d', $data['birth_date'], wp_timezone() );
		$today = new DateTimeImmutable( 'today', wp_timezone() );
		if ( ! $birth || $birth->format( 'Y-m-d' ) !== $data['birth_date'] || $birth > $today->modify( '-18 years' ) ) {
			throw new DomainException( '18歳未満の方はお申込みできません。' );
		}
		$phone = $this->digits( $data['phone'] );
		$zip = $this->digits( $data['postal_code'] );
		$account_number = $this->digits( $data['account_number'] );
		$bank_code = $this->digits( $data['bank_code'] );
		$branch_code = $this->digits( $data['branch_code'] );
		if ( strlen( $phone ) < 10 || strlen( $phone ) > 11 || 7 !== strlen( $zip ) || 7 !== strlen( $account_number ) || '' === $data['bank_name'] || '' === $data['branch_name'] ) {
			throw new DomainException( '電話番号・郵便番号・口座情報を確認してください。' );
		}
		if ( '' === $bank_code || strlen( $bank_code ) > 4 || '' === $branch_code || strlen( $branch_code ) > 3 ) {
			throw new DomainException( '銀行・支店の選択を確認してください。' );
		}
		$data['bank_code'] = str_pad( $bank_code, 4, '0', STR_PAD_LEFT );
		$data['branch_code'] = str_pad( $branch_code, 3, '0', STR_PAD_LEFT );
		$data['account_number'] = $account_number;
		$data['phone'] = $phone;
		$data['postal_code'] = $zip;
		$data['account_type'] = array( '普通' => 'ordinary', '当座' => 'checking' )[ $data['account_type'] ] ?? '';
		if ( '' === $data['account_type'] ) {
			throw new DomainException( '口座種別を選択してください。' );
		}
		$privacy_agreement = $request->get_param( 'privacy_agreement' );
		$terms_agreement = $request->get_param( 'terms_agreement' );
		if ( ! in_array( $data['register_choice'], array( 'yes', 'no' ), true ) || ! in_array( $data['receive_choice'], array( 'yes', 'no' ), true ) || empty( $privacy_agreement ) || empty( $terms_agreement ) ) {
			throw new DomainException( '申込設定と同意項目を確認してください。' );
		}
		$box_count = filter_var( $request->get_param( 'box_count' ), FILTER_VALIDATE_INT );
		if ( false === $box_count || $box_count < 0 || $box_count > 255 ) {
			throw new DomainException( '箱数を確認してください。' );
		}
		if ( strlen( $data['campaign_code'] ) > 10 ) {
			throw new DomainException( 'キャンペーンコードは10文字以内で入力してください。' );
		}
		$data['box_count'] = $box_count;
		$data['campaign_code'] = sanitize_text_field( $data['campaign_code'] );
		$data['pickup_notes'] = sanitize_textarea_field( $data['pickup_notes'] );
		$data['pickup_place_details'] = sanitize_text_field( $data['pickup_place_details'] );
		$data['nameplate_name'] = sanitize_text_field( $data['nameplate_name'] );
		$data['prefecture'] = (string) $region['prefecture'];
		$data['city'] = sanitize_text_field( $data['city'] );
		$data['street'] = sanitize_text_field( $data['street'] );
		$data['building'] = sanitize_text_field( $data['building'] );
		if ( mb_strlen( $data['prefecture'] . $data['city'] . ' ' . $data['street'] . ' ' . $data['building'], 'UTF-8' ) > 255 || mb_strlen( trim( $data['street'] . ' ' . $data['building'] ), 'UTF-8' ) > 255 ) {
			throw new DomainException( '住所が長すぎます。入力内容を確認してください。' );
		}
		$data['occupation'] = sanitize_text_field( $data['occupation'] );
		$data['bank_name'] = sanitize_text_field( $data['bank_name'] );
		$data['branch_name'] = sanitize_text_field( $data['branch_name'] );
		$data['pickup_date'] = sanitize_text_field( $data['pickup_date'] );
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $data['pickup_date'], wp_timezone() );
		if ( ! $date || $date->format( 'Y-m-d' ) !== $data['pickup_date'] || ! $this->is_service_date( $data['pickup_date'] ) ) {
			throw new DomainException( '回収希望日を選び直してください。' );
		}
		$available_slots = function_exists( 'visit_booking_get_shared_slots' ) ? visit_booking_get_shared_slots() : array( '10:00〜12:00', '12:00〜14:00', '14:00〜16:00', '16:00〜18:00', '18:00〜20:00' );
		$selected_slots = array_values( array_unique( array_filter( explode( '|', $data['pickup_times'] ) ) ) );
		if ( empty( $selected_slots ) || count( $selected_slots ) > count( $available_slots ) ) {
			throw new DomainException( '回収希望時間帯を選択してください。' );
		}
		foreach ( $selected_slots as $slot ) {
			if ( ! in_array( $slot, $available_slots, true ) || ! preg_match( '/^(\d{2}:\d{2})〜(\d{2}:\d{2})$/u', $slot, $match ) || $match[1] >= $match[2] ) {
				throw new DomainException( '回収希望時間帯を選び直してください。' );
			}
		}
		$data['pickup_times'] = $selected_slots;
		$lat = filter_var( $data['pickup_latitude'], FILTER_VALIDATE_FLOAT );
		$lng = filter_var( $data['pickup_longitude'], FILTER_VALIDATE_FLOAT );
		if ( false === $lat || false === $lng || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 ) {
			throw new DomainException( '地図で集荷場所を指定してください。' );
		}
		$data['pickup_latitude'] = (float) $lat;
		$data['pickup_longitude'] = (float) $lng;
		$data['email_confirm'] = '';
		$data['privacy_agreement'] = true;
		$data['terms_agreement'] = true;
		return $data;
	}

	private function encryption_key() {
		$key_b64 = defined( 'PAYOUT_ENC_KEY_B64' ) ? PAYOUT_ENC_KEY_B64 : getenv( 'PAYOUT_ENC_KEY_B64' );
		$key = base64_decode( (string) $key_b64, true );
		if ( false === $key || 32 !== strlen( $key ) ) {
			throw new RuntimeException( '暗号化設定が正しくありません。' );
		}
		return $key;
	}

	private function encrypt( $plaintext ) {
		$key = $this->encryption_key();
		$iv = random_bytes( 12 );
		$tag = '';
		$cipher = openssl_encrypt( $plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
		if ( false === $cipher || 16 !== strlen( $tag ) ) {
			throw new RuntimeException( '暗号化に失敗しました。' );
		}
		return array( base64_encode( $cipher ), base64_encode( $iv ), base64_encode( $tag ) );
	}

	private function encrypt_draft( $payload ) {
		$key = $this->encryption_key();
		$iv = random_bytes( 12 );
		$tag = '';
		$json = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$cipher = openssl_encrypt( $json, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
		if ( false === $cipher || 16 !== strlen( $tag ) ) {
			throw new RuntimeException( '一時データを暗号化できません。' );
		}
		return array( $cipher, $iv, $tag );
	}

	private function encrypt_private_token( $token ) {
		$iv = random_bytes( 12 );
		$tag = '';
		$cipher = openssl_encrypt( $token, 'aes-256-gcm', $this->encryption_key(), OPENSSL_RAW_DATA, $iv, $tag );
		if ( false === $cipher || 32 !== strlen( $cipher ) || 16 !== strlen( $tag ) ) {
			throw new RuntimeException( '一時トークンを暗号化できません。' );
		}
		return array( $cipher, $iv, $tag );
	}

	private function decrypt_private_token( $ciphertext, $iv, $tag ) {
		$token = openssl_decrypt( $ciphertext, 'aes-256-gcm', $this->encryption_key(), OPENSSL_RAW_DATA, $iv, $tag );
		if ( ! is_string( $token ) || ! preg_match( '/^[a-f0-9]{64}$/i', $token ) ) {
			throw new RuntimeException( '状態確認トークンを復号できません。' );
		}
		return $token;
	}

	private function decrypt_draft( $ciphertext, $iv, $tag ) {
		$json = openssl_decrypt( $ciphertext, 'aes-256-gcm', $this->encryption_key(), OPENSSL_RAW_DATA, $iv, $tag );
		$data = false !== $json ? json_decode( $json, true ) : null;
		if ( ! is_array( $data ) ) {
			throw new RuntimeException( '一時申込データを復号できません。' );
		}
		return $data;
	}

	private function check_schema( PDO $pdo ) {
		$required = array( 'visit_application_drafts', 'visit_purchase_details', 'visit_pickup_time_slots', 'visit_consent_records', 'visit_slot_reservation_day_locks', 'purchase_requests' );
		foreach ( $required as $table ) {
			$stmt = $pdo->prepare( 'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1' );
			$stmt->execute( array( $table ) );
			if ( ! $stmt->fetchColumn() ) {
				throw new RuntimeException( '出張買取用テーブルが未作成です。' );
			}
		}
		$table_columns = array(
			'visit_application_drafts' => array( 'submission_key_hash', 'confirmation_token_hash', 'poll_token_hash', 'payload_ciphertext', 'payload_iv', 'payload_tag', 'poll_token_ciphertext', 'poll_token_iv', 'poll_token_tag', 'expires_at', 'draft_status', 'public_token' ),
			'visit_purchase_details' => array( 'public_token', 'collection_code', 'visit_type', 'latitude', 'longitude', 'placement_location', 'placement_note', 'nameplate_status', 'nameplate_name', 'building_type', 'access_note', 'confirmed_start_at', 'confirmed_end_at', 'collection_status', 'collected_at' ),
			'visit_pickup_time_slots' => array( 'public_token', 'preference_order', 'start_at', 'end_at' ),
			'visit_consent_records' => array( 'public_token', 'consent_type', 'consent_version', 'consent_text', 'consented_at' ),
			'visit_slot_reservation_day_locks' => array( 'service_date' ),
		);
		$columns_query = $pdo->prepare( 'SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?' );
		foreach ( $table_columns as $table => $expected_columns ) {
			$columns_query->execute( array( $table ) );
			$actual_columns = array_column( $columns_query->fetchAll(), 'column_name' );
			if ( array_diff( $expected_columns, $actual_columns ) ) {
				throw new RuntimeException( '出張買取用テーブルの必須カラムが不足しています。' );
			}
		}
		$stmt = $pdo->query( "SHOW COLUMNS FROM purchase_requests LIKE 'shipping_choice'" );
		$column = $stmt->fetch();
		if ( ! $column || false === strpos( (string) $column['Type'], 'visit' ) ) {
			throw new RuntimeException( 'purchase_requests.shipping_choice に visit がありません。' );
		}
		$parent_columns = array( 'request_code', 'public_token', 'shipping_method', 'payment_method', 'last_name', 'first_name', 'last_name_kana', 'first_name_kana', 'application_zip', 'application_prefecture', 'application_city', 'application_address1', 'address', 'email', 'phone_number', 'birth_date', 'occupation', 'bank_code', 'branch_code', 'bank_name', 'branch_name', 'account_type', 'account_number_enc', 'account_number_iv', 'account_number_tag', 'account_holder_kana_enc', 'account_holder_kana_iv', 'account_holder_kana_tag', 'account_number_last4', 'account_fingerprint', 'pickup_time', 'pickup_zip', 'pickup_prefecture', 'pickup_city', 'pickup_address1', 'box_count', 'register_flg', 'approval_flg', 'receive_flg', 'campaign_code', 'campaign_code_status', 'pickup_date' );
		$column_stmt = $pdo->prepare( 'SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?' );
		$column_stmt->execute( array( 'purchase_requests' ) );
		$available = array_column( $column_stmt->fetchAll(), 'column_name' );
		if ( array_diff( $parent_columns, $available ) ) {
			throw new RuntimeException( 'purchase_requests の必須カラムが不足しています。' );
		}
	}

	private function token() {
		return bin2hex( random_bytes( 32 ) );
	}

	private function mail_link( $token, $region_slug ) {
		return add_query_arg( array( 'token' => rawurlencode( $token ), 'region' => sanitize_key( $region_slug ) ), rest_url( self::API_NAMESPACE . '/confirm' ) );
	}

	public function submit( WP_REST_Request $request ) {
		try {
			if ( ! $this->enabled() ) {
				return $this->response_error( '出張買取の申込受付は現在停止しています。', 503 );
			}
			$this->assert_same_origin();
			$this->assert_nonce( $request );
			$this->rate_limit();
			if ( '' !== (string) $request->get_param( 'website' ) ) {
				return $this->response_error( '送信内容を確認できません。', 400 );
			}
			$data = $this->validate_payload( $request );
			$test_recipient_setting = defined( 'VISIT_CONFIRMATION_TEST_RECIPIENT' ) ? VISIT_CONFIRMATION_TEST_RECIPIENT : getenv( 'VISIT_CONFIRMATION_TEST_RECIPIENT' );
			$test_recipient = sanitize_email( (string) $test_recipient_setting );
			if ( ! is_email( $test_recipient ) || strtolower( $test_recipient ) === strtolower( $data['email'] ) ) {
				return $this->response_error( 'テスト用メール設定を確認できません。', 503 );
			}
			$submission_key = (string) $request->get_header( 'x-visit-submission-key' );
			if ( ! preg_match( '/^[a-f0-9]{64}$/i', $submission_key ) ) {
				return $this->response_error( '送信状態を確認できません。ページを再読み込みしてください。', 400 );
			}
			$confirmation_token = $this->token();
			$poll_token = $this->token();
			list( $ciphertext, $iv, $tag ) = $this->encrypt_draft( $data );
			$pdo = $this->db();
			$this->check_schema( $pdo );
			$this->assert_selected_slots_available( $pdo, $data, false );
			$pdo->exec( "DELETE FROM visit_application_drafts WHERE draft_status = 'expired' OR (draft_status = 'pending' AND expires_at <= UTC_TIMESTAMP())" );
			$existing = $pdo->prepare( 'SELECT poll_token_ciphertext, poll_token_iv, poll_token_tag, draft_status, public_token, expires_at FROM visit_application_drafts WHERE submission_key_hash = ? LIMIT 1' );
			$existing->execute( array( hash( 'sha256', $submission_key, true ) ) );
			$row = $existing->fetch();
			if ( $row && 'pending' === $row['draft_status'] && strtotime( $row['expires_at'] . ' UTC' ) > time() ) {
				$poll_token = $this->decrypt_private_token( $row['poll_token_ciphertext'], $row['poll_token_iv'], $row['poll_token_tag'] );
				return rest_ensure_response( array( 'status' => 'pending', 'poll_token' => $poll_token, 'test_mail_only' => true ) );
			}
			if ( $row && 'confirmed' === $row['draft_status'] && ! empty( $row['public_token'] ) ) {
				return rest_ensure_response( array( 'status' => 'complete', 'public_token' => $row['public_token'] ) );
			}
			if ( $row ) {
				$delete = $pdo->prepare( 'DELETE FROM visit_application_drafts WHERE submission_key_hash = ? AND draft_status = \'expired\'' );
				$delete->execute( array( hash( 'sha256', $submission_key, true ) ) );
				if ( 1 !== $delete->rowCount() ) {
					return $this->response_error( 'この申込は期限切れです。ページを再読み込みして入力し直してください。', 410 );
				}
			}
			list( $poll_cipher, $poll_iv, $poll_tag ) = $this->encrypt_private_token( $poll_token );
			$insert = $pdo->prepare( 'INSERT INTO visit_application_drafts (submission_key_hash, confirmation_token_hash, poll_token_hash, payload_ciphertext, payload_iv, payload_tag, poll_token_ciphertext, poll_token_iv, poll_token_tag, expires_at, draft_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ' . self::DRAFT_TTL . ' SECOND), \'pending\')' );
			$insert->execute( array( hash( 'sha256', $submission_key, true ), hash( 'sha256', $confirmation_token, true ), hash( 'sha256', $poll_token, true ), $ciphertext, $iv, $tag, $poll_cipher, $poll_iv, $poll_tag ) );
			$subject = 'おうち買取便 出張買取：確認リンク（ステージングテスト）';
			$body = "ステージング環境の出張買取メール確認テストです。\n\n" . $this->mail_link( $confirmation_token, $data['visit_region_slug'] ) . "\n\nこのメールは固定テスト宛先にのみ送信されています。申込者の入力メールアドレスには送信していません。\nリンクの有効期限は5時間です。";
			$mail_sent = false;
			try {
				$mail_sent = wp_mail( $test_recipient, $subject, $body, array( 'Content-Type: text/plain; charset=UTF-8' ) );
			} catch ( Throwable $mail_error ) {
				$mail_sent = false;
			}
			if ( ! $mail_sent ) {
				$expired = $pdo->prepare( "DELETE FROM visit_application_drafts WHERE confirmation_token_hash = ? AND draft_status = 'pending'" );
				$expired->execute( array( hash( 'sha256', $confirmation_token, true ) ) );
				return $this->response_error( 'テスト用確認メールを送信できませんでした。申込は確定していません。', 502 );
			}
			return rest_ensure_response( array( 'status' => 'pending', 'poll_token' => $poll_token, 'test_mail_only' => true ) );
		} catch ( DomainException $e ) {
			return $this->response_error( $e->getMessage(), 400 );
		} catch ( Throwable $e ) {
			error_log( '[visit-application] submit failed' );
			return $this->response_error( '仮申し込みを処理できませんでした。設定と入力内容を確認してください。', 503 );
		}
	}

	public function status( WP_REST_Request $request ) {
		if ( ! $this->enabled() ) {
			return $this->response_error( '申込状態を確認できません。', 503 );
		}
		$token = (string) $request->get_param( 'poll_token' );
		if ( ! preg_match( '/^[a-f0-9]{64}$/i', $token ) ) {
			return $this->response_error( '申込状態を確認できません。', 400 );
		}
		try {
			$pdo = $this->db();
			$this->check_schema( $pdo );
			$stmt = $pdo->prepare( 'SELECT draft_id, draft_status, public_token, expires_at FROM visit_application_drafts WHERE poll_token_hash = ? LIMIT 1' );
			$stmt->execute( array( hash( 'sha256', $token, true ) ) );
			$row = $stmt->fetch();
			if ( ! $row ) {
				return rest_ensure_response( array( 'status' => 'expired' ) );
			}
			if ( 'confirmed' === $row['draft_status'] && preg_match( '/^[a-f0-9]{64}$/', (string) $row['public_token'] ) ) {
				return rest_ensure_response( array( 'status' => 'complete', 'public_token' => $row['public_token'] ) );
			}
			if ( strtotime( $row['expires_at'] . ' UTC' ) < time() || 'expired' === $row['draft_status'] ) {
				if ( $row ) {
					$delete = $pdo->prepare( "DELETE FROM visit_application_drafts WHERE draft_id = ? AND draft_status <> 'confirmed'" );
					$delete->execute( array( $row['draft_id'] ) );
				}
				return rest_ensure_response( array( 'status' => 'expired' ) );
			}
			return rest_ensure_response( array( 'status' => 'pending' ) );
		} catch ( Throwable $e ) {
			error_log( '[visit-application] status lookup failed' );
			return $this->response_error( '申込状態を確認できません。', 503 );
		}
	}

	public function confirmation_page( WP_REST_Request $request ) {
		if ( ! $this->enabled() ) {
			return new WP_REST_Response( '<!doctype html><meta charset="utf-8"><title>確認リンク</title><p>確認処理を利用できません。</p>', 503, array( 'Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex, nofollow' ) );
		}
		$token = sanitize_text_field( (string) $request->get_param( 'token' ) );
		$region_slug = sanitize_key( (string) $request->get_param( 'region' ) );
		$regions_file = get_stylesheet_directory() . '/visit-lp-draft/visit/regions.php';
		$regions = is_readable( $regions_file ) ? require $regions_file : array();
		if ( ! preg_match( '/^[a-f0-9]{64}$/i', $token ) ) {
			return new WP_REST_Response( '<!doctype html><meta charset="utf-8"><title>確認リンク</title><p>確認リンクが無効です。</p>', 400, array( 'Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex, nofollow' ) );
		}
		if ( ! isset( $regions[ $region_slug ] ) ) {
			return new WP_REST_Response( '<!doctype html><meta charset="utf-8"><title>確認リンク</title><p>確認リンクが無効です。</p>', 400, array( 'Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex, nofollow' ) );
		}
		$confirmation_page_url = home_url( '/visit/' . rawurlencode( $region_slug ) . '/application-pending/' );
		$html = '<!doctype html><html lang="ja"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="referrer" content="no-referrer"><title>メール確認｜おうち買取便</title><style>body{font-family:system-ui,sans-serif;margin:0;background:#f5f7f5;color:#24352a}.wrap{max-width:620px;margin:8vh auto;padding:24px}.card{background:#fff;border-radius:18px;padding:clamp(24px,6vw,48px);box-shadow:0 12px 36px #263b2518}h1{font-size:clamp(1.5rem,5vw,2.2rem);line-height:1.4}p{line-height:1.8}button{border:0;border-radius:999px;background:#f47c20;color:white;font-weight:700;font-size:1rem;padding:16px 26px;cursor:pointer}button:disabled{opacity:.6}#status{margin-top:1rem}</style><main class="wrap"><section class="card"><p>おうち買取便 出張買取</p><h1>メールアドレスを確認します</h1><p>下のボタンを押すと、出張買取の申込内容が登録されます。</p><form id="confirm"><input type="hidden" name="token" value="' . esc_attr( $token ) . '"><button type="submit">メールを確認して申込内容を登録</button></form><p id="status" role="status" aria-live="polite"></p></section></main><script>document.getElementById("confirm").addEventListener("submit",async function(e){e.preventDefault();var b=this.querySelector("button"),s=document.getElementById("status");b.disabled=true;s.textContent="確認しています…";try{var r=await fetch(' . wp_json_encode( rest_url( self::API_NAMESPACE . '/confirm' ) ) . ',{method:"POST",headers:{"Content-Type":"application/json","Accept":"application/json"},body:JSON.stringify({token:this.elements.token.value})});var d=await r.json();if(!r.ok)throw new Error(d.message||"確認できませんでした。");var u=new URL(' . wp_json_encode( $confirmation_page_url ) . ');u.searchParams.set("public_token",d.public_token);location.assign(u.toString())}catch(err){s.textContent=err.message;b.disabled=false}});</script></html>';
		return new WP_REST_Response( $html, 200, array( 'Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0', 'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex, nofollow, noarchive' ) );
	}

	private function collection_code() {
		$letters = str_replace( ' ', '', self::COLLECTION_LETTERS );
		return $letters[ random_int( 0, strlen( $letters ) - 1 ) ] . self::COLLECTION_DIGITS[ random_int( 0, strlen( self::COLLECTION_DIGITS ) - 1 ) ];
	}

	private function insert_confirmed_application( PDO $pdo, $draft ) {
		$data = $this->decrypt_draft( $draft['payload_ciphertext'], $draft['payload_iv'], $draft['payload_tag'] );
		$this->assert_selected_slots_available( $pdo, $data, true );
		$public_token = bin2hex( random_bytes( 32 ) );
		$code = $this->collection_code();
		$account_enc = $this->encrypt( $data['account_number'] );
		$holder_enc = $this->encrypt( $data['account_holder_kana'] );
		$fingerprint = hash( 'sha256', $data['bank_code'] . '|' . $data['branch_code'] . '|' . $data['account_type'] . '|' . $data['account_number'] . '|' . $data['account_holder_kana'], true );
		$address = trim( $data['prefecture'] . $data['city'] . $data['street'] . ' ' . $data['building'] );
		$pickup_address = trim( $data['street'] . ' ' . $data['building'] );
		$register = 'yes' === $data['register_choice'] ? 1 : 0;
		$receive = 'yes' === $data['receive_choice'] ? 1 : 0;
			$campaign_status = null;
		$sql = 'INSERT INTO purchase_requests (request_code, public_token, shipping_method, payment_method, last_name, first_name, last_name_kana, first_name_kana, application_zip, application_prefecture, application_city, application_address1, address, email, phone_number, birth_date, occupation, bank_code, branch_code, bank_name, branch_name, account_type, account_number_enc, account_number_iv, account_number_tag, account_holder_kana_enc, account_holder_kana_iv, account_holder_kana_tag, account_number_last4, account_fingerprint, shipping_choice, pickup_time, pickup_zip, pickup_prefecture, pickup_city, pickup_address1, box_count, register_flg, approval_flg, receive_flg, campaign_code, campaign_code_status, pickup_date) VALUES (NULL, :public_token, \'visit\', \'銀行振込\', :last_name, :first_name, :last_name_kana, :first_name_kana, :application_zip, :application_prefecture, :application_city, :application_address1, :address, :email, :phone_number, :birth_date, :occupation, :bank_code, :branch_code, :bank_name, :branch_name, :account_type, :account_number_enc, :account_number_iv, :account_number_tag, :account_holder_kana_enc, :account_holder_kana_iv, :account_holder_kana_tag, :account_number_last4, :account_fingerprint, \'visit\', NULL, :pickup_zip, :pickup_prefecture, :pickup_city, :pickup_address1, :box_count, :register_flg, 0, :receive_flg, :campaign_code, :campaign_code_status, :pickup_date)';
		$stmt = $pdo->prepare( $sql );
		$stmt->execute( array(
			':public_token' => $public_token, ':last_name' => $data['last_name'], ':first_name' => $data['first_name'], ':last_name_kana' => $data['last_name_kana'], ':first_name_kana' => $data['first_name_kana'],
			':application_zip' => $data['postal_code'], ':application_prefecture' => $data['prefecture'], ':application_city' => $data['city'], ':application_address1' => trim( $data['street'] . ' ' . $data['building'] ), ':address' => $address, ':email' => $data['email'], ':phone_number' => $data['phone'], ':birth_date' => $data['birth_date'], ':occupation' => $data['occupation'],
			':bank_code' => $data['bank_code'], ':branch_code' => $data['branch_code'], ':bank_name' => $data['bank_name'], ':branch_name' => $data['branch_name'], ':account_type' => $data['account_type'], ':account_number_enc' => $account_enc[0], ':account_number_iv' => $account_enc[1], ':account_number_tag' => $account_enc[2], ':account_holder_kana_enc' => $holder_enc[0], ':account_holder_kana_iv' => $holder_enc[1], ':account_holder_kana_tag' => $holder_enc[2], ':account_number_last4' => substr( $data['account_number'], -4 ), ':account_fingerprint' => $fingerprint,
			':pickup_zip' => $data['postal_code'], ':pickup_prefecture' => $data['prefecture'], ':pickup_city' => $data['city'], ':pickup_address1' => $pickup_address, ':box_count' => $data['box_count'], ':register_flg' => $register, ':receive_flg' => $receive, ':campaign_code' => '' === $data['campaign_code'] ? null : $data['campaign_code'], ':campaign_code_status' => $campaign_status, ':pickup_date' => $data['pickup_date'],
		) );
		$request_id = (int) $pdo->lastInsertId();
		$request_code = 'VIS-' . wp_date( 'Ymd' ) . '-' . str_pad( (string) $request_id, 6, '0', STR_PAD_LEFT );
		$update = $pdo->prepare( 'UPDATE purchase_requests SET request_code = :request_code WHERE request_id = :request_id' );
		$update->execute( array( ':request_code' => $request_code, ':request_id' => $request_id ) );
		$confirmed_start = null;
		$confirmed_end = null;
		if ( 1 === count( $data['pickup_times'] ) ) {
			list( $confirmed_start, $confirmed_end ) = $this->slot_range_utc( $data['pickup_date'], $data['pickup_times'][0] );
		}
		$detail = $pdo->prepare( 'INSERT INTO visit_purchase_details (public_token, collection_code, visit_type, latitude, longitude, placement_location, placement_note, nameplate_status, nameplate_name, building_type, access_note, confirmed_start_at, confirmed_end_at, collection_status) VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?)' );
		$detail->execute( array( $public_token, $code, $data['handoff_method'], $data['pickup_latitude'], $data['pickup_longitude'], 'contactless' === $data['handoff_method'] ? $data['pickup_place_details'] : null, $data['nameplate'], 'no' === $data['nameplate'] ? $data['nameplate_name'] : null, $data['building_type'], $data['pickup_notes'], $confirmed_start, $confirmed_end, '回収待ち' ) );

		$slot_insert = $pdo->prepare( 'INSERT INTO visit_pickup_time_slots (public_token, preference_order, start_at, end_at) VALUES (?, ?, ?, ?)' );
		foreach ( $data['pickup_times'] as $index => $slot ) {
			preg_match( '/^(\d{2}:\d{2})〜(\d{2}:\d{2})$/u', $slot, $match );
			$start = new DateTimeImmutable( $data['pickup_date'] . ' ' . $match[1] . ':00', wp_timezone() );
			$end = new DateTimeImmutable( $data['pickup_date'] . ' ' . $match[2] . ':00', wp_timezone() );
			if ( $start >= $end ) {
				throw new DomainException( '回収希望時間帯が不正です。' );
			}
			$utc = new DateTimeZone( 'UTC' );
			$slot_insert->execute( array( $public_token, $index + 1, $start->setTimezone( $utc )->format( 'Y-m-d H:i:s' ), $end->setTimezone( $utc )->format( 'Y-m-d H:i:s' ) ) );
		}
		if ( 'contactless' === $data['handoff_method'] ) {
			$consent = $pdo->prepare( 'INSERT INTO visit_consent_records (public_token, consent_type, consent_version, consent_text, consented_at) VALUES (?, ?, ?, ?, ?)' );
			$consent->execute( array( $public_token, 'contactless_pickup', $data['contactless_consent_version'], $data['contactless_consent_text'], $data['contactless_consented_at_utc'] ) );
		}
		return $public_token;
	}

	public function confirm( WP_REST_Request $request ) {
		if ( ! $this->enabled() ) {
			return $this->response_error( '確認処理を利用できません。', 503 );
		}
		$token = sanitize_text_field( (string) $request->get_param( 'token' ) );
		if ( ! preg_match( '/^[a-f0-9]{64}$/i', $token ) ) {
			return $this->response_error( '確認リンクが無効です。', 400 );
		}
		$pdo = null;
		try {
			$this->assert_same_origin();
			$pdo = $this->db();
			$this->check_schema( $pdo );
			$pdo->beginTransaction();
			$stmt = $pdo->prepare( 'SELECT * FROM visit_application_drafts WHERE confirmation_token_hash = ? FOR UPDATE' );
			$stmt->execute( array( hash( 'sha256', $token, true ) ) );
			$draft = $stmt->fetch();
			if ( ! $draft ) {
				$pdo->rollBack();
				return $this->response_error( '確認リンクが無効または期限切れです。', 410 );
			}
			if ( 'confirmed' === $draft['draft_status'] && ! empty( $draft['public_token'] ) ) {
				$pdo->commit();
				return rest_ensure_response( array( 'status' => 'complete', 'public_token' => $draft['public_token'] ) );
			}
			if ( 'pending' !== $draft['draft_status'] || strtotime( $draft['expires_at'] . ' UTC' ) < time() ) {
				$expired = $pdo->prepare( "DELETE FROM visit_application_drafts WHERE draft_id = ?" );
				$expired->execute( array( $draft['draft_id'] ) );
				$pdo->commit();
				return $this->response_error( '確認リンクが無効または期限切れです。', 410 );
			}
			$public_token = $this->insert_confirmed_application( $pdo, $draft );
			$update = $pdo->prepare( "UPDATE visit_application_drafts SET draft_status = 'confirmed', public_token = ?, confirmation_token_hash = ?, payload_ciphertext = ?, payload_iv = ?, payload_tag = ?, poll_token_ciphertext = ?, poll_token_iv = ?, poll_token_tag = ? WHERE draft_id = ?" );
			$update->execute( array( $public_token, random_bytes( 32 ), random_bytes( 32 ), random_bytes( 12 ), random_bytes( 16 ), random_bytes( 32 ), random_bytes( 12 ), random_bytes( 16 ), $draft['draft_id'] ) );
			$pdo->commit();
			return rest_ensure_response( array( 'status' => 'complete', 'public_token' => $public_token ) );
		} catch ( DomainException $e ) {
			if ( $pdo && $pdo->inTransaction() ) { $pdo->rollBack(); }
			return $this->response_error( $e->getMessage(), 400 );
		} catch ( Throwable $e ) {
			if ( $pdo && $pdo->inTransaction() ) { $pdo->rollBack(); }
			error_log( '[visit-application] confirmation transaction failed' );
			return $this->response_error( '申込を登録できませんでした。時間をおいてお問い合わせください。', 503 );
		}
	}

	public function pending_application( $application, $public_token ) {
		if ( ! $this->enabled() || ! preg_match( '/^[a-f0-9]{64}$/', (string) $public_token ) ) {
			return null;
		}
		try {
			$pdo = $this->db();
			$this->check_schema( $pdo );
			$stmt = $pdo->prepare( "SELECT request_code, last_name, first_name, email, application_city, application_zip, application_prefecture, application_address1, pickup_date, box_count FROM purchase_requests WHERE public_token = ? AND shipping_choice = 'visit' LIMIT 1" );
			$stmt->execute( array( $public_token ) );
			$row = $stmt->fetch();
			if ( ! $row ) { return null; }
			$detail_stmt = $pdo->prepare( 'SELECT collection_code, visit_type, building_type, nameplate_status, nameplate_name, placement_location, access_note FROM visit_purchase_details WHERE public_token = ? LIMIT 1' );
			$detail_stmt->execute( array( $public_token ) );
			$detail = $detail_stmt->fetch();
			if ( ! $detail ) { return null; }
			$slots_stmt = $pdo->prepare( 'SELECT start_at, end_at FROM visit_pickup_time_slots WHERE public_token = ? ORDER BY preference_order ASC' );
			$slots_stmt->execute( array( $public_token ) );
			$slots = array();
			foreach ( $slots_stmt->fetchAll() as $slot ) {
				$slots[] = wp_date( 'n月j日 H:i', strtotime( $slot['start_at'] . ' UTC' ) ) . '〜' . wp_date( 'H:i', strtotime( $slot['end_at'] . ' UTC' ) );
			}
			$building = array( 'detached' => '一軒家', 'multi_unit' => '集合住宅' )[ $detail['building_type'] ] ?? '';
			$nameplate = 'yes' === $detail['nameplate_status'] ? 'あり' : 'なし';
			if ( 'no' === $detail['nameplate_status'] && '' !== (string) $detail['nameplate_name'] ) { $nameplate .= '（' . $detail['nameplate_name'] . '）'; }
			return array(
				'public_token_valid' => true, 'application_status' => 'complete', 'application_number' => (string) $row['request_code'],
				'customer_name' => trim( $row['last_name'] . ' ' . $row['first_name'] ), 'email' => (string) $row['email'],
				'area' => (string) $row['application_city'], 'pickup_date' => (string) $row['pickup_date'], 'pickup_times' => $slots,
				'handoff_method' => (string) $detail['visit_type'],
				'pickup_address' => trim( $row['application_zip'] . ' ' . $row['application_prefecture'] . $row['application_city'] . ' ' . $row['application_address1'] ),
				'building_type' => $building, 'nameplate' => $nameplate, 'pickup_place' => (string) $detail['placement_location'],
				'box_count' => (string) $row['box_count'], 'pickup_notes' => (string) $detail['access_note'], 'pickup_code' => (string) $detail['collection_code'],
			);
		} catch ( Throwable $e ) {
			error_log( '[visit-application] pending lookup failed' );
			return null;
		}
	}
}

register_activation_hook( __FILE__, array( 'Ouchi_Visit_Application_Backend', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Ouchi_Visit_Application_Backend', 'deactivate' ) );
Ouchi_Visit_Application_Backend::boot();
