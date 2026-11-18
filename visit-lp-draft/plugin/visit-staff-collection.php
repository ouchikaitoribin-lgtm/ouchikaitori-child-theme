<?php
/**
 * Staff-only visit application lookup and image upload helpers.
 * This file performs no schema changes and does not send mail.
 */
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Ouchi_Visit_Staff_Collection_Page' ) ) {
	final class Ouchi_Visit_Staff_Collection_Page {
		const TABLE = 'visit_staff_uploads';
		const MAX_FILE_SIZE = 15728640;
		const MAX_COLLECTION_BATCH = 5;
		const MAX_IMAGE_WIDTH = 1600;
		const JPEG_QUALITY = 82;

		private static $document_types = array(
			'drivers_license' => '運転免許証',
			'mynumber' => 'マイナンバーカード',
			'insurance_card' => '健康保険証・資格確認書',
			'passport' => 'パスポート',
			'juki_card' => '写真付き住民基本台帳カード',
			'pension_book' => '年金手帳',
		);

		public static function handle_request() {
			nocache_headers();
			if ( ! headers_sent() ) {
				header( 'Referrer-Policy: no-referrer' );
				header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
			}

			$view = array( 'state' => 'error', 'errors' => array(), 'success' => array(), 'application' => null, 'counts' => array(), 'token' => '' );
			if ( ! is_user_logged_in() ) {
				$login_token = isset( $_GET['token'] ) && is_string( $_GET['token'] ) ? trim( wp_unslash( $_GET['token'] ) ) : '';
				if ( preg_match( '/^[a-f0-9]{64}$/i', $login_token ) ) {
					$view['token'] = strtolower( $login_token );
				}
				$view['state'] = 'login';
				return $view;
			}

			$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET';
			$raw_token = 'POST' === $method ? ( $_POST['token'] ?? '' ) : ( $_GET['token'] ?? '' );
			$token = is_string( $raw_token ) ? trim( wp_unslash( $raw_token ) ) : '';
			if ( ! preg_match( '/^[a-f0-9]{64}$/i', $token ) ) {
				$view['errors'][] = '申込を確認できません。URLのtokenを確認してください。';
				return $view;
			}
			$view['token'] = strtolower( $token );

			try {
				$pdo = self::connect();
				self::assert_upload_table_ready( $pdo );
				$application = self::load_application( $pdo, $view['token'] );
				if ( ! $application ) {
					$view['errors'][] = 'このtokenに一致する出張買取の申し込みは見つかりません。';
					return $view;
				}

				if ( 'POST' === $method ) {
					$view['document_type'] = isset( $_POST['document_type'] ) && is_string( $_POST['document_type'] ) ? sanitize_key( wp_unslash( $_POST['document_type'] ) ) : '';
					$action = isset( $_POST['visit_staff_action'] ) && is_string( $_POST['visit_staff_action'] ) ? sanitize_key( wp_unslash( $_POST['visit_staff_action'] ) ) : '';
					$nonce = isset( $_POST['_wpnonce'] ) && is_string( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
					if ( ! wp_verify_nonce( $nonce, 'visit_staff_upload_' . $action . '_' . $view['token'] ) ) {
						$view['errors'][] = 'ページの有効期限が切れました。再読み込みしてやり直してください。';
					} elseif ( 'identity' === $action ) {
						self::handle_identity_uploads( $pdo, $application, $view['token'], $view['document_type'], $view );
					} elseif ( 'collection' === $action ) {
						self::handle_collection_uploads( $pdo, $application, $view['token'], $view );
					} else {
						$view['errors'][] = 'アップロードの種類を確認できません。';
					}
					$application = self::load_application( $pdo, $view['token'] );
				}

				$view['application'] = $application;
				$view['counts'] = self::load_upload_counts( $pdo, $view['token'] );
				$view['state'] = 'ready';
			} catch ( Throwable $e ) {
				error_log( '[visit-staff] page processing failed' );
				$view['errors'][] = '申し込み情報を読み込めません。システム管理者へお問い合わせください。';
			}
			return $view;
		}

		private static function connect(): PDO {
			if ( function_exists( 'visit_connect_app_db' ) ) {
				return visit_connect_app_db();
			}
			if ( function_exists( 'pdo_connect_app_db' ) ) {
				return pdo_connect_app_db();
			}
			throw new RuntimeException( 'Application database connection is unavailable.' );
		}

		private static function assert_upload_table_ready( PDO $pdo ) {
			$stmt = $pdo->prepare( 'SELECT ENGINE FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1' );
			$stmt->execute( array( self::TABLE ) );
			if ( 'InnoDB' !== $stmt->fetchColumn() ) {
				throw new RuntimeException( 'Staff upload table is unavailable.' );
			}
			$columns_stmt = $pdo->prepare( 'SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?' );
			$columns_stmt->execute( array( self::TABLE ) );
			$columns = array_column( $columns_stmt->fetchAll(), 'column_name' );
			foreach ( array( 'public_token', 'batch_id', 'media_type', 'document_type', 'collection_item_count', 'storage_key', 'mime_type', 'file_size', 'uploaded_by', 'created_at' ) as $column ) {
				if ( ! in_array( $column, $columns, true ) ) {
					throw new RuntimeException( 'Staff upload table schema is incomplete.' );
				}
			}
		}

		private static function load_application( PDO $pdo, $token ) {
			$stmt = $pdo->prepare( "SELECT request_id, public_token, request_code, last_name, first_name, application_zip, application_prefecture, application_city, application_address1, pickup_date, box_count, identity_verification_status FROM purchase_requests WHERE public_token = ? AND shipping_choice = 'visit' LIMIT 1" );
			$stmt->execute( array( $token ) );
			$request = $stmt->fetch();
			if ( ! $request ) {
				return null;
			}

			$detail_stmt = $pdo->prepare( 'SELECT visit_type, collection_code, building_type, nameplate_status, nameplate_name, placement_location, access_note, collection_status FROM visit_purchase_details WHERE public_token = ? LIMIT 1' );
			$detail_stmt->execute( array( $token ) );
			$detail = $detail_stmt->fetch();
			if ( ! $detail ) {
				return null;
			}

			$slot_stmt = $pdo->prepare( 'SELECT start_at, end_at FROM visit_pickup_time_slots WHERE public_token = ? ORDER BY preference_order ASC' );
			$slot_stmt->execute( array( $token ) );
			$slots = array();
			foreach ( $slot_stmt->fetchAll() as $slot ) {
				$slots[] = wp_date( 'n月j日 H:i', strtotime( $slot['start_at'] . ' UTC' ) ) . '〜' . wp_date( 'H:i', strtotime( $slot['end_at'] . ' UTC' ) );
			}

			$address = trim( (string) $request['application_prefecture'] . (string) $request['application_city'] . ' ' . (string) $request['application_address1'] );
			$map_query = trim( '〒' . (string) $request['application_zip'] . ' ' . $address );
			$request['customer_name'] = trim( (string) $request['last_name'] . ' ' . (string) $request['first_name'] );
			$request['address_display'] = $address;
			$request['map_url'] = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $map_query );
			$request['pickup_times'] = $slots;
			$request['visit_type'] = (string) $detail['visit_type'];
			$request['collection_code'] = (string) $detail['collection_code'];
			$request['building_type'] = (string) $detail['building_type'];
			$request['nameplate_status'] = (string) $detail['nameplate_status'];
			$request['nameplate_name'] = (string) $detail['nameplate_name'];
			$request['placement_location'] = (string) $detail['placement_location'];
			$request['access_note'] = (string) $detail['access_note'];
			$request['collection_status'] = (string) $detail['collection_status'];
			return $request;
		}

		private static function load_upload_counts( PDO $pdo, $token ) {
			$stmt = $pdo->prepare( 'SELECT media_type, COUNT(*) AS total FROM ' . self::TABLE . ' WHERE public_token = ? GROUP BY media_type' );
			$stmt->execute( array( $token ) );
			$counts = array( 'identity_front' => 0, 'identity_back' => 0, 'collection_pre_photo' => 0 );
			foreach ( $stmt->fetchAll() as $row ) {
				if ( array_key_exists( $row['media_type'], $counts ) ) {
					$counts[ $row['media_type'] ] = (int) $row['total'];
				}
			}
			return $counts;
		}

		private static function handle_identity_uploads( PDO $pdo, $application, $token, $document_type, &$view ) {
			if ( ! isset( self::$document_types[ $document_type ] ) ) {
				$view['errors'][] = '本人確認書類の種類を選択してください。';
				return;
			}
			$files = array();
			foreach ( array( 'identity_front' => 'identity_front', 'identity_back' => 'identity_back' ) as $field => $media_type ) {
				if ( isset( $_FILES[ $field ] ) && UPLOAD_ERR_NO_FILE !== (int) $_FILES[ $field ]['error'] ) {
					$files[] = array( $media_type, $_FILES[ $field ] );
				}
			}
			if ( ! $files ) {
				$view['errors'][] = '身分証明書の表面または裏面の画像を選択してください。';
				return;
			}
			$batch_id = bin2hex( random_bytes( 16 ) );
			foreach ( $files as $entry ) {
				try {
					self::store_image( $pdo, $token, $batch_id, $entry[0], $document_type, $entry[1] );
					$view['success'][] = ( 'identity_front' === $entry[0] ? '本人確認書類の表面' : '本人確認書類の裏面' ) . 'を保存しました。';
				} catch ( Throwable $e ) {
					$view['errors'][] = ( 'identity_front' === $entry[0] ? '表面' : '裏面' ) . 'の画像を保存できませんでした。画像形式・サイズと保存先の設定をご確認ください。';
				}
			}
		}

		private static function handle_collection_uploads( PDO $pdo, $application, $token, &$view ) {
			if ( 'contactless' !== $application['visit_type'] ) {
				$view['errors'][] = '回収前写真のアップロードは非対面回収の場合のみ利用できます。';
				return;
			}
			$raw_item_count = isset( $_POST['collection_item_count'] ) && is_string( $_POST['collection_item_count'] ) ? trim( wp_unslash( $_POST['collection_item_count'] ) ) : '';
			if ( ! preg_match( '/^\d+$/', $raw_item_count ) || (float) $raw_item_count > 4294967295 ) {
				$view['errors'][] = '回収する荷物の個数を確認してください。';
				return;
			}
			$item_count = (int) $raw_item_count;
			$files = self::normalize_multiple_files( $_FILES['collection_photos'] ?? null );
			if ( count( $files ) < 1 ) {
				$view['errors'][] = '回収前写真を選択してください。';
				return;
			}
			if ( count( $files ) > self::MAX_COLLECTION_BATCH ) {
				$view['errors'][] = '一度にアップロードできる回収前写真は5枚までです。複数回に分けて送信してください。';
				return;
			}
			$batch_id = bin2hex( random_bytes( 16 ) );
			foreach ( $files as $index => $file ) {
				try {
					self::store_image( $pdo, $token, $batch_id, 'collection_pre_photo', null, $file, $item_count );
					$view['success'][] = '回収前写真 ' . ( $index + 1 ) . ' を保存しました。';
				} catch ( Throwable $e ) {
					$view['errors'][] = '回収前写真 ' . ( $index + 1 ) . ' を保存できませんでした。ほかの写真は個別に処理されているため、失敗した写真だけ再度送信できます。';
				}
			}
		}

		private static function normalize_multiple_files( $files ) {
			if ( ! is_array( $files ) || ! isset( $files['name'], $files['tmp_name'], $files['error'], $files['size'] ) || ! is_array( $files['name'] ) ) {
				return array();
			}
			$normalized = array();
			foreach ( $files['name'] as $index => $name ) {
				$error = (int) $files['error'][ $index ];
				if ( UPLOAD_ERR_NO_FILE === $error ) {
					continue;
				}
				$normalized[] = array(
					'name' => (string) $name,
					'tmp_name' => (string) ( $files['tmp_name'][ $index ] ?? '' ),
					'error' => $error,
					'size' => (int) ( $files['size'][ $index ] ?? 0 ),
				);
			}
			return $normalized;
		}

		private static function store_image( PDO $pdo, $token, $batch_id, $media_type, $document_type, $file, $collection_item_count = null ) {
			$directory = self::storage_directory( $media_type, $token );
			$tmp_path = self::compress_to_temporary_jpeg( $file, $directory );
			$filename = bin2hex( random_bytes( 24 ) ) . '.jpg';
			$destination = $directory . DIRECTORY_SEPARATOR . $filename;
			$storage_key = self::storage_key( $media_type, $token, $filename );
			$size = filesize( $tmp_path );
			if ( false === $size || $size > self::MAX_FILE_SIZE ) {
				@unlink( $tmp_path );
				throw new RuntimeException( 'Compressed image is too large.' );
			}
			try {
				$pdo->beginTransaction();
				if ( ! rename( $tmp_path, $destination ) ) {
					throw new RuntimeException( 'Could not store image.' );
				}
				$stmt = $pdo->prepare( 'INSERT INTO ' . self::TABLE . ' (public_token, batch_id, media_type, document_type, collection_item_count, storage_key, mime_type, file_size, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)' );
				$stmt->execute( array( $token, $batch_id, $media_type, $document_type, $collection_item_count, $storage_key, 'image/jpeg', (int) $size, get_current_user_id() ) );
				$pdo->commit();
			} catch ( Throwable $e ) {
				if ( $pdo->inTransaction() ) {
					$pdo->rollBack();
				}
				if ( file_exists( $destination ) ) {
					@unlink( $destination );
				}
				if ( file_exists( $tmp_path ) ) {
					@unlink( $tmp_path );
				}
				throw $e;
			}
		}

		private static function compress_to_temporary_jpeg( $file, $directory ) {
			if ( ! is_array( $file ) || UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
				throw new RuntimeException( 'Upload failed.' );
			}
			$tmp = (string) ( $file['tmp_name'] ?? '' );
			$size = is_file( $tmp ) ? filesize( $tmp ) : false;
			if ( ! $tmp || ! is_uploaded_file( $tmp ) || false === $size || $size > self::MAX_FILE_SIZE ) {
				throw new RuntimeException( 'Upload size or temporary file is invalid.' );
			}
			$finfo = finfo_open( FILEINFO_MIME_TYPE );
			$mime = $finfo ? finfo_file( $finfo, $tmp ) : '';
			if ( $finfo ) {
				finfo_close( $finfo );
			}
			$image_info = @getimagesize( $tmp );
			if ( ! $image_info || ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp' ), true ) || $mime !== $image_info['mime'] ) {
				throw new RuntimeException( 'Unsupported image type.' );
			}
			$width = (int) $image_info[0];
			$height = (int) $image_info[1];
			if ( $width < 1 || $height < 1 || $width > 12000 || $height > 12000 || ( $width * $height ) > 40000000 ) {
				throw new RuntimeException( 'Image dimensions are invalid.' );
			}
			switch ( $mime ) {
				case 'image/jpeg': $source = @imagecreatefromjpeg( $tmp ); break;
				case 'image/png': $source = @imagecreatefrompng( $tmp ); break;
				case 'image/webp': $source = function_exists( 'imagecreatefromwebp' ) ? @imagecreatefromwebp( $tmp ) : false; break;
				default: $source = false;
			}
			if ( ! $source ) {
				throw new RuntimeException( 'Image decoder is unavailable.' );
			}

			$new_width = min( $width, self::MAX_IMAGE_WIDTH );
			$new_height = (int) round( $height * ( $new_width / $width ) );
			$destination = imagecreatetruecolor( $new_width, $new_height );
			$white = imagecolorallocate( $destination, 255, 255, 255 );
			imagefill( $destination, 0, 0, $white );
			imagecopyresampled( $destination, $source, 0, 0, 0, 0, $new_width, $new_height, $width, $height );
			$temp_path = tempnam( $directory, '.visit-upload-' );
			if ( false === $temp_path || ! imagejpeg( $destination, $temp_path, self::JPEG_QUALITY ) ) {
				imagedestroy( $source );
				imagedestroy( $destination );
				if ( $temp_path && file_exists( $temp_path ) ) { @unlink( $temp_path ); }
				throw new RuntimeException( 'Could not compress image.' );
			}
			@chmod( $temp_path, 0600 );
			imagedestroy( $source );
			imagedestroy( $destination );
			return $temp_path;
		}

		private static function storage_directory( $media_type, $token ) {
			$uploads = wp_upload_dir();
			if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
				throw new RuntimeException( 'Upload directory is unavailable.' );
			}
			$base = 'collection_pre_photo' === $media_type
				? trailingslashit( $uploads['basedir'] ) . 'visit-collection-photos'
				: trailingslashit( $uploads['basedir'] ) . 'identity-docs/visit-staff';
			$request_dir = trailingslashit( $base ) . hash( 'sha256', $token );
			foreach ( array( $base, $request_dir ) as $dir ) {
				if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
					throw new RuntimeException( 'Upload directory could not be created.' );
				}
				@chmod( $dir, 0700 );
				$permissions = @fileperms( $dir );
				if ( false === $permissions || 0 !== ( $permissions & 0077 ) ) {
					throw new RuntimeException( 'Upload directory permissions are not private.' );
				}
				self::protect_directory( $dir );
			}
			return $request_dir;
		}

		private static function protect_directory( $directory ) {
			$rules = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n";
			$htaccess = trailingslashit( $directory ) . '.htaccess';
			if ( ! is_file( $htaccess ) ) {
				if ( false === file_put_contents( $htaccess, $rules, LOCK_EX ) ) {
					throw new RuntimeException( 'Private upload access rule could not be written.' );
				}
				@chmod( $htaccess, 0640 );
			} else {
				$current = file_get_contents( $htaccess );
				if ( false === $current || ( ! preg_match( '/^\s*Require\s+all\s+denied\s*$/im', $current ) && ! preg_match( '/^\s*Deny\s+from\s+all\s*$/im', $current ) ) ) {
					throw new RuntimeException( 'Private upload access rule is not present.' );
				}
			}
			$index = trailingslashit( $directory ) . 'index.php';
			if ( ! is_file( $index ) ) {
				file_put_contents( $index, "<?php\nhttp_response_code( 404 );\nexit;\n", LOCK_EX );
			}
		}

		private static function storage_key( $media_type, $token, $filename ) {
			$prefix = 'collection_pre_photo' === $media_type ? 'visit-collection-photos' : 'identity-docs/visit-staff';
			return $prefix . '/' . hash( 'sha256', $token ) . '/' . $filename;
		}
	}
}
