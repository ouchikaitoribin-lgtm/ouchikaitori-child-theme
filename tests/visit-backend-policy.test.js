const { readFileSync } = require('node:fs');
const { join } = require('node:path');
const test = require('node:test');
const assert = require('node:assert/strict');

const root = join(__dirname, '..');
const backend = readFileSync(join(root, 'visit-lp-draft/plugin/visit-application-backend.php'), 'utf8');
const migration = readFileSync(join(root, 'visit-lp-draft/migrations/003-visit-application-email-outbox.sql'), 'utf8');
const mailpitTransport = readFileSync(join(root, 'visit-lp-draft/plugin/visit-staging-mailpit-transport.php'), 'utf8');
const functions = readFileSync(join(root, 'functions.php'), 'utf8');
const bookingScript = readFileSync(join(root, 'visit-lp-draft/visit-booking.js'), 'utf8');
const page = readFileSync(join(root, 'page-visit-kyoto-city.php'), 'utf8');

test('expired drafts are not automatically deleted', () => {
  assert.doesNotMatch(backend, /\bDELETE\s+FROM\b/i);
  assert.doesNotMatch(backend, /ouchi_visit_cleanup_expired_drafts',\s*array\(\s*\$this,\s*'cleanup_expired_drafts'/);
  assert.doesNotMatch(backend, /wp_schedule_event\([^;]*ouchi_visit_cleanup_expired_drafts/s);
  assert.match(backend, /UPDATE visit_application_confirmation_outbox SET delivery_status = 'pending'/);
});

test('visit email is restricted to the staging test recipient', () => {
  assert.match(backend, /'staging' !== wp_get_environment_type\(\)/);
  assert.match(backend, /VISIT_CONFIRMATION_TEST_RECIPIENT/);
  assert.match(backend, /\$args\['to'\]\s*=\s*array\(\s*\$recipient\s*\)/);
  assert.match(backend, /\$args\['headers'\]\s*=\s*array\(\s*'Content-Type: text\/plain; charset=UTF-8'\s*\)/);
  assert.equal((backend.match(/\bwp_mail\s*\(/g) || []).length, 1);
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

test('completion message omits bank and identity document data', () => {
  const bodyBuilder = backend.slice(backend.indexOf('private function completion_email_body('), backend.indexOf('private function dispatch_completion_email('));
  assert.doesNotMatch(bodyBuilder, /\$[A-Za-z_][A-Za-z0-9_]*\[['"](?:account_number|bank_code|account_holder)/);
  assert.match(bodyBuilder, /collection_code/);
  assert.match(bodyBuilder, /consent_text/);
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
  assert.match(page, /:\s*'contactless-v1-draft'/);
  assert.match(backend, /return 'contactless-v1-draft'/);
});

test('contactless placement text is bounded before database insertion', () => {
  const validation = backend.slice(backend.indexOf("$data['pickup_place_details'] = sanitize_text_field"), backend.indexOf("$data['nameplate_name'] = sanitize_text_field"));
  assert.match(validation, /'contactless' === \$data\['handoff_method'\]/);
  assert.match(validation, /mb_strlen\( \$data\['pickup_place_details'\], 'UTF-8' \) > 240/);
  assert.match(validation, /throw new DomainException/);
});

test('API registration is staging-gated and does not call collection services', () => {
  assert.match(backend, /'staging' === wp_get_environment_type\(\)/);
  assert.match(backend, /if \( ! \$this->database_ready\(\) \)\s*\{\s*return;\s*\}/);
  assert.doesNotMatch(backend, /wp_remote_(?:get|post|request)\s*\(|curl_(?:init|exec)\s*\(/);
});

test('staging WordPress mail is pinned to loopback Mailpit with no mail() fallback', () => {
  assert.match(functions, /'staging' === wp_get_environment_type\(\)[\s\S]*?visit-staging-mailpit-transport\.php/);
  assert.match(mailpitTransport, /'127\.0\.0\.1'/);
  assert.match(mailpitTransport, /\$mailer->Port\s*=\s*1025/);
  assert.match(mailpitTransport, /\$mailer->isSMTP\(\)/);
  assert.match(mailpitTransport, /\$mailer->SMTPAutoTLS\s*=\s*false/);
  assert.doesNotMatch(mailpitTransport, /isMail\s*\(|smtp-relay|wp_mail_from/);
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
