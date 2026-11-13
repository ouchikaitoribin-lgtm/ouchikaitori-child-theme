<?php
/**
 * Template Name: おうち買取便 地域別出張買取
 * Template Post Type: page
 *
 * 地域ページ共通テンプレート。対応地域は visit-lp-draft/visit/regions.php で管理します。
 * 地域ごとに固定ページを作成し、すべてこのテンプレートを割り当ててください。
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ステージング環境だけでフォーム画面を操作できるプレビューとして動かします。
$visit_form_preview_mode = function_exists( 'wp_get_environment_type' )
	&& 'staging' === wp_get_environment_type();
$visit_regions = require get_stylesheet_directory() . '/visit-lp-draft/visit/regions.php';
$visit_region_slug = (string) get_post_field( 'post_name', get_queried_object_id() );
$visit_region = isset( $visit_regions[ $visit_region_slug ] ) ? $visit_regions[ $visit_region_slug ] : null;
if ( ! is_array( $visit_region ) ) {
	status_header( 404 );
	get_header();
	echo '<main class="visit-page"><section class="visit-section"><div class="visit-container"><h1>対応エリアを確認できません</h1><p>このページの対応地域は、現在設定されていません。</p></div></section></main>';
	get_footer();
	return;
}
$visit_area_count = count( $visit_region['areas'] );
$visit_area_unit = (string) $visit_region['area_unit'];
$visit_coverage_label = isset( $visit_region['coverage_label'] ) ? (string) $visit_region['coverage_label'] : '全' . $visit_area_count . $visit_area_unit;
$visit_city_name = (string) $visit_region['city'];
$visit_prefecture_name = (string) $visit_region['prefecture'];
$visit_address_prefix = (string) $visit_region['address_prefix'];
$visit_map_center = array( (float) $visit_region['map_center']['lat'], (float) $visit_region['map_center']['lng'] );
$visit_confirmation_path = '/visit/' . rawurlencode( $visit_region_slug ) . '/application-pending/';

add_action( 'wp_enqueue_scripts', static function () use ( $visit_form_preview_mode, $visit_confirmation_path, $visit_address_prefix, $visit_prefecture_name, $visit_map_center ) {
	if ( ! is_page_template( 'page-visit-kyoto-city.php' ) ) {
		return;
	}
	$asset_uri = get_stylesheet_directory_uri() . '/visit-lp-draft/';
	wp_enqueue_style( 'ouchi-visit-lp', $asset_uri . 'visit.css', array(), '1.3.2' );
	wp_enqueue_style( 'ouchi-visit-kyoto-city', $asset_uri . 'visit/kyoto-city/kyoto-city.css', array( 'ouchi-visit-lp' ), '1.3.2' );
	wp_enqueue_style( 'ouchi-visit-booking-ui', $asset_uri . 'visit/kyoto-city/booking.css', array( 'ouchi-visit-kyoto-city' ), '1.6.5' );
	wp_enqueue_style( 'ouchi-visit-leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', array(), '1.9.4' );
	wp_enqueue_script( 'ouchi-visit-yubinbango', 'https://yubinbango.github.io/yubinbango/yubinbango.js', array(), null, true );
	wp_enqueue_script( 'ouchi-visit-leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', array(), '1.9.4', true );
	$booking_script_dependencies = array( 'ouchi-visit-yubinbango', 'ouchi-visit-leaflet' );
	wp_enqueue_script( 'ouchi-visit-booking-ui', $asset_uri . 'visit-booking.js', $booking_script_dependencies, '1.6.7', true );
	$visit_booking_defaults = array(
		'slots'                   => array( '10:00〜12:00', '12:00〜14:00', '14:00〜16:00', '16:00〜18:00', '18:00〜20:00' ),
		'multiSlotBonusEnabled'   => true,
		'multiSlotBonusPercent'   => 1,
	);
	$visit_bonus_settings   = function_exists( 'visit_booking_get_multi_slot_bonus_settings' ) ? visit_booking_get_multi_slot_bonus_settings() : array( 'enabled' => true, 'percent' => 1 );
	wp_localize_script(
		'ouchi-visit-booking-ui',
		'VISIT_BOOKING_CONFIG',
		array(
			'slots'                 => function_exists( 'visit_booking_get_shared_slots' ) ? visit_booking_get_shared_slots() : $visit_booking_defaults['slots'],
			'multiSlotBonusEnabled' => ! empty( $visit_bonus_settings['enabled'] ),
			'multiSlotBonusPercent' => isset( $visit_bonus_settings['percent'] ) ? (float) $visit_bonus_settings['percent'] : 1,
			'applicationSubmitEndpoint' => apply_filters( 'visit_application_submit_endpoint', '' ),
			'applicationStatusEndpoint' => apply_filters( 'visit_application_status_endpoint', '' ),
			'applicationConfirmationPageUrl' => home_url( $visit_confirmation_path ),
			'formPreviewMode' => $visit_form_preview_mode,
			'applicationSubmissionEnabled' => false,
			'regionName' => $visit_address_prefix,
			'prefectureName' => $visit_prefecture_name,
			'mapCenter' => $visit_map_center,
			'previewAddress' => $visit_region['preview_address'],
		)
	);
	wp_script_add_data( 'ouchi-visit-yubinbango', 'strategy', 'defer' );
	wp_script_add_data( 'ouchi-visit-booking-ui', 'strategy', 'defer' );
} );

$asset_uri   = get_stylesheet_directory_uri() . '/visit-lp-draft/';

get_header();
?>
<div class="visit-page">
  <a class="visit-skip-link" href="#visit-main">本文へ移動</a>
  <main id="visit-main" class="visit-kyoto-city">
    <nav class="visit-kyoto-breadcrumb visit-container" aria-label="パンくずリスト">
      <a href="<?php echo esc_url( home_url( '/visit/' ) ); ?>">出張買取</a><span aria-hidden="true">/</span><span aria-current="page"><?php echo esc_html( $visit_city_name ); ?></span>
    </nav>

    <section class="visit-hero visit-kyoto-hero" aria-labelledby="kyoto-hero-title">
      <div class="visit-container visit-hero__grid">
        <div class="visit-hero__copy">
          <p class="visit-eyebrow"><span></span><?php echo esc_html( $visit_city_name . $visit_coverage_label ); ?>で対応中</p>
          <h1 id="kyoto-hero-title"><?php echo esc_html( $visit_city_name ); ?>の出張買取。<br><em>玄関前に置くだけ。</em></h1>
          <p class="visit-hero__lead">スタッフと会わずに、自宅にいなくても。<br><?php echo esc_html( $visit_city_name ); ?>のご自宅まで伺い、指定場所から商品を回収します。</p>
          <div class="visit-hero__chips" aria-label="出張買取の特徴"><span>玄関のドアは開けずに回収</span><span>ご不在でも利用可能</span><span>対面も選べます</span></div>
          <p class="visit-hero__note"><strong>現在の対応地域：<?php echo esc_html( $visit_city_name . '（' . $visit_coverage_label . '）' ); ?></strong><br>本・漫画・CD・DVD・ゲーム・カメラ・小型家電など</p>
          <a class="visit-button visit-button--primary" href="#kyoto-booking">エリア選択へ進む <span aria-hidden="true">↓</span></a>
          <p class="visit-hero__fineprint">対応地域・日時の選択からお客様情報の入力まで、このページ内で行う構成です。メール確認機能は準備中です。</p>
        </div>
        <div class="visit-hero-art"><img src="<?php echo esc_url( $asset_uri . 'assets/hero-pickup-v2.webp' ); ?>" width="1536" height="1024" alt="閉じた玄関ドアの外に本やゲーム機を置いておく置き配のイメージ" fetchpriority="high" decoding="async"></div>
      </div>
      <div class="visit-container visit-trust-row"><span><i aria-hidden="true">01</i> ドアを開けずに受け渡し</span><span><i aria-hidden="true">02</i> 回収時の在宅不要</span><span><i aria-hidden="true">03</i> <?php echo esc_html( $visit_city_name . $visit_coverage_label ); ?></span></div>
    </section>

    <?php
    // 静的プレビューと同一の選択UIを使います。予約APIが未接続の間は本番では操作を無効化します。
    $booking_widget_path = get_stylesheet_directory() . '/visit-lp-draft/visit/kyoto-city/booking-widget.html';
    if ( is_readable( $booking_widget_path ) ) {
        $booking_widget_html = file_get_contents( $booking_widget_path ); // 固定管理下のHTML断片のみを読み込み
        $terms_text = function_exists( 'visit_booking_get_contactless_terms_text' )
            ? visit_booking_get_contactless_terms_text()
            : "盗難・紛失について | 商品を玄関前などに設置してから、当社スタッフが回収するまでの間に発生した盗難・紛失等については、当社に責任が認められる場合を除き、補償いたしかねます。\n雨・風などによる汚損について | 回収前に雨・風などによって商品が濡れたり、汚れたり、破損した場合については、当社に責任が認められる場合を除き、補償いたしかねます。雨風の影響を受けにくい場所に設置してください。\n回収住所について | 非対面回収は、原則としてお申し込み住所と本人確認書類に記載された住所が一致する場合にご利用いただけます。住所が異なる場合は、別途確認が必要です。\n表札などによる住所確認 | スタッフが回収先を間違えないよう、表札などで回収先を確認できる状態にしてください。表札を設置できない場合の対応方法は、受付開始時にご案内します。\nスタッフが立ち入れる場所 | オートロックのマンション内や施錠された門の内側など、スタッフが立ち入れない場所では回収できません。\nペットなどへの配慮 | スタッフが安全に回収できるよう、ペットなどが回収作業を妨げない状態にしてください。\n回収時の写真撮影 | 回収間違いやトラブル防止のため、スタッフが商品の設置状況や回収時の状態を写真撮影します。個人情報や住宅内部など、回収確認に不要なものは極力撮影しません。\n本人確認について | 非対面回収では、回収前にWEB上で所定の本人確認手続を完了していただく必要があります。";
        $terms_markup = '';
        foreach ( preg_split( '/\R/u', trim( $terms_text ) ) as $terms_line ) {
            $terms_parts = explode( '|', $terms_line, 2 );
            if ( 2 !== count( $terms_parts ) || '' === trim( $terms_parts[0] ) || '' === trim( $terms_parts[1] ) ) {
                continue;
            }
            $terms_markup .= '<li><strong>' . esc_html( trim( $terms_parts[0] ) ) . '</strong><p>' . esc_html( trim( $terms_parts[1] ) ) . '</p></li>';
        }
        $terms_version = function_exists( 'visit_booking_get_contactless_terms_version' )
            ? visit_booking_get_contactless_terms_version()
            : 'contactless-v1-draft';
        $booking_widget_html = str_replace( '<!-- VISIT_CONTACTLESS_TERMS -->', $terms_markup, $booking_widget_html );
        $area_options_markup = '';
        foreach ( $visit_region['areas'] as $visit_area ) {
            $area_options_markup .= '<label><input type="radio" name="visit-area" value="' . esc_attr( $visit_area ) . '"><span>' . esc_html( $visit_area ) . '</span></label>';
        }
        $booking_widget_html = str_replace( '<!-- VISIT_AREA_OPTIONS -->', $area_options_markup, $booking_widget_html );
        $booking_widget_html = str_replace( 'value="contactless-v1-draft"', 'value="' . esc_attr( $terms_version ) . '"', $booking_widget_html );
        $booking_widget_html = str_replace( 'value="京都府"', 'value="' . esc_attr( $visit_prefecture_name ) . '"', $booking_widget_html );
        $application_nonce = wp_nonce_field( 'visit_application_submit', 'visit_application_nonce', false, false );
        $booking_widget_html = str_replace( '<!-- VISIT_APPLICATION_NONCE -->', $application_nonce, $booking_widget_html );
        echo $booking_widget_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 固定HTML断片を使用し、差し込み値は個別にエスケープ済み
    }
    ?>

    <section class="visit-section visit-contactless" id="kyoto-contactless" aria-labelledby="kyoto-contactless-title">
      <div class="visit-container visit-contactless__grid">
        <div class="visit-contactless__visual"><img class="visit-contactless__image" src="<?php echo esc_url( $asset_uri . 'assets/contactless-pickup-v2.webp' ); ?>" width="1536" height="1024" alt="閉じた玄関ドアの外に置いた買取品を、後からスタッフが回収する置き配のイメージ" loading="lazy" decoding="async"><div class="visit-round-stamp"><span>会わずに<br>完了</span><b>非対面</b></div></div>
        <div class="visit-contactless__copy">
          <p class="visit-eyebrow">YOUR TIME, YOUR WAY</p><h2 id="kyoto-contactless-title">会わなくていい。<br>待たなくていい。</h2>
          <p class="visit-body-lead">非対面回収は、荷物を置き配するような受け渡しです。回収時に玄関のドアを開けることはありません。</p>
          <ul class="visit-check-list"><li><span aria-hidden="true">✓</span><div><strong>指定した場所に置くだけ</strong><small>玄関前など、事前に確認した安全な場所へ商品をまとめて置きます。</small></div></li><li><span aria-hidden="true">✓</span><div><strong>回収時に家にいなくても大丈夫</strong><small>お出かけ中など、ご不在の間にスタッフが回収します。</small></div></li><li><span aria-hidden="true">✓</span><div><strong>対面での受け渡しも選べます</strong><small>ご希望の場合は、スタッフへ直接商品を渡す方法も選択できます。</small></div></li></ul>
          <p class="visit-callout"><strong>非対面回収をご利用いただく前に</strong><br>事前に所定の本人確認が必要です。回収場所はスタッフが安全に立ち入り、商品を確認できる場所に限ります。具体的な場所の条件と手続きは、受付開始時にご案内します。</p>
        </div>
      </div>
    </section>

    <section class="visit-section visit-items" id="kyoto-items" aria-labelledby="kyoto-items-title">
      <div class="visit-container"><div class="visit-section-heading"><div><p class="visit-eyebrow">WHAT WE BUY</p><h2 id="kyoto-items-title"><?php echo esc_html( $visit_city_name ); ?>で買取できるもの</h2></div><figure class="visit-items-illustration"><img src="<?php echo esc_url( $asset_uri . 'assets/accepted-items.webp' ); ?>" width="1536" height="1024" alt="本、CD、DVD、ゲーム機、カメラ、小型家電のイラスト" loading="lazy" decoding="async"></figure></div>
        <ul class="visit-item-grid"><li class="visit-item-card"><span class="visit-item-icon" aria-hidden="true">本</span><strong>本・漫画・コミック</strong><small>小説、専門書、漫画など</small></li><li class="visit-item-card"><span class="visit-item-icon" aria-hidden="true">♫</span><strong>CD</strong><small>音楽CDなど</small></li><li class="visit-item-card"><span class="visit-item-icon" aria-hidden="true">▶</span><strong>DVD・Blu-ray</strong><small>映像ソフトなど</small></li><li class="visit-item-card"><span class="visit-item-icon" aria-hidden="true">GAME</span><strong>ゲームソフト</strong><small>各種ゲームソフト</small></li><li class="visit-item-card"><span class="visit-item-icon" aria-hidden="true">▣</span><strong>ゲーム機本体</strong><small>携帯型・据え置き型</small></li><li class="visit-item-card"><span class="visit-item-icon" aria-hidden="true">◎</span><strong>カメラ</strong><small>デジタルカメラなど</small></li><li class="visit-item-card"><span class="visit-item-icon" aria-hidden="true">家</span><strong>小型家電</strong><small>大型家電を除く家電製品</small></li></ul>
        <p class="visit-exclusion"><span aria-hidden="true">!</span><span><strong>買取対象外</strong>テレビ・冷蔵庫・洗濯機・エアコンなどの大型家電</span></p>
      </div>
    </section>

    <section class="visit-section visit-flow" id="kyoto-flow" aria-labelledby="kyoto-flow-title"><div class="visit-container"><div class="visit-section-heading visit-section-heading--center"><p class="visit-eyebrow">HOW IT WORKS</p><h2 id="kyoto-flow-title">出張買取のご利用の流れ</h2><p>このページで日時とお客様情報を入力し、確認メール内のリンクで申込を完了します。</p></div>
      <ol class="visit-flow-list"><li class="visit-flow-card"><span class="visit-flow-card__step">STEP <b>01</b></span><span class="visit-flow-card__icon" aria-hidden="true">⌕</span><h3>ネットから申し込み</h3><p>対応地域・受け渡し方法・回収日時を選び、このページでお客様情報を送信します。</p></li><li class="visit-flow-card"><span class="visit-flow-card__step">STEP <b>02</b></span><span class="visit-flow-card__icon" aria-hidden="true">✓</span><h3>本人確認</h3><p>対面は回収時に本人確認書類の原本を提示。非対面は事前にWEBで手続きします。</p></li><li class="visit-flow-card"><span class="visit-flow-card__step">STEP <b>03</b></span><span class="visit-flow-card__icon" aria-hidden="true">▢</span><h3>商品を回収</h3><p>対面はスタッフへ手渡し。非対面はドアを開けず、指定した場所から回収します。</p></li><li class="visit-flow-card"><span class="visit-flow-card__step">STEP <b>04</b></span><span class="visit-flow-card__icon" aria-hidden="true">⌕</span><h3>持ち帰って査定</h3><p>回収した商品を持ち帰り、1点ずつ査定します。</p></li><li class="visit-flow-card"><span class="visit-flow-card__step">STEP <b>05</b></span><span class="visit-flow-card__icon" aria-hidden="true">￥</span><h3>結果確認・振込</h3><p>査定結果をご確認・承認いただいた後、銀行口座へ代金を振り込みます。</p></li></ol>
      <p class="visit-flow-note">※申込保存・確認メール送信は未接続のため、現在は受付していません。本人確認・査定条件の詳細も受付開始前に確定します。</p></div></section>

    <section class="visit-section visit-area" id="kyoto-wards" aria-labelledby="kyoto-wards-title"><div class="visit-container"><div class="visit-area-heading"><div><p class="visit-eyebrow">SERVICE AREA</p><h2 id="kyoto-wards-title"><?php echo esc_html( $visit_city_name ); ?>の対応エリア</h2></div><p>このページに記載した地域が対応地域です。<br>都道府県全域を示すものではありません。</p></div>
      <article class="visit-prefecture-card visit-kyoto-area-card"><div class="visit-prefecture-card__top"><span class="visit-prefecture-card__pin" aria-hidden="true">⌖</span><div><span class="visit-prefecture-card__status"><i></i> 対応中</span><h3><?php echo esc_html( $visit_city_name ); ?></h3></div><span class="visit-prefecture-card__count"><?php echo esc_html( (string) $visit_area_count ); ?> <small><?php echo esc_html( $visit_area_unit ); ?></small></span></div><p class="visit-kyoto-area-intro"><?php echo esc_html( $visit_coverage_label ); ?>で出張買取を承っています。</p><ul class="visit-kyoto-ward-list" aria-label="<?php echo esc_attr( $visit_city_name . 'の対応する地域' ); ?>"><?php foreach ( $visit_region['areas'] as $visit_area ) : ?><li><?php echo esc_html( $visit_area ); ?></li><?php endforeach; ?></ul></article>
      <p class="visit-area-footnote"><span aria-hidden="true">i</span> 現在の対応地域はこのページの一覧に記載した地域です。都道府県全域を示すものではありません。</p></div></section>

    <section class="visit-section visit-faq" id="kyoto-faq" aria-labelledby="kyoto-faq-title"><div class="visit-container visit-faq__grid"><div class="visit-faq__intro"><p class="visit-eyebrow">FAQ</p><h2 id="kyoto-faq-title"><?php echo esc_html( $visit_city_name ); ?>の出張買取<br>よくある質問</h2><p>非対面回収や、対応地域についてご案内します。</p><span class="visit-faq__decor" aria-hidden="true">?</span></div><div class="visit-faq-list">
      <details class="visit-faq-item"><summary><span>Q</span><?php echo esc_html( $visit_city_name ); ?>のどの地域が対応していますか？<i aria-hidden="true"></i></summary><p><?php echo esc_html( implode( '・', $visit_region['areas'] ) . 'の' . $visit_coverage_label . 'です。対応地域はこのページの一覧をご確認ください。' ); ?></p></details>
      <details class="visit-faq-item"><summary><span>Q</span>非対面回収では玄関のドアを開けますか？<i aria-hidden="true"></i></summary><p>いいえ。置き配のように、玄関のドアを閉めたまま、事前に指定した場所の荷物を回収する方式です。本人確認と回収場所の条件があります。</p></details>
      <details class="visit-faq-item"><summary><span>Q</span>回収のとき家にいる必要はありますか？<i aria-hidden="true"></i></summary><p>非対面回収では、事前手続きと場所の条件を満たせば、回収時にご在宅いただく必要はありません。対面での受け渡しも選べます。</p></details>
      <details class="visit-faq-item"><summary><span>Q</span>出張費や最低点数、査定後の返却条件は？<i aria-hidden="true"></i></summary><p>費用・最低点数・返却条件は、現時点で確定した公開情報がありません。受付開始前に確定条件をご案内します。</p></details>
      <details class="visit-faq-item"><summary><span>Q</span>申し込みはどのように完了しますか？<i aria-hidden="true"></i></summary><p>このページで日時とお客様情報を入力し、仮申込を送信した後、確認メール内のリンクを開くと申込完了となる予定です。現在は申込保存・メール確認のバックエンドが未接続です。</p></details>
    </div></div></section>

    <section class="visit-final-cta visit-kyoto-final" aria-labelledby="kyoto-final-title"><div class="visit-container visit-final-cta__inner"><div class="visit-final-cta__text"><p class="visit-eyebrow">AVAILABLE SERVICE AREA</p><h2 id="kyoto-final-title"><?php echo esc_html( $visit_city_name . '（' . $visit_coverage_label . '）' ); ?>で<br>出張買取に対応しています。</h2><p>日時と申込情報の入力から確認メールによる完了まで、このページ内で行う構成です。現在はバックエンド準備中です。</p></div><a class="visit-button visit-button--light" href="#kyoto-booking">申込フォームを見る <span aria-hidden="true">↑</span></a><span class="visit-final-cta__spark" aria-hidden="true">✳</span></div></section>
  </main>
</div>
<?php get_footer(); ?>
