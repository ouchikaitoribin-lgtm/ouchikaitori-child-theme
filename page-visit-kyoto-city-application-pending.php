<?php
/**
 * Template Name: 出張買取 仮申し込み後のご案内（地域共通）
 * Template Post Type: page
 *
 * 仮申し込み後の案内ページ案。申込内容と回収コードは、今後の仮申込処理から
 * window.VISIT_PENDING_APPLICATION として安全に渡す接続点を用意しています。
 * 現在、申込保存・メール確認バックエンドは未接続です。
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// public_tokenからの申込照会は、後から安全なサーバー側実装をフックして接続します。
$visit_public_token = isset( $_GET['public_token'] ) && is_string( $_GET['public_token'] )
	? sanitize_text_field( wp_unslash( $_GET['public_token'] ) )
	: '';
$visit_pending_preview_mode = function_exists( 'wp_get_environment_type' )
	&& 'staging' === wp_get_environment_type()
	&& '' === $visit_public_token
	&& isset( $_GET['preview'] )
	&& is_string( $_GET['preview'] )
	&& '1' === sanitize_text_field( wp_unslash( $_GET['preview'] ) );
$visit_parent_page_id = wp_get_post_parent_id( get_queried_object_id() );
$visit_region_page_url = $visit_parent_page_id ? get_permalink( $visit_parent_page_id ) : home_url( '/visit/' );
$visit_booking_page_url = trailingslashit( $visit_region_page_url ) . '#kyoto-booking';
$visit_pending_application = null;
if ( '' !== $visit_public_token && strlen( $visit_public_token ) <= 512 ) {
	$visit_pending_application = apply_filters( 'visit_pending_application_for_public_token', null, $visit_public_token );
	if (
		! is_array( $visit_pending_application )
		|| ! isset( $visit_pending_application['public_token_valid'], $visit_pending_application['application_status'] )
		|| true !== $visit_pending_application['public_token_valid']
		|| 'complete' !== $visit_pending_application['application_status']
	) {
		$visit_pending_application = null;
	} else {
		$visit_pending_application = array_intersect_key(
			$visit_pending_application,
			array_flip(
				array(
					'public_token_valid', 'application_status', 'application_number', 'customer_name',
					'email', 'area', 'pickup_date', 'pickup_times', 'handoff_method', 'pickup_address',
					'building_type', 'nameplate', 'pickup_place', 'box_count', 'pickup_notes', 'pickup_code',
				)
			)
		);
	}
}

add_action( 'wp_enqueue_scripts', static function () {
	if ( ! is_page_template( 'page-visit-kyoto-city-application-pending.php' ) ) {
		return;
	}
	$asset_uri = get_stylesheet_directory_uri() . '/visit-lp-draft/';
	wp_enqueue_style( 'ouchi-visit-lp', $asset_uri . 'visit.css', array(), '1.0.0' );
	wp_enqueue_style( 'ouchi-visit-pending', $asset_uri . 'visit/kyoto-city/application-pending/pending.css', array( 'ouchi-visit-lp' ), '1.1.0' );
	wp_enqueue_script( 'ouchi-visit-pending', $asset_uri . 'visit/kyoto-city/application-pending/pending.js', array(), '1.1.2', true );
	wp_script_add_data( 'ouchi-visit-pending', 'strategy', 'defer' );
} );

//申込内容を含むページの検索登録とキャッシュを抑止します。
add_filter( 'wp_robots', static function ( $robots ) {
	if ( is_page_template( 'page-visit-kyoto-city-application-pending.php' ) ) {
		$robots['noindex'] = true;
		$robots['nofollow'] = true;
		$robots['noarchive'] = true;
	}
	return $robots;
} );
add_action( 'template_redirect', static function () {
	if ( is_page_template( 'page-visit-kyoto-city-application-pending.php' ) ) {
		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'Referrer-Policy: no-referrer' );
		}
	}
} );
add_action( 'wp_head', static function () {
	if ( is_page_template( 'page-visit-kyoto-city-application-pending.php' ) ) {
		echo '<meta name="referrer" content="no-referrer">' . "\n";
	}
}, 1 );

get_header();
?>
<?php if ( is_array( $visit_pending_application ) ) : ?>
<script>window.VISIT_PENDING_APPLICATION = <?php echo wp_json_encode( $visit_pending_application, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?>;</script>
<?php endif; ?>
<?php if ( $visit_pending_preview_mode ) : ?>
<script>window.VISIT_PENDING_PREVIEW = true;</script>
<?php endif; ?>
<div class="visit-page">
  <a class="visit-skip-link" href="#pending-main">本文へ移動</a>
  <header class="visit-header">
    <div class="visit-container visit-header__inner">
      <a class="visit-brand" href="<?php echo esc_url( $visit_region_page_url ); ?>" aria-label="出張買取ページへ戻る">
        <span class="visit-brand__mark" aria-hidden="true">家</span>
        <span class="visit-brand__name">おうち買取便<small>出張買取</small></span>
      </a>
      <a class="visit-header__cta" href="<?php echo esc_url( $visit_booking_page_url ); ?>">申込ページへ <span aria-hidden="true">→</span></a>
    </div>
  </header>

  <main id="pending-main" class="visit-pending-main">
    <section class="visit-pending-hero">
      <div class="visit-container">
        <div data-application-state="preview" hidden>
          <p class="visit-pending-eyebrow">STAGING PREVIEW</p>
          <p class="visit-pending-state">画面確認用</p>
          <h1 id="pending-preview-title">申込内容確認ページのプレビューです。</h1>
          <p class="visit-pending-hero__lead">この画面は表示確認用です。実際の申込情報は保存されておらず、回収コードも発行されていません。</p>
          <div class="visit-pending-warning" role="status">
            <span class="visit-pending-warning__icon" aria-hidden="true">i</span>
            <div><strong>画面確認用のプレビューです</strong><p>受付システム接続後に、申込内容と回収コードが表示されます。</p></div>
          </div>
        </div>
        <div data-application-state="pending">
        <p class="visit-pending-eyebrow">APPLICATION LOOKUP</p>
        <p class="visit-pending-state">申込内容の確認</p>
        <h1 id="pending-title">申込内容を表示できません。</h1>
        <p class="visit-pending-hero__lead">有効な申込情報を確認できません。申込完了後に発行された専用URLをご確認ください。</p>
        <div class="visit-pending-warning" role="alert">
          <span class="visit-pending-warning__icon" aria-hidden="true">!</span>
          <div><strong>申込情報を読み込めません</strong><p>URLをご確認いただくか、お問い合わせください。</p></div>
        </div>
        </div>
        <div data-application-state="complete" hidden>
          <p class="visit-pending-eyebrow">APPLICATION COMPLETE</p>
          <p class="visit-pending-state visit-pending-state--complete">正式申込完了</p>
          <h1>正式なお申し込みが完了しました。</h1>
          <p class="visit-pending-hero__lead">申込完了時に案内された専用URLからいつでも確認できます。申込内容と回収コード、荷物の準備方法をご確認ください。</p>
        </div>
        <p class="visit-pending-token-status" data-public-token-status role="status" hidden></p>
        <div class="visit-pending-token-invalid" data-public-token-invalid role="alert" hidden>この確認ページを表示できません。申込完了時に発行されたURLをご確認いただくか、お問い合わせください。</div>
      </div>
    </section>

    <section class="visit-pending-section visit-pending-identity" aria-labelledby="pending-identity-title">
      <div class="visit-container">
        <div class="visit-pending-section__heading"><p class="visit-pending-eyebrow">IDENTITY VERIFICATION</p><h2 id="pending-identity-title">本人確認書類のご案内</h2><p>お申し込み時に選んだ受け渡し方法に応じて、本人確認を行います。</p></div>
        <div class="visit-pending-id-guides">
          <article class="visit-pending-id-guide" data-handoff-guide="face"><span class="visit-pending-id-guide__icon" aria-hidden="true">対面</span><div><h3>対面で受け渡す場合</h3><p>回収当日、スタッフへ本人確認書類の<strong>原本を提示</strong>してください。</p></div></article>
          <article class="visit-pending-id-guide" data-handoff-guide="contactless"><span class="visit-pending-id-guide__icon" aria-hidden="true">非対面</span><div><h3>非対面で回収する場合</h3><p>インターネット上の所定の本人確認手続きから、本人確認書類を<strong>アップロード</strong>してください。</p></div></article>
        </div>
        <p class="visit-pending-id-note" data-identity-upload-note>本人確認書類のアップロード画面はシステム接続後に案内します。このプレビューにはアップロード機能はありません。</p>
      </div>
    </section>

    <section class="visit-pending-section" aria-labelledby="pending-details-title">
      <div class="visit-container">
        <div class="visit-pending-section__heading"><p class="visit-pending-eyebrow">APPLICATION DETAILS</p><h2 id="pending-details-title">お申し込み内容</h2><p>申込内容と回収先を確認してください。</p></div>
        <div class="visit-pending-data-note" data-pending-data-note role="status">申込内容は、仮申込システム接続後にここへ表示されます。</div>
        <dl class="visit-pending-details">
          <div><dt>申込番号</dt><dd data-pending-field="application_number">システム接続後に表示</dd></div>
          <div><dt>お名前</dt><dd data-pending-field="customer_name">システム接続後に表示</dd></div>
          <div><dt>メールアドレス</dt><dd data-pending-field="email">システム接続後に表示</dd></div>
          <div><dt>回収エリア</dt><dd data-pending-field="area">システム接続後に表示</dd></div>
          <div><dt>回収希望日</dt><dd data-pending-field="pickup_date">システム接続後に表示</dd></div>
          <div><dt>回収希望時間帯</dt><dd data-pending-field="pickup_times">システム接続後に表示</dd></div>
          <div><dt>受け渡し方法</dt><dd data-pending-field="handoff_method">システム接続後に表示</dd></div>
          <div><dt>回収先住所</dt><dd data-pending-field="pickup_address">システム接続後に表示</dd></div>
          <div><dt>建物の種類</dt><dd data-pending-field="building_type">システム接続後に表示</dd></div>
          <div><dt>表札の申告</dt><dd data-pending-field="nameplate">システム接続後に表示</dd></div>
          <div><dt>荷物の置き場所</dt><dd data-pending-field="pickup_place">システム接続後に表示</dd></div>
          <div><dt>おおよその箱数</dt><dd data-pending-field="box_count">システム接続後に表示</dd></div>
          <div class="visit-pending-details__wide"><dt>備考</dt><dd data-pending-field="pickup_notes">システム接続後に表示</dd></div>
        </dl>
      </div>
    </section>

    <section class="visit-pending-code-section" aria-labelledby="pending-code-title">
      <div class="visit-container"><div class="visit-pending-code-card">
        <p class="visit-pending-eyebrow">PICKUP CODE</p><h2 id="pending-code-title">荷物に記載する回収コード</h2>
        <p>回収するすべての段ボール・袋に、同じ回収コードを外側から見えるように記載してください。</p>
        <output class="visit-pending-code" data-pending-field="pickup_code" aria-live="polite">システム接続後に表示</output>
        <small>回収コードが記載されていない荷物や、異なるコードの荷物は回収対象になりません。</small>
      </div></div>
    </section>

    <section class="visit-pending-section visit-pending-packing" aria-labelledby="pending-packing-title">
      <div class="visit-container">
        <div class="visit-pending-section__heading"><p class="visit-pending-eyebrow">PICKUP PREPARATION</p><h2 id="pending-packing-title">荷物の置き方・準備</h2><p>申込時に指定した置き場所と、荷物の準備内容をご確認ください。</p></div>
        <div class="visit-pending-place-card"><span class="visit-pending-place-card__icon" aria-hidden="true">⌖</span><div><strong>指定した場所に置いてください</strong><p>回収対象は、申込時に指定した設置場所にある荷物です。荷物の置き場所は申込内容欄でご確認ください。</p></div></div>
        <ol class="visit-pending-packing-list">
          <li><span>01</span><div><strong>段ボール箱・紙袋・ビニール袋に入れる</strong><p>商品は必ずいずれかの梱包資材に入れてください。</p></div></li>
          <li><span>02</span><div><strong>中身が出ない状態にする</strong><p>ふたや袋の口を閉じ、商品が飛び出さず、持ち上げても落ちない状態にしてください。</p></div></li>
          <li><span>03</span><div><strong>すべての荷物に回収コードを付ける</strong><p>荷物ごとに同じコードを、外側から確認できる位置に記載してください。コードは開封せずに確認でき、文字が隠れていない状態にしてください。</p></div></li>
          <li data-pending-contactless hidden><span>04</span><div><strong>非対面の場合は玄関ドアを開けずに置く</strong><p>申込時に指定した場所へ荷物を置いてください。スタッフはお客様と対面せず、指定場所から回収します。</p></div></li>
        </ol>
      </div>
    </section>

    <section class="visit-pending-final">
      <div class="visit-container"><p class="visit-pending-eyebrow">APPLICATION DETAILS</p><div data-application-state="preview" hidden><h2>申込内容確認画面のプレビューです。</h2><p>実際の申込情報と回収コードは、受付システム接続後に表示されます。</p></div><div data-application-state="pending"><h2>申込情報を表示できません。</h2><p>申込完了後に発行された専用URLをご確認ください。</p></div><div data-application-state="complete" hidden><h2>申込内容と回収コードをご確認ください。</h2><p>専用URLを保存しておくと、あとからこのページを確認できます。</p></div><a class="visit-pending-back-link" href="<?php echo esc_url( $visit_booking_page_url ); ?>">申込ページへ戻る <span aria-hidden="true">→</span></a></div>
    </section>
  </main>
  <footer class="visit-footer"><div class="visit-container"><p>© おうち買取便</p></div></footer>
</div>
<?php get_footer(); ?>
