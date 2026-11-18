<?php
/**
 * Template Name: 出張買取スタッフ用 集荷ページ
 * Template Post Type: page
 */
defined( 'ABSPATH' ) || exit;

$visit_staff_plugin = get_stylesheet_directory() . '/visit-lp-draft/plugin/visit-staff-collection.php';
if ( is_readable( $visit_staff_plugin ) ) {
	require_once $visit_staff_plugin;
}
$visit_staff_view = class_exists( 'Ouchi_Visit_Staff_Collection_Page' )
	? Ouchi_Visit_Staff_Collection_Page::handle_request()
	: array( 'state' => 'error', 'errors' => array( 'スタッフページを初期化できません。' ), 'success' => array(), 'application' => null, 'counts' => array(), 'token' => '' );

$visit_staff_asset_uri = get_stylesheet_directory_uri() . '/visit-lp-draft/visit/staff-collection/';
wp_enqueue_style( 'ouchi-visit-staff-collection', $visit_staff_asset_uri . 'staff.css', array(), '1.0.3' );
wp_enqueue_script( 'ouchi-visit-staff-collection', $visit_staff_asset_uri . 'staff.js', array(), '1.0.5', true );
wp_script_add_data( 'ouchi-visit-staff-collection', 'strategy', 'defer' );

$visit_staff_application = $visit_staff_view['application'] ?? null;
$visit_staff_token = (string) ( $visit_staff_view['token'] ?? '' );
$visit_staff_form_action = '' !== $visit_staff_token
	? add_query_arg( 'token', $visit_staff_token, get_permalink() )
	: get_permalink();

