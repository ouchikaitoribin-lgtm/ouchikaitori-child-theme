const { readFileSync } = require('node:fs');
const { join } = require('node:path');
const test = require('node:test');
const assert = require('node:assert/strict');

const root = join(__dirname, '..');
const backend = readFileSync(join(root, 'visit-lp-draft/plugin/visit-application-backend.php'), 'utf8');
const migration = readFileSync(join(root, 'visit-lp-draft/migrations/003-visit-application-email-outbox.sql'), 'utf8');
const confirmationCodeMigration = readFileSync(join(root, 'visit-lp-draft/migrations/005-visit-confirmation-code-attempts.sql'), 'utf8');
const stagingMailGuard = readFileSync(join(root, 'visit-lp-draft/plugin/visit-staging-mailpit-transport.php'), 'utf8');
const functions = readFileSync(join(root, 'functions.php'), 'utf8');
const bookingScript = readFileSync(join(root, 'visit-lp-draft/visit-booking.js'), 'utf8');
const bookingWidget = readFileSync(join(root, 'visit-lp-draft/visit/kyoto-city/booking-widget.html'), 'utf8');
const landingPage = readFileSync(join(root, 'page-visit.php'), 'utf8');
const landingCss = readFileSync(join(root, 'visit-lp-draft/visit.css'), 'utf8');
const sharedVisitFaq = readFileSync(join(root, 'visit-lp-draft/visit/faq-common.php'), 'utf8');
const page = readFileSync(join(root, 'page-visit-kyoto-city.php'), 'utf8');
const pendingPage = readFileSync(join(root, 'page-visit-kyoto-city-application-pending.php'), 'utf8');
const pendingScript = readFileSync(join(root, 'visit-lp-draft/visit/kyoto-city/application-pending/pending.js'), 'utf8');
const staffPage = readFileSync(join(root, 'page-visit-staff-collection.php'), 'utf8');
const staffBackend = readFileSync(join(root, 'visit-lp-draft/plugin/visit-staff-collection.php'), 'utf8');
const staffMigration = readFileSync(join(root, 'visit-lp-draft/migrations/004-visit-staff-uploads.sql'), 'utf8');
const staffScript = readFileSync(join(root, 'visit-lp-draft/visit/staff-collection/staff.js'), 'utf8');