get_header();
?>
<main class="visit-staff-page">
  <div class="visit-staff-container">
    <header class="visit-staff-header">
      <p class="visit-staff-eyebrow">STAFF COLLECTION PAGE</p>
      <h1>出張買取 集荷情報</h1>
    </header>

      <?php if ( ! empty( $visit_staff_view['errors'] ) ) : ?>
        <div class="visit-staff-alert visit-staff-alert--error" role="alert">
          <?php foreach ( $visit_staff_view['errors'] as $visit_staff_error ) : ?>
            <p><?php echo esc_html( $visit_staff_error ); ?></p>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if ( ! empty( $visit_staff_view['success'] ) ) : ?>
        <div class="visit-staff-alert visit-staff-alert--success" role="status" aria-live="polite">
          <?php foreach ( $visit_staff_view['success'] as $visit_staff_message ) : ?>
            <p><?php echo esc_html( $visit_staff_message ); ?></p>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if ( is_array( $visit_staff_application ) ) : ?>
        <?php
        $visit_staff_name = (string) $visit_staff_application['customer_name'];
        $visit_staff_zip = (string) $visit_staff_application['application_zip'];
        $visit_staff_address = (string) $visit_staff_application['address_display'];
        $visit_staff_contactless = 'contactless' === $visit_staff_application['visit_type'];
        $visit_staff_identity_done = '完了' === $visit_staff_application['identity_verification_status'];
        $visit_staff_counts = $visit_staff_view['counts'] ?? array();
        $visit_staff_identity_front_count = (int) ( $visit_staff_counts['identity_front'] ?? 0 );
        $visit_staff_identity_back_count = (int) ( $visit_staff_counts['identity_back'] ?? 0 );
        ?>
        <section class="visit-staff-card" aria-labelledby="visit-staff-application-title">
          <div class="visit-staff-section-heading">
            <p class="visit-staff-eyebrow">APPLICATION</p>
            <h2 id="visit-staff-application-title">お申し込み情報</h2>
          </div>
          <dl class="visit-staff-details">
            <div><dt>受付番号</dt><dd><?php echo esc_html( $visit_staff_application['request_code'] ); ?></dd></div>
            <div>
              <dt>氏名</dt>
              <dd><span><?php echo esc_html( $visit_staff_name ); ?></span><button type="button" class="visit-staff-copy" data-copy-value="<?php echo esc_attr( $visit_staff_name ); ?>">コピー</button></dd>
            </div>
            <div>
              <dt>郵便番号</dt>
              <dd><span>〒<?php echo esc_html( $visit_staff_zip ); ?></span><button type="button" class="visit-staff-copy" data-copy-value="<?php echo esc_attr( $visit_staff_zip ); ?>">コピー</button></dd>
            </div>
            <div class="visit-staff-details__wide">
              <dt>住所</dt>
              <dd><span><?php echo esc_html( $visit_staff_address ); ?></span><button type="button" class="visit-staff-copy" data-copy-value="<?php echo esc_attr( $visit_staff_address ); ?>">コピー</button><a class="visit-staff-map-link" href="<?php echo esc_url( $visit_staff_application['map_url'] ); ?>" target="_blank" rel="noopener noreferrer">Googleマップで開く</a></dd>
            </div>
            <div><dt>回収日</dt><dd><?php echo esc_html( $visit_staff_application['pickup_date'] ); ?></dd></div>
            <div><dt>希望時間帯</dt><dd><?php echo esc_html( implode( '、', $visit_staff_application['pickup_times'] ) ); ?></dd></div>
            <div><dt>受け渡し方法</dt><dd><?php echo $visit_staff_contactless ? '非対面' : '対面'; ?></dd></div>
            <div><dt>回収コード</dt><dd class="visit-staff-code"><?php echo esc_html( $visit_staff_application['collection_code'] ); ?></dd></div>
            <div><dt>建物</dt><dd><?php echo esc_html( 'detached' === $visit_staff_application['building_type'] ? '一軒家' : ( 'multi_unit' === $visit_staff_application['building_type'] ? '集合住宅' : $visit_staff_application['building_type'] ) ); ?></dd></div>
            <div><dt>表札</dt><dd><?php echo esc_html( 'yes' === $visit_staff_application['nameplate_status'] ? 'あり' : ( 'no' === $visit_staff_application['nameplate_status'] ? 'なし' : $visit_staff_application['nameplate_status'] ) ); ?><?php if ( 'no' === $visit_staff_application['nameplate_status'] && '' !== $visit_staff_application['nameplate_name'] ) : ?>（<?php echo esc_html( $visit_staff_application['nameplate_name'] ); ?>）<?php endif; ?></dd></div>
            <div><dt>申告箱数</dt><dd><?php echo esc_html( $visit_staff_application['box_count'] ); ?></dd></div>
            <div><dt>回収状況</dt><dd><?php echo esc_html( $visit_staff_application['collection_status'] ); ?></dd></div>
            <div class="visit-staff-details__wide"><dt>非対面の置き場所</dt><dd><?php echo esc_html( $visit_staff_application['placement_location'] ?: '—' ); ?></dd></div>
            <div class="visit-staff-details__wide"><dt>回収についての備考</dt><dd><?php echo esc_html( $visit_staff_application['access_note'] ?: '—' ); ?></dd></div>
          </dl>
          <p class="visit-staff-copy-status" data-copy-status role="status" aria-live="polite"></p>
        </section>

        <section class="visit-staff-card" aria-labelledby="visit-staff-identity-title">
          <div class="visit-staff-section-heading">
            <p class="visit-staff-eyebrow">IDENTITY DOCUMENTS</p>
            <h2 id="visit-staff-identity-title">本人確認書類</h2>
          </div>
          <p class="visit-staff-status <?php echo $visit_staff_identity_done ? 'is-complete' : 'is-pending'; ?>">本人確認状況：<strong><?php echo $visit_staff_identity_done ? '完了' : '未完了'; ?></strong></p>
          <p class="visit-staff-note">対面・非対面のどちらでも、当日対面に切り替わる可能性があるため、必要に応じて書類の表面・裏面を撮影して保存できます。ここで画像を保存しても本人確認状況は自動変更されません。</p>
          <p class="visit-staff-upload-state <?php echo ( $visit_staff_identity_front_count > 0 || $visit_staff_identity_back_count > 0 ) ? 'is-complete' : 'is-pending'; ?>" role="status">
            <?php if ( $visit_staff_identity_front_count > 0 || $visit_staff_identity_back_count > 0 ) : ?>
              本人確認書類はこのフォームから送信済みです。保存済みの面は重複送信を防ぐため選択できません。
            <?php else : ?>
              本人確認書類はまだ送信されていません。
            <?php endif; ?>
          </p>
          <p class="visit-staff-saved-count">送信済み：表面 <?php echo esc_html( $visit_staff_identity_front_count ); ?>枚 ／ 裏面 <?php echo esc_html( $visit_staff_identity_back_count ); ?>枚</p>
          <form class="visit-staff-form js-image-compress-form" data-upload-label="本人確認書類" method="post" action="<?php echo esc_url( $visit_staff_form_action ); ?>" enctype="multipart/form-data">
            <input type="hidden" name="token" value="<?php echo esc_attr( $visit_staff_token ); ?>">
            <input type="hidden" name="visit_staff_action" value="identity">
            <?php wp_nonce_field( 'visit_staff_upload_identity_' . $visit_staff_token ); ?>
            <label class="visit-staff-field"><span>書類の種類</span><select name="document_type" required><option value="">選択してください</option><option value="drivers_license">運転免許証</option><option value="mynumber">マイナンバーカード</option><option value="insurance_card">健康保険証・資格確認書</option><option value="passport">パスポート</option><option value="juki_card">写真付き住民基本台帳カード</option><option value="pension_book">年金手帳</option></select></label>
            <div class="visit-staff-upload-grid">
              <label class="visit-staff-field"><span>本人確認書類・表面<?php echo $visit_staff_identity_front_count > 0 ? '（送信済み）' : ''; ?></span><input type="file" name="identity_front" accept="image/jpeg,image/png,image/webp" capture="environment" <?php disabled( $visit_staff_identity_front_count > 0 ); ?>></label>
              <label class="visit-staff-field"><span>本人確認書類・裏面<?php echo $visit_staff_identity_back_count > 0 ? '（送信済み）' : ''; ?></span><input type="file" name="identity_back" accept="image/jpeg,image/png,image/webp" capture="environment" <?php disabled( $visit_staff_identity_back_count > 0 ); ?>></label>
            </div>
            <button class="visit-staff-clear-button" type="button" data-clear-upload-files hidden>本人確認書類の選択画像をクリア</button>
            <button class="visit-staff-button" type="submit">本人確認書類を保存</button>
          </form>
        </section>

        <?php if ( $visit_staff_contactless ) : ?>
          <section class="visit-staff-card" aria-labelledby="visit-staff-photos-title">
            <div class="visit-staff-section-heading">
              <p class="visit-staff-eyebrow">PRE-COLLECTION PHOTOS</p>
              <h2 id="visit-staff-photos-title">回収前の荷物写真</h2>
            </div>
            <p class="visit-staff-note">荷物を移動する前に、荷物全体・各荷物の回収コード・設置場所が分かる写真を撮影してください。回収コードの照合と回収条件の確認後、撮影した写真を保存します。</p>
            <p class="visit-staff-saved-count">保存済み：<?php echo esc_html( $visit_staff_counts['collection_pre_photo'] ?? 0 ); ?>枚</p>
            <form class="visit-staff-form js-image-compress-form" data-upload-label="回収前写真" method="post" action="<?php echo esc_url( $visit_staff_form_action ); ?>" enctype="multipart/form-data">
              <input type="hidden" name="token" value="<?php echo esc_attr( $visit_staff_token ); ?>">
              <input type="hidden" name="visit_staff_action" value="collection">
              <?php wp_nonce_field( 'visit_staff_upload_collection_' . $visit_staff_token ); ?>
              <label class="visit-staff-field"><span>今回回収する荷物の個数</span><input type="number" name="collection_item_count" min="0" step="1" inputmode="numeric" required></label>
              <div class="visit-staff-photo-inputs" data-collection-photos data-max-files="5">
                <p class="visit-staff-photo-inputs__count" data-collection-photo-count aria-live="polite">撮影済み：0 / 5枚</p>
                <label class="visit-staff-field"><span>回収前写真 1</span><input type="file" name="collection_photos[]" accept="image/jpeg,image/png,image/webp" capture="environment" data-preview-label="回収前写真 1"></label>
                <label class="visit-staff-field"><span>回収前写真 2</span><input type="file" name="collection_photos[]" accept="image/jpeg,image/png,image/webp" capture="environment" data-preview-label="回収前写真 2"></label>
                <label class="visit-staff-field"><span>回収前写真 3</span><input type="file" name="collection_photos[]" accept="image/jpeg,image/png,image/webp" capture="environment" data-preview-label="回収前写真 3"></label>
                <label class="visit-staff-field"><span>回収前写真 4</span><input type="file" name="collection_photos[]" accept="image/jpeg,image/png,image/webp" capture="environment" data-preview-label="回収前写真 4"></label>
                <label class="visit-staff-field"><span>回収前写真 5</span><input type="file" name="collection_photos[]" accept="image/jpeg,image/png,image/webp" capture="environment" data-preview-label="回収前写真 5"></label>
              </div>
              <p class="visit-staff-note">各欄を押して1枚ずつ撮影してください。撮影した画像は送信前にすべてプレビュー表示されます。エラー時は保存済み枚数を確認し、失敗した写真だけ再撮影して送信できます。</p>
              <button class="visit-staff-clear-button" type="button" data-clear-upload-files hidden>回収前写真の選択画像をクリア</button>
              <button class="visit-staff-button" type="submit">回収前写真を保存</button>
            </form>
          </section>
        <?php endif; ?>

        <section class="visit-staff-footer-note" aria-label="保存形式">
            <p>画像はJPEG・PNG・WEBPを受け付け、幅1600px以下に圧縮して保存します。本人確認書類と回収前写真は別のフォルダーに分けて保存されます。</p>
        </section>
      <?php endif; ?>
  </div>
</main>
<?php get_footer(); ?>