test('expired drafts are not automatically deleted', () => {
  assert.doesNotMatch(backend, /\bDELETE\s+FROM\b/i);
  assert.doesNotMatch(backend, /ouchi_visit_cleanup_expired_drafts',\s*array\(\s*\$this,\s*'cleanup_expired_drafts'/);
  assert.doesNotMatch(backend, /wp_schedule_event\([^;]*ouchi_visit_cleanup_expired_drafts/s);
  assert.match(backend, /UPDATE visit_application_confirmation_outbox SET delivery_status = 'pending'/);
});

test('visit email is restricted to the staging test recipient', () => {
  assert.match(backend, /'staging' !== wp_get_environment_type\(\)/);
  assert.match(backend, /VISIT_CONFIRMATION_TEST_RECIPIENT/);
  assert.match(backend, /ouchi_visit_staging_send_test_mail\(\s*\$recipient/);
  assert.match(stagingMailGuard, /strtolower\(\s*\$allowed\s*\)\s*!==\s*strtolower\(\s*\$recipient\s*\)/);
  assert.equal((stagingMailGuard.match(/\bwp_mail\s*\(/g) || []).length, 1);
  assert.doesNotMatch(backend, /\bwp_mail\s*\(/);
  assert.doesNotMatch(backend, /wp_mail\(\s*\$data\[['"]email['"]\]/);
});

test('completion email has a durable outbox record and sends after transaction commit', () => {
  assert.match(migration, /UNIQUE KEY uq_visit_email_public_token \(public_token\)/);
  assert.match(migration, /FOREIGN KEY \(public_token\) REFERENCES purchase_requests \(public_token\)/);
  assert.match(backend, /INSERT INTO visit_application_email_outbox/);
  const confirm = backend.slice(backend.indexOf('public function confirm('), backend.indexOf('public function pending_application('));
  assert.ok(confirm.indexOf('$pdo->commit();') < confirm.indexOf('$this->dispatch_completion_email('));
  assert.match(backend, /delivery_status = 'pending'.*delivery_status = 'sending'/s);
  assert.match(backend, /delivery_status = 'sent'/);
});

test('email confirmation requires a four-digit code and limits each draft to five attempts', () => {
  assert.match(confirmationCodeMigration, /ADD COLUMN IF NOT EXISTS verification_attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0/);
  assert.doesNotMatch(confirmationCodeMigration, /DROP\s+|DELETE\s+FROM|UPDATE\s+.+\s+SET/i);
  assert.match(backend, /'verification_attempt_count'/);
  assert.match(backend, /hash_hmac\( 'sha256',[\s\S]*?visit-email-confirmation-code/);
  assert.match(backend, /確認番号：.*confirmation_code/s);
  const confirm = backend.slice(backend.indexOf('public function confirm('), backend.indexOf('public function pending_application('));
  assert.match(confirm, /SELECT \* FROM visit_application_drafts WHERE confirmation_token_hash = \? FOR UPDATE/);
  assert.match(confirm, /verification_attempt_count/);
  assert.match(confirm, /attempt_count >= 5/);
  assert.match(confirm, /UPDATE visit_application_drafts SET verification_attempt_count = \?/);
  assert.match(confirm, /hash_equals\( \$expected_code, \$code \)/);
  assert.match(confirm, /\$pdo->commit\(\);[\s\S]*?確認番号が一致しません/);
  assert.match(backend, /name=\"code\" type=\"text\" inputmode=\"numeric\"/);
  assert.match(backend, /pattern=\"\[0-9\]\{4\}\" maxlength=\"4\"/);
  assert.match(backend, /JSON\.stringify\(\{token:this\.elements\.token\.value,code:this\.elements\.code\.value\}\)/);
});

test('provisional confirmation mail uses production copy with an explicit test marker', () => {
  assert.match(backend, /send_staging_test_mail\( '【おうち買取便】出張買取のお申し込み確認（テスト）', \$body \)/);
  assert.match(backend, /メールアドレスの確認を完了すると、お申し込み内容が登録されます。/);
  assert.match(backend, /4桁の確認番号を入力して送信してください。/);
  assert.match(backend, /確認番号の有効期限は、お申し込みから5時間です。/);
  assert.match(backend, /メール：support@ouchikaitori\.jp/);
  assert.match(backend, /これはテストメールです\"/);
  assert.doesNotMatch(backend, /ステージング環境の出張買取メール確認テストです。/);
  assert.doesNotMatch(backend, /このメールは固定テスト宛先にのみ送信されています/);
});

test('confirmation mail failure retains an encrypted one-time token for retry', () => {
  assert.match(migration, /CREATE TABLE IF NOT EXISTS visit_application_confirmation_outbox/);
  assert.match(migration, /FOREIGN KEY \(draft_id\) REFERENCES visit_application_drafts \(draft_id\)/);
  assert.match(backend, /encrypt_private_token\(\s*\$confirmation_token\s*\)/);
  assert.match(backend, /INSERT INTO visit_application_confirmation_outbox/);
  assert.match(backend, /SELECT o\.region_slug,[\s\S]*?confirmation_token_ciphertext[\s\S]*?FOR UPDATE/);
  assert.match(backend, /UPDATE visit_application_confirmation_outbox SET delivery_status = 'pending'/);
  assert.match(backend, /delivery_status = 'consumed',[\s\S]*?confirmation_token_ciphertext = \?/);
  assert.match(backend, /\$this->dispatch_confirmation_email\(\s*\$row\['draft_id'\]\s*\)/);
});

test('completion message includes submitted form details, masks account number and omits consent notices', () => {
  const bodyBuilder = backend.slice(backend.indexOf('private function completion_email_body('), backend.indexOf('private function dispatch_completion_email('));
  for (const field of [
    'payment_method', 'last_name_kana', 'first_name_kana', 'birth_date', 'occupation', 'application_zip',
    'application_prefecture', 'application_city', 'application_address1', 'bank_code',
    'bank_name', 'branch_code', 'branch_name', 'account_type', 'account_number_last4',
    'account_holder_kana_enc', 'box_count', 'campaign_code', 'register_flg', 'approval_flg',
    'receive_flg', 'latitude', 'longitude', 'nameplate_status', 'nameplate_name', 'building_type', 'access_note'
  ]) {
    assert.match(bodyBuilder, new RegExp(field), `completion mail should include ${field}`);
  }
  assert.match(bodyBuilder, /口座番号：xxx['"]?\s*\.\s*\$row\[['"]account_number_last4['"]\]/);
  assert.doesNotMatch(bodyBuilder, /\$row\[['"]account_number['"]\]/);
  assert.match(bodyBuilder, /査定結果を自動承認する/);
  assert.match(bodyBuilder, /査定結果を確認してから承認する/);
  assert.match(bodyBuilder, /会員登録：/);
  assert.match(bodyBuilder, /お知らせメール配信：/);
  assert.match(bodyBuilder, /collection_code/);
  assert.doesNotMatch(bodyBuilder, /consent_text|consent_query|確認・同意いただいた注意事項/);
  assert.match(backend, /private function decrypt_encrypted_field\(/);
});

test('the page and API share the same contactless terms helpers', () => {
  assert.match(backend, /function visit_booking_get_contactless_terms_text\(\)/);
  assert.match(backend, /function visit_booking_get_contactless_terms_version\(\)/);
  assert.match(page, /visit_booking_get_contactless_terms_text\(\)/);
  assert.match(page, /visit_booking_get_contactless_terms_version\(\)/);
  assert.match(backend, /hash_equals\s*\(\s*\(string\)\s*\$current_version/);
  assert.match(backend, /'' === \$text \|\| '' === \$version/);
  const pageFallback = page.match(/\?\s*visit_booking_get_contactless_terms_text\(\)\s*:\s*"([\s\S]*?)";/);
  const apiFallback = backend.match(/function visit_booking_get_contactless_terms_text\(\)\s*\{\s*return\s+"([\s\S]*?)";/);
  assert.ok(pageFallback && apiFallback, 'both fallback terms should be present');
  assert.equal(pageFallback[1], apiFallback[1]);
  assert.match(page, /:\s*'contactless-v3'/);
  assert.match(backend, /return 'contactless-v3'/);
});

test('contactless placement text is bounded before database insertion', () => {
  const validation = backend.slice(backend.indexOf("$data['pickup_place_details'] = sanitize_text_field"), backend.indexOf("$data['nameplate_name'] = sanitize_text_field"));
  assert.match(validation, /'contactless' === \$data\['handoff_method'\]/);
  assert.match(validation, /mb_strlen\( \$data\['pickup_place_details'\], 'UTF-8' \) > 240/);
  assert.match(validation, /throw new DomainException/);
});

test('appraisal approval choice is required and maps the existing 0/1 values to approval_flg', () => {
  assert.match(bookingWidget, /name="approval_choice" value="1" required/);
  assert.match(bookingWidget, /name="approval_choice" value="0" required/);
  assert.match(backend, /'approval_choice'/);
  assert.match(backend, /! in_array\( \$data\['approval_choice'\], array\( '0', '1' \), true \)/);
  assert.match(backend, /\$approval = '1' === \$data\['approval_choice'\] \? 1 : 0/);
  assert.match(backend, /approval_flg, receive_flg[\s\S]*?:approval_flg, :receive_flg/);
  assert.match(backend, /':approval_flg' => \$approval/);
});

test('application contents and pickup location are separate in-form steps', () => {
  assert.match(bookingWidget, /data-step="6"[\s\S]*?集荷場所[\s\S]*?name="pickup_latitude"[\s\S]*?data-next-step="7"/);
  assert.match(bookingWidget, /data-step="7"[\s\S]*?お申込み内容[\s\S]*?name="box_count"[\s\S]*?name="approval_choice"/);
  assert.match(bookingScript, /step > 7/);
  assert.match(bookingScript, /finalSubmitButton\.disabled = step !== 7/);
  assert.match(bookingScript, /for \(const step of \[4, 5, 6, 7\]\)/);
});

test('privacy policy is read in place from the existing WordPress policy post', () => {
  assert.match(bookingWidget, /data-open-privacy-policy/);
  assert.match(bookingWidget, /VISIT_PRIVACY_POLICY_DIALOG/);
  assert.match(page, /get_post_field\( 'post_content', 59 \)/);
  assert.match(page, /apply_filters\( 'the_content', \$privacy_policy_content \)/);
  assert.match(page, /wp_kses_post\(/);
  assert.match(bookingScript, /data-privacy-policy-dialog/);
  assert.match(bookingScript, /data-privacy-dialog-close/);
  assert.match(bookingScript, /'Escape' === event\.key/);
  assert.match(bookingWidget, /name="privacy_agreement" required/);
});

test('application pending page shows only requested summary fields with Japanese handoff labels', () => {
  for (const field of ['application_number', 'area', 'pickup_date', 'pickup_times', 'handoff_method', 'placement_location']) {
    assert.match(pendingPage, new RegExp(`data-pending-field="${field}"`));
  }
  for (const field of ['customer_name', 'email', 'pickup_address', 'building_type', 'nameplate', 'pickup_place', 'box_count', 'pickup_notes']) {
    assert.doesNotMatch(pendingPage, new RegExp(`data-pending-field="${field}"`));
  }
  assert.match(pendingPage, /その他のお申込内容については送付したメールをご確認ください。/);
  assert.match(pendingPage, /荷物ごとに同じコードを、外側から確認できる分かりやすい位置に記載してください。/);
  assert.match(pendingScript, /value === 'face' \? '対面' : value === 'contactless' \? '非対面'/);
  assert.match(pendingScript, /key === 'pickup_date'/);
  assert.match(backend, /SELECT request_code, application_city, pickup_date FROM purchase_requests/);
  assert.match(backend, /wp_date\( 'H:i', strtotime\( \$slot\['start_at'\] \. ' UTC' \) \)/);
  assert.match(backend, /wp_date\( 'H:i', strtotime\( \$slot\['end_at'\] \. ' UTC' \) \)/);
  assert.match(pendingPage, /pending\.css'.*'1\.1\.3'/);
  assert.match(pendingPage, /pending\.js'.*'1\.1\.5'/);
  assert.match(pendingPage, /data-pending-handoff="contactless"[^>]*hidden/);
  assert.match(pendingPage, /<div><dt>受け渡し方法<\/dt><dd data-pending-field="handoff_method">[\s\S]*?<div data-pending-handoff="contactless" hidden><dt>荷物の置き場所<\/dt><dd data-pending-field="placement_location">/);
  assert.match(pendingPage, /'pickup_code', 'placement_location'/);
  assert.match(backend, /SELECT collection_code, visit_type, placement_location FROM visit_purchase_details/);
  assert.match(backend, /if \( 'contactless' === \$detail\['visit_type'\] \) \{[\s\S]*?\$application\['placement_location'\]/);
  assert.match(pendingPage, /<li data-pending-handoff="face" hidden>[\s\S]*?本人確認書類の原本を準備する/);
  assert.match(pendingPage, /回収時間までに荷物を梱包する/);
  assert.match(pendingPage, /荷物を指定の場所に置く/);
  assert.match(pendingPage, /可能な限り回収時間の直前に置いてください。/);
  assert.match(pendingPage, /data-pending-handoff="contactless" hidden><span>03<\/span>[\s\S]*?すべての荷物に回収コードを付ける[\s\S]*?<li class="visit-pending-place-card" data-pending-handoff="contactless" hidden><span>04<\/span>[\s\S]*?荷物を指定の場所に置く/);
  assert.match(pendingScript, /dataset\.pendingHandoff !== handoff/);
  assert.match(pendingScript, /handoff === 'face' \? '回収までの準備' : '荷物の置き方・準備'/);
  assert.match(backend, /if \( 'contactless' === \$detail\['visit_type'\] \) \{\s*\$application\['pickup_code'\]/);
});

test('supported municipality cards link to their configured regional pages visibly', () => {
  assert.match(landingPage, /'url' => home_url\( '\/visit\/' \. rawurlencode\( \(string\) \$visit_region_slug \) \. '\/' \)/);
  assert.match(landingPage, /if \( ! empty\( \$visit_city\['url'\] \) \) : \?>[\s\S]*?<a class="visit-city-detail-link" href="<\?php echo esc_url\( \$visit_city\['url'\] \); \?>"[\s\S]*?対応地域ページを見る/);
  assert.match(landingPage, /else : \?>\s*<\?php echo esc_html\( \$visit_city\['name'\] \. '（' \. \$visit_city\['coverage'\] \. '）' \); \?>/);
  assert.match(landingPage, /ouchi-visit-lp'.*'1\.0\.2'/);
  assert.match(landingCss, /\.visit-city-detail-link::after\s*\{[^}]*content:\s*"→"/);
  assert.match(landingCss, /\.visit-city-detail-link:focus-visible/);
});

test('visit and all area pages render common FAQ entries from one shared source', () => {
  for (const template of [landingPage, page]) {
    assert.match(template, /\$visit_common_faq_items = require get_stylesheet_directory\(\) \. '\/visit-lp-draft\/visit\/faq-common\.php'/);
    assert.match(template, /foreach \( \$visit_common_faq_items as \$visit_faq_item \)/);
    assert.match(template, /esc_html\( \$visit_faq_item\['question'\] \)/);
    assert.match(template, /esc_html\( \$visit_faq_item\['answer'\] \)/);
  }
  assert.match(landingPage, /今はどの地域で利用できますか？/);
  assert.match(page, /<\?php echo esc_html\( \$visit_city_name \); \?>のどの地域が対応していますか？/);
  assert.match(sharedVisitFaq, /'question' => '非対面での回収はできますか？'/);
  assert.match(sharedVisitFaq, /'question' => '予約はどこからできますか？'/);
  assert.doesNotMatch(page, /非対面回収では玄関のドアを開けますか？|申し込みはどのように完了しますか？/);
});

test('visit terms agreement displays the existing WordPress terms post 57 in place', () => {
  assert.match(bookingWidget, /name="terms_agreement" required><span>利用規約を確認しました。<\/span>/);
  assert.match(bookingWidget, /data-open-visit-terms[\s\S]*aria-controls="visit-terms-dialog"/);
  assert.match(bookingWidget, /VISIT_TERMS_DIALOG/);
  assert.match(page, /get_post_field\( 'post_content', 57 \)/);
  assert.match(page, /apply_filters\( 'the_content', \$visit_terms_content \)/);
  assert.match(page, /data-visit-terms-dialog/);
  assert.match(bookingScript, /bindPolicyDialog\(visitTermsOpenButton, visitTermsDialog\)/);
});

test('API registration is staging-gated and does not call collection services', () => {
  assert.match(backend, /'staging' === wp_get_environment_type\(\)/);
  assert.match(backend, /if \( ! \$this->database_ready\(\) \)\s*\{\s*return;\s*\}/);
  assert.doesNotMatch(backend, /wp_remote_(?:get|post|request)\s*\(|curl_(?:init|exec)\s*\(/);
});

test('staging only sends explicitly authorized visit mail through the parent SES setup', () => {
  assert.match(functions, /'staging' === wp_get_environment_type\(\)[\s\S]*?visit-staging-mailpit-transport\.php/);
  assert.match(stagingMailGuard, /add_filter\([\s\S]*?'pre_wp_mail'/);
  assert.match(stagingMailGuard, /return false;/);
  assert.match(stagingMailGuard, /ouchi_visit_staging_send_test_mail/);
  assert.match(stagingMailGuard, /has_action\( 'phpmailer_init', 'my_smtp_mailer' \)/);
  assert.ok(stagingMailGuard.includes('email-smtp') && stagingMailGuard.includes('amazonaws'));
  assert.match(stagingMailGuard, /getToAddresses\(\)[\s\S]*?getCcAddresses\(\)[\s\S]*?getBccAddresses\(\)/);
  assert.match(stagingMailGuard, /PHP_INT_MAX/);
  assert.doesNotMatch(stagingMailGuard, /127\.0\.0\.1|Port\s*=\s*1025|->isSMTP\(\)|isMail\s*\(/);
  assert.match(backend, /ouchi_visit_staging_send_test_mail\(\s*\$recipient,\s*\$subject,\s*\$body\s*\)/);
  assert.doesNotMatch(backend, /OUCHI-VISIT-STAGING|wp_mail\(\s*\$recipient/);
});

test('regional form captures the backend readiness flag for frontend submission', () => {
  assert.match(page, /static function \(\) use \([^)]*\$visit_application_backend_enabled/);
  assert.match(page, /'applicationSubmissionEnabled'\s*=>\s*\(bool\)\s*\$visit_application_backend_enabled/);
  assert.match(page, /'formPreviewMode'\s*=>\s*\(bool\)\s*\$visit_form_preview_mode/);
});

test('regional form passes its slug to live availability requests', () => {
  assert.match(page, /static function \(\) use \([^)]*\$visit_region_slug/);
  assert.match(page, /'regionSlug'\s*=>\s*\$visit_region_slug/);
  assert.match(bookingScript, /endpoint\.searchParams\.set\('region_slug', String\(bookingConfig\.regionSlug \|\| ''\)\)/);
  assert.match(bookingScript, /if \(!applicationFlowReady \|\| !bookingConfig\.availabilityEndpoint \|\| !state\.area\) return/);
});
test('frontend accepts boolean, numeric, and string staging submission flags', () => {
  assert.match(bookingScript, /submissionFlag === true \|\| submissionFlag === 1 \|\| submissionFlag === '1'/);
  assert.match(bookingScript, /&& isApplicationSubmissionEnabled/);
  assert.doesNotMatch(bookingScript, /bookingConfig\.applicationSubmissionEnabled === true/);
});

test('application payload separates the selected area and derives city from region data', () => {
  assert.ok(page.includes("'cityName' => $visit_city_name"));
  assert.ok(page.includes('$visit_address_prefix, $visit_city_name, $visit_prefecture_name'));
  assert.ok(bookingScript.includes("const cityName = String(bookingConfig.cityName || regionName)"));
  assert.ok(bookingScript.includes("widget.querySelector('[data-form-area]').value = state.area;"));
  assert.ok(bookingScript.includes('cityField.value = '));
  assert.ok(bookingScript.includes("setValue('city', "));
});

test('contactless consent is included in the application form payload', () => {
  assert.match(bookingWidget, /<input type="hidden" name="contactless_consent" value="">/);
  assert.match(bookingWidget, /name="pickup_place_details" data-pickup-place-details maxlength="240"/);
  assert.match(bookingScript, /form\.elements\.contactless_consent\.value = state\.handoff === 'contactless' && consentBox\.checked \? 'yes' : '';/);
  assert.match(backend, /'yes' !== \$data\['contactless_consent'\] \|\| '' === \$data\['pickup_place_details'\]/);
});

test('application submission refreshes the WordPress nonce without using cached HTML values', () => {
  assert.ok(backend.includes("self::API_NAMESPACE . '/nonce'"));
  assert.ok(backend.includes("array( 'nonce' => wp_create_nonce( 'visit_application_submit' ) )"));
  assert.ok(backend.includes("Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0"));
  assert.ok(page.includes("'applicationNonceEndpoint' => apply_filters( 'visit_application_nonce_endpoint', '' )"));
  assert.ok(bookingScript.includes("cache: 'no-store'"));
  assert.ok(bookingScript.includes('form.elements.visit_application_nonce.value = nonceResult.nonce'));
});

test('staging application test prefills safe sample data and enables submit only on final step', () => {
  assert.ok(bookingScript.includes('const isStagingTestDefaults = isStagingFormPreview || applicationFlowReady'));
  assert.ok(bookingScript.includes('if (!isStagingTestDefaults) return;'));
  assert.ok(bookingScript.includes('finalSubmitButton.disabled = step !== 7 || !isStagingTestDefaults'));
  assert.ok(bookingScript.includes('day && day.available && Array.isArray(day.slots)'));
  assert.ok(bookingScript.includes("new Option('サンプル銀行（テスト用）', '9999')"));
});

test('visit booking script cache version is bumped for the staging form fix', () => {
  assert.ok(page.includes("wp_enqueue_script( 'ouchi-visit-booking-ui', $asset_uri . 'visit-booking.js', $booking_script_dependencies, '1.8.8', true );"));
});

test('bank details use AES-256-GCM with 32-byte keys, 12-byte IVs and 16-byte tags', () => {
  assert.match(backend, /32\s*===\s*strlen\(\s*\$key\s*\)/);
  assert.match(backend, /\$iv = random_bytes\(\s*12\s*\)/);
  assert.match(backend, /openssl_encrypt\([^;]*'aes-256-gcm'/s);
  assert.match(backend, /16\s*!==\s*strlen\(\s*\$tag\s*\)/);
  assert.match(backend, /openssl_decrypt\([^;]*'aes-256-gcm'/s);
});

test('single-slot reservations use a date lock and confirmation rolls back on failure', () => {
  assert.match(backend, /INSERT IGNORE INTO visit_slot_reservation_day_locks/);
  assert.match(backend, /visit_slot_reservation_day_locks WHERE service_date = \? FOR UPDATE/);
  const confirm = backend.slice(backend.indexOf('public function confirm('), backend.indexOf('public function pending_application('));
  assert.match(confirm, /catch \( Throwable \$e \)[\s\S]*?\$pdo->rollBack\(\)/);
  assert.match(backend, /count\( \$data\['pickup_times'\] \) === 1|1 === count\( \$data\['pickup_times'\] \)/);
});

test('staff collection page requires WordPress login and resolves only visit tokens', () => {
  assert.match(staffBackend, /if \( ! is_user_logged_in\(\) \)/);
  assert.match(staffBackend, /status_header\( 404 \);\s*wp_die\( 'ページが見つかりません。', 'ページが見つかりません', array\( 'response' => 404 \) \)/);
  assert.match(staffBackend, /\^\[a-f0-9\]\{64\}\$/i);
  assert.match(staffBackend, /WHERE public_token = \? AND shipping_choice = 'visit'/);
  assert.match(staffBackend, /wp_verify_nonce\(/);
  assert.match(staffPage, /Template Name: 出張買取スタッフ用 集荷ページ/);
  assert.doesNotMatch(staffPage, /ログインが必要です|WordPressログイン/);
  assert.doesNotMatch(staffBackend, /\bwp_mail\s*\(|UPDATE purchase_requests|(?:FROM|INTO|UPDATE)\s+identity_uploads/);
});

test('staff uploads are compressed, private, incremental, and migration is additive', () => {
  assert.match(staffBackend, /MAX_COLLECTION_BATCH = 5/);
  assert.match(staffBackend, /collection_item_count, storage_key, mime_type/);
  assert.match(staffBackend, /imagejpeg\( \$destination, \$temp_path, self::JPEG_QUALITY \)/);
  assert.match(staffBackend, /Require all denied/);
  assert.match(staffBackend, /0 !== \( \$permissions & 0077 \)/);
  assert.match(staffBackend, /\$pdo->beginTransaction\(\)/);
  assert.match(staffBackend, /\$pdo->rollBack\(\)/);
  assert.match(staffScript, /canvas\.toBlob\(resolve, 'image\/jpeg', 0\.82\)/);
  assert.match(staffPage, /staff\.js[^\r\n]*'1\.0\.5'/);
  assert.match(staffScript, /selectedPhotoInputs\.length > maxPhotos/);
  assert.match(staffScript, /getOtherFormWithPendingFiles/);
  assert.match(staffScript, /form\.addEventListener\('click'/);
  assert.match(staffScript, /showConflictActionPanel/);
  assert.doesNotMatch(staffScript, /window\.confirm/);
  assert.match(staffScript, /もう一方の画像をアップロード/);
  assert.match(staffScript, /もう一方の画像を削除/);
  assert.match(staffPage, /disabled\( \$visit_staff_identity_front_count > 0 \)/);
  assert.match(staffScript, /data-clear-upload-files/);
  assert.match(staffPage, /本人確認書類はこのフォームから送信済みです/);
  assert.match(staffBackend, /重複保存しませんでした/);
  assert.match(staffScript, /form\.addEventListener\('change'/);
  assert.match(staffMigration, /CREATE TABLE IF NOT EXISTS visit_staff_uploads/);
  assert.match(staffMigration, /public_token CHAR\(64\) NOT NULL/);
  assert.match(staffMigration, /collection_item_count INT UNSIGNED NULL/);
  assert.match(staffMigration, /FOREIGN KEY \(public_token\) REFERENCES purchase_requests \(public_token\)/);
  assert.match(staffMigration, /ON DELETE RESTRICT ON UPDATE RESTRICT/);
  assert.doesNotMatch(staffMigration, /^\s*(?:ALTER|DROP|UPDATE|DELETE)\s+/im);
});
