<?php
/**
 * Template Name: おうち買取便 出張買取LP
 * Template Post Type: page
 *
 * Draft only. Install this template and its sibling asset folder in a WordPress theme
 * after reviewing against that theme. No backend or booking flow is included.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// 対応エリアは共通設定で管理し、地域ページ用テンプレートと同じデータを表示します。
$visit_regions = require get_stylesheet_directory() . '/visit-lp-draft/visit/regions.php';
$visit_areas = array();
foreach ( $visit_regions as $visit_region_slug => $visit_region ) {
    $visit_prefecture = (string) $visit_region['prefecture'];
    if ( ! isset( $visit_areas[ $visit_prefecture ] ) ) {
        $visit_areas[ $visit_prefecture ] = array(
            'prefecture' => $visit_prefecture,
            'municipalities' => array(),
        );
    }
    $visit_areas[ $visit_prefecture ]['municipalities'][] = array(
        'name' => (string) $visit_region['city'],
        'coverage' => isset( $visit_region['coverage_label'] ) ? (string) $visit_region['coverage_label'] : '全' . count( $visit_region['areas'] ) . (string) $visit_region['area_unit'],
        'description' => (string) $visit_region['city'] . 'の対応地域でお伺いします。',
        'status' => '対応中',
        'url' => home_url( '/visit/' . rawurlencode( (string) $visit_region_slug ) . '/' ),
        'districts' => $visit_region['areas'],
    );
}
$visit_areas = array_values( $visit_areas );
$visit_area_summary_parts = array();
foreach ( $visit_regions as $visit_region ) {
    $visit_coverage = isset( $visit_region['coverage_label'] ) ? (string) $visit_region['coverage_label'] : '全' . count( $visit_region['areas'] ) . (string) $visit_region['area_unit'];
    $visit_area_summary_parts[] = (string) $visit_region['city'] . '（' . $visit_coverage . '）';
}
$visit_area_summary = implode( '、', $visit_area_summary_parts );
$visit_common_faq_items = require get_stylesheet_directory() . '/visit-lp-draft/visit/faq-common.php';

add_action( 'wp_enqueue_scripts', static function () {
    if ( ! is_page_template( 'page-visit.php' ) ) {
        return;
    }

    $visit_asset_uri = get_stylesheet_directory_uri() . '/visit-lp-draft/';
    wp_enqueue_style( 'ouchi-visit-lp', $visit_asset_uri . 'visit.css', array(), '1.0.5' );
    wp_enqueue_script( 'ouchi-visit-lp', $visit_asset_uri . 'visit.js', array(), '1.0.0', true );
} );

get_header();
?>
<div class="visit-page">
<a class="visit-skip-link" href="#visit-main">本文へ移動</a><main id="visit-main">
    <section class="visit-hero" aria-labelledby="visit-hero-title">
      <div class="visit-container visit-hero__grid">
        <div class="visit-hero__copy">
          <p class="visit-eyebrow"><span></span>現在対応中の地域をご案内</p>
          <h1 id="visit-hero-title">売りたいものは、<br><em>玄関前に置くだけ。</em></h1>
          <p class="visit-hero__lead">スタッフと会わずに、自宅にいなくても。<br>ご指定の場所から、まとめてお引き取りします。</p>
          <div class="visit-hero__chips" aria-label="出張買取の特徴">
            <span>非対面で受け渡し</span><span>ご不在でも回収</span><span>箱詰め不要</span>
          </div>
          <p class="visit-hero__note">本・CD・DVD・ゲーム・カメラ・スマートフォン・パソコンなど<br><strong>現在の対応地域：<?php echo esc_html( $visit_area_summary ); ?></strong></p>
          <a class="visit-button visit-button--primary" href="#visit-area">お申し込みはこちらから <span aria-hidden="true">↓</span></a>
          <p class="visit-hero__fineprint">非対面回収には事前の本人確認と回収場所の条件があります。<br>予約受付ページは準備中です。</p>
        </div>
        <div class="visit-hero-art">
          <img src="<?php echo esc_url( get_stylesheet_directory_uri() . '/visit-lp-draft/assets/sunny-entryway-pickup.webp' ); ?>" width="1536" height="1024" alt="日差しの差し込む玄関前に、本やゲーム機、カメラが入った笑顔の段ボール箱を置いたイラスト" fetchpriority="high" decoding="async">
        </div>
      </div>
      <div class="visit-container visit-trust-row">
        <span><i aria-hidden="true">01</i> 自宅前で受け渡し</span>
        <span><i aria-hidden="true">02</i> ご不在でもOK</span>
        <span><i aria-hidden="true">03</i> 対面での受け渡しも選べます</span>
      </div>
    </section>

    <section class="visit-section visit-contactless" aria-labelledby="visit-contactless-title">
      <div class="visit-container visit-contactless__grid">
        <div class="visit-contactless__visual">
          <img class="visit-contactless__image" src="<?php echo esc_url( get_stylesheet_directory_uri() . '/visit-lp-draft/assets/contactless-pickup-staff.webp' ); ?>" width="1536" height="1024" alt="おうち買取便のスタッフが玄関先で買取品の箱を受け取るイラスト" loading="lazy" decoding="async">
          <div class="visit-round-stamp"><span>会わずに<br>完了</span><b>非対面</b></div>
        </div>
        <div class="visit-contactless__copy">
          <p class="visit-eyebrow">YOUR TIME, YOUR WAY</p>
          <h2 id="visit-contactless-title">会わなくていい。<br>待たなくていい。</h2>
          <p class="visit-body-lead">出張買取の受け渡しは、対面・非対面からお選びいただけます。</p>
          <ul class="visit-check-list">
            <li><span aria-hidden="true">✓</span><div><strong>スタッフと顔を合わせずに受け渡し</strong><small>事前に決めた玄関前などへ置いておくだけ。</small></div></li>
            <li><span aria-hidden="true">✓</span><div><strong>お出かけ中の回収も相談できます</strong><small>回収時にご在宅いただく必要はありません。</small></div></li>
            <li><span aria-hidden="true">✓</span><div><strong>対面で直接渡したい方にも対応</strong><small>スタッフに商品を手渡す方法もお選びいただけます。</small></div></li>
          </ul>
          <p class="visit-callout"><strong>安心してご利用いただくために</strong><br>非対面回収では、事前にWEB上で所定の本人確認を行います。また、回収場所はスタッフが安全に立ち入り、商品を確認できる場所に限ります。詳しい条件は受付開始時にご案内します。</p>
        </div>
      </div>
    </section>
    <div class="visit-container visit-area-cta">
      <a class="visit-button visit-button--primary" href="#visit-area">お申し込みはこちらから <span aria-hidden="true">↓</span></a>
      <p class="visit-area-cta__note">対応エリアを確認して、地域ページへお進みください。</p>
    </div>

    <?php if ( shortcode_exists( 'sc' ) ) : ?>
    <section class="visit-section visit-reviews" aria-labelledby="visit-reviews-title">
      <div class="visit-container">
        <div class="voice-section">
          <div class="voice-inner">
            <p class="voice-label">Customer Voice</p>
            <div class="voice-title" id="visit-reviews-title">おうち買取便を利用した方の口コミ</div>
            <p class="voice-lead">
              おうち買取便をご利用いただいたお客様から、うれしいお声をいただいています。
            </p>
            <?php echo do_shortcode( '[sc file=review_incert]' ); ?>
          </div>
        </div>
      </div>
    </section>
    <?php endif; ?>

    <!-- 既存トップページの作業風景動画コードを挿入する枠。開発プレビューでのみ表示できます。 -->
    <section class="visit-insert visit-video" aria-labelledby="visit-video-title">
      <div class="visit-container"><p class="visit-eyebrow">OUR WORK</p><h2 id="visit-video-title">おうち買取便の作業風景</h2>
        <!-- 既存トップページの動画表示コードをここに挿入する -->
        <div class="visit-content-placeholder">既存の作業風景動画コードを挿入</div>
      </div>
    </section>

    <!-- 既存トップページのメリット表示コードを挿入する枠。開発プレビューでのみ表示できます。 -->
    <section class="visit-insert visit-benefits" aria-labelledby="visit-benefits-title">
      <div class="visit-container"><p class="visit-eyebrow">WHY OUCHI KAITORI</p><h2 id="visit-benefits-title">おうち買取便のメリット</h2>
        <!-- 既存トップページのメリット表示コードをここに挿入する -->
        <div class="visit-content-placeholder">既存のメリット表示コードを挿入</div>
      </div>
    </section>

    <section class="visit-section visit-items" id="visit-items" aria-labelledby="visit-items-title">
      <div class="visit-container">
        <div class="visit-section-heading"><div><p class="visit-eyebrow">WHAT WE BUY</p><h2 id="visit-items-title">買取できるもの</h2></div><figure class="visit-items-illustration"><img src="<?php echo esc_url( get_stylesheet_directory_uri() . '/visit-lp-draft/assets/accepted-items-visit-v2.webp' ); ?>" width="1536" height="1024" alt="本、CD、DVD、ゲーム機、カメラ、スマートフォン、パソコン、美容機器、AV機器、電動工具のイラスト" loading="lazy" decoding="async"></figure></div>
        <ul class="visit-item-grid">
          <li class="visit-item-card"><span class="visit-item-icon" aria-hidden="true"><img src="<?php echo esc_url( get_stylesheet_directory_uri() . '/visit-lp-draft/assets/purchase-items/item-books.webp' ); ?>" width="200" height="200" alt="" loading="lazy" decoding="async"></span><strong>本・漫画・コミック</strong><small>小説、専門書、コミックなど</small></li>
          <li class="visit-item-card"><span class="visit-item-icon" aria-hidden="true"><img src="<?php echo esc_url( get_stylesheet_directory_uri() . '/visit-lp-draft/assets/purchase-items/item-music-cd.webp' ); ?>" width="200" height="200" alt="" loading="lazy" decoding="async"></span><strong>CD</strong><small>音楽CDなど</small></li>
          <li class="visit-item-card"><span class="visit-item-icon" aria-hidden="true"><img src="<?php echo esc_url( get_stylesheet_directory_uri() . '/visit-lp-draft/assets/purchase-items/item-dvd-bluray.webp' ); ?>" width="200" height="200" alt="" loading="lazy" decoding="async"></span><strong>DVD・Blu-ray</strong><small>映像ソフトなど</small></li>
          <li class="visit-item-card"><span class="visit-item-icon" aria-hidden="true"><img src="<?php echo esc_url( get_stylesheet_directory_uri() . '/visit-lp-draft/assets/purchase-items/item-game-software.webp' ); ?>" width="200" height="200" alt="" loading="lazy" decoding="async"></span><strong>ゲームソフト</strong><small>各種ゲームソフト</small></li>
          <li class="visit-item-card"><span class="visit-item-icon" aria-hidden="true"><img src="<?php echo esc_url( get_stylesheet_directory_uri() . '/visit-lp-draft/assets/purchase-items/item-game-console.webp' ); ?>" width="200" height="200" alt="" loading="lazy" decoding="async"></span><strong>ゲーム機本体</strong><small>携帯型・据え置き型</small></li>
          <li class="visit-item-card"><span class="visit-item-icon" aria-hidden="true"><img src="<?php echo esc_url( get_stylesheet_directory_uri() . '/visit-lp-draft/assets/purchase-items/item-camera.webp' ); ?>" width="200" height="200" alt="" loading="lazy" decoding="async"></span><strong>カメラ</strong><small>デジタルカメラなど</small></li>
          <li class="visit-item-card"><span class="visit-item-icon" aria-hidden="true"><img src="<?php echo esc_url( get_stylesheet_directory_uri() . '/visit-lp-draft/assets/purchase-items/item-smartphones.webp' ); ?>" width="200" height="200" alt="" loading="lazy" decoding="async"></span><strong>スマホ</strong><small>スマートフォン</small></li>
          <li class="visit-item-card"><span class="visit-item-icon" aria-hidden="true"><img src="<?php echo esc_url( get_stylesheet_directory_uri() . '/visit-lp-draft/assets/purchase-items/item-tablet.webp' ); ?>" width="200" height="200" alt="" loading="lazy" decoding="async"></span><strong>タブレット</strong><small>各種タブレット端末</small></li>
          <li class="visit-item-card"><span class="visit-item-icon" aria-hidden="true"><img src="<?php echo esc_url( get_stylesheet_directory_uri() . '/visit-lp-draft/assets/purchase-items/item-desktop-computer.webp' ); ?>" width="200" height="200" alt="" loading="lazy" decoding="async"></span><strong>デスクトップパソコン</strong><small>モニタのみは買取不可</small></li>
          <li class="visit-item-card"><span class="visit-item-icon" aria-hidden="true"><img src="<?php echo esc_url( get_stylesheet_directory_uri() . '/visit-lp-draft/assets/purchase-items/item-laptop.webp' ); ?>" width="200" height="200" alt="" loading="lazy" decoding="async"></span><strong>ノートパソコン</strong><small>各種ノートパソコン</small></li>
          <li class="visit-item-card"><span class="visit-item-icon" aria-hidden="true"><img src="<?php echo esc_url( get_stylesheet_directory_uri() . '/visit-lp-draft/assets/purchase-items/item-beauty-devices.webp' ); ?>" width="200" height="200" alt="" loading="lazy" decoding="async"></span><strong>美容機器</strong><small>美顔器・脱毛器・小型マッサージ器・電気シェーバー</small></li>
          <li class="visit-item-card"><span class="visit-item-icon" aria-hidden="true"><img src="<?php echo esc_url( get_stylesheet_directory_uri() . '/visit-lp-draft/assets/purchase-items/item-av-equipment.webp' ); ?>" width="200" height="200" alt="" loading="lazy" decoding="async"></span><strong>AV機器</strong><small>HDDレコーダー・プロジェクター・ICレコーダー（テレビ不可）</small></li>
          <li class="visit-item-card"><span class="visit-item-icon" aria-hidden="true"><img src="<?php echo esc_url( get_stylesheet_directory_uri() . '/visit-lp-draft/assets/purchase-items/item-power-tools.webp' ); ?>" width="200" height="200" alt="" loading="lazy" decoding="async"></span><strong>電動工具</strong><small>電動工具各種</small></li>
        </ul>
      </div>
    </section>
    <div class="visit-container visit-area-cta">
      <a class="visit-button visit-button--primary" href="#visit-area">お申し込みはこちらから <span aria-hidden="true">↓</span></a>
      <p class="visit-area-cta__note">対応エリアを確認して、地域ページへお進みください。</p>
    </div>

    <!-- 既存トップページの最新買取価格コードを挿入する枠。開発プレビューでのみ表示できます。 -->
    <section class="visit-insert visit-prices" aria-labelledby="visit-prices-title">
      <div class="visit-container"><p class="visit-eyebrow">RECENT PRICES</p><h2 id="visit-prices-title">最新の買取価格の一例</h2>
        <!-- 既存トップページの買取価格表示コードをここに挿入する -->
        <div class="visit-content-placeholder">既存の買取価格表示コードを挿入</div>
      </div>
    </section>

    <section class="visit-section visit-flow" id="visit-how" aria-labelledby="visit-flow-title">
      <div class="visit-container">
        <div class="visit-section-heading visit-section-heading--center"><div><p class="visit-eyebrow">HOW IT WORKS</p><h2 id="visit-flow-title">出張買取の流れ</h2></div><p>お申し込みからお振り込みまで、<br>5つのステップでご案内します。</p></div>
        <ol class="visit-flow-list">
          <li class="visit-flow-card"><span class="visit-flow-card__step">STEP <b>01</b></span><span class="visit-flow-card__icon" aria-hidden="true">⌕</span><h3>ネットから申し込み</h3><p>対応エリアを選び、地域ページから回収日時を予約します。</p></li>
          <li class="visit-flow-card"><span class="visit-flow-card__step">STEP <b>02</b></span><span class="visit-flow-card__icon" aria-hidden="true">▣</span><h3>本人確認</h3><p>対面は回収時に本人確認書類の原本を提示。非対面はWEB上で所定の本人確認を行います。</p></li>
          <li class="visit-flow-card"><span class="visit-flow-card__step">STEP <b>03</b></span><span class="visit-flow-card__icon" aria-hidden="true">⌂</span><h3>商品を回収</h3><p>対面はスタッフへ直接お渡し。非対面は事前に指定した玄関前などへ置いておきます。</p></li>
          <li class="visit-flow-card"><span class="visit-flow-card__step">STEP <b>04</b></span><span class="visit-flow-card__icon" aria-hidden="true">⌕</span><h3>持ち帰って査定</h3><p>スタッフが商品を持ち帰り、1点ずつ丁寧に査定します。</p></li>
          <li class="visit-flow-card"><span class="visit-flow-card__step">STEP <b>05</b></span><span class="visit-flow-card__icon" aria-hidden="true">¥</span><h3>査定結果の確認・振込</h3><p>査定結果をご確認・承認いただいた後、銀行口座へ代金をお振り込みします。</p></li>
        </ol>
        <p class="visit-flow-note">※ご利用条件・予約方法は受付開始時にご案内します。予約ページは現在準備中です。</p>
      </div>
    </section>
    <div class="visit-container visit-area-cta">
      <a class="visit-button visit-button--primary" href="#visit-area">お申し込みはこちらから <span aria-hidden="true">↓</span></a>
      <p class="visit-area-cta__note">対応エリアを確認して、地域ページへお進みください。</p>
    </div>

    <section class="visit-section visit-area" id="visit-area" aria-labelledby="visit-area-title">
      <div class="visit-container">
        <div class="visit-area-heading"><div><p class="visit-eyebrow">SERVICE AREA</p><h2 id="visit-area-title">出張買取の対応エリア</h2></div><p>現在は<?php echo esc_html( $visit_area_summary ); ?>で出張買取を承っています。<br>対応地域は順次拡大予定です。</p></div>
        <div class="visit-prefecture-grid" data-visit-areas>
          <?php foreach ( $visit_areas as $visit_area ) : ?>
            <article class="visit-prefecture-card" data-prefecture="<?php echo esc_attr( $visit_area['prefecture'] ); ?>">
              <div class="visit-prefecture-card__top">
                <span class="visit-prefecture-card__pin" aria-hidden="true">⌖</span>
                <div><span class="visit-prefecture-card__status"><i></i> 対応中</span><h3><?php if ( 1 === count( $visit_area['municipalities'] ) && ! empty( $visit_area['municipalities'][0]['url'] ) ) : ?><a class="visit-prefecture-link" href="<?php echo esc_url( $visit_area['municipalities'][0]['url'] ); ?>" aria-label="<?php echo esc_attr( $visit_area['prefecture'] . '（' . $visit_area['municipalities'][0]['name'] . '）の対応地域ページを見る' ); ?>"><?php echo esc_html( $visit_area['prefecture'] ); ?></a><?php else : ?><?php echo esc_html( $visit_area['prefecture'] ); ?><?php endif; ?></h3></div>
                <span class="visit-prefecture-card__count"><?php echo esc_html( (string) count( $visit_area['municipalities'] ) ); ?> <small>自治体</small></span>
              </div>
              <?php foreach ( $visit_area['municipalities'] as $visit_city ) : ?>
                <div class="visit-municipality">
                  <div class="visit-municipality__heading">
                    <strong>
                      <?php if ( ! empty( $visit_city['url'] ) ) : ?>
                        <a class="visit-city-detail-link" href="<?php echo esc_url( $visit_city['url'] ); ?>" aria-label="<?php echo esc_attr( $visit_city['name'] . '（' . $visit_city['coverage'] . '）の対応地域ページを見る' ); ?>"><?php echo esc_html( $visit_city['name'] . '（' . $visit_city['coverage'] . '）の対応地域ページを見る' ); ?></a>
                      <?php else : ?>
                        <?php echo esc_html( $visit_city['name'] . '（' . $visit_city['coverage'] . '）' ); ?>
                      <?php endif; ?>
                    </strong>
                    <span><?php echo esc_html( $visit_city['status'] ); ?></span>
                  </div>
                  <p><?php echo esc_html( $visit_city['description'] ); ?></p>
                  <?php if ( ! empty( $visit_city['districts'] ) ) : ?>
                    <ul aria-label="<?php echo esc_attr( $visit_city['name'] . 'の対応区' ); ?>">
                      <?php foreach ( $visit_city['districts'] as $visit_district ) : ?><li><a class="visit-area-district-link" href="<?php echo esc_url( $visit_city['url'] ); ?>" aria-label="<?php echo esc_attr( $visit_city['name'] . 'の出張買取対応地域（' . $visit_district . '）を確認する' ); ?>"><?php echo esc_html( $visit_district ); ?></a></li><?php endforeach; ?>
                    </ul>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </article>
          <?php endforeach; ?>
        </div>
        <p class="visit-area-footnote"><span aria-hidden="true">i</span> 都道府県全域ではなく、カードに記載した市区町村が現在の対応地域です。</p>
      </div>
    </section>

    <!-- 既存トップページのおすすめ記事コードを挿入する枠。開発プレビューでのみ表示できます。 -->
    <section class="visit-insert visit-articles" aria-labelledby="visit-articles-title">
      <div class="visit-container"><p class="visit-eyebrow">JOURNAL</p><h2 id="visit-articles-title">おすすめ記事</h2>
        <!-- 既存トップページの記事表示コードをここに挿入する -->
        <div class="visit-content-placeholder">既存のおすすめ記事表示コードを挿入</div>
      </div>
    </section>

    <section class="visit-section visit-faq" id="visit-faq" aria-labelledby="visit-faq-title">
      <div class="visit-container visit-faq__grid">
        <div class="visit-faq__intro"><p class="visit-eyebrow">FAQ</p><h2 id="visit-faq-title">よくある質問</h2><p>出張買取・非対面回収について、よくいただくご質問をまとめました。</p><span class="visit-faq__decor" aria-hidden="true">?</span></div>
        <div class="visit-faq-list">
          <details class="visit-faq-item"><summary><span>Q</span>今はどの地域で利用できますか？<i aria-hidden="true"></i></summary><p>現在の対応地域は<?php echo esc_html( $visit_area_summary ); ?>です。都道府県全域ではなく、カードに記載した市区町村が対象です。</p></details>
          <?php foreach ( $visit_common_faq_items as $visit_faq_item ) : ?>
            <details class="visit-faq-item"><summary><span>Q</span><?php echo esc_html( $visit_faq_item['question'] ); ?><i aria-hidden="true"></i></summary><p><?php echo esc_html( $visit_faq_item['answer'] ); ?></p></details>
          <?php endforeach; ?>
        </div>
      </div>
    </section>

    <!-- 既存トップページのキャンペーンクーポンコードを挿入する枠。開発プレビューでのみ表示できます。 -->
    <section class="visit-insert visit-coupons" aria-labelledby="visit-coupons-title">
      <div class="visit-container"><p class="visit-eyebrow">SPECIAL OFFER</p><h2 id="visit-coupons-title">キャンペーンクーポン</h2>
        <!-- 既存トップページのクーポン表示コードをここに挿入する -->
        <div class="visit-content-placeholder">既存のキャンペーン・クーポン表示コードを挿入</div>
      </div>
    </section>

    <section class="visit-final-cta" aria-labelledby="visit-final-cta-title">
      <div class="visit-container visit-final-cta__inner"><div class="visit-final-cta__text"><p class="visit-eyebrow">SERVICE AREAS</p><h2 id="visit-final-cta-title">まずは対応エリアを<br>ご確認ください。</h2><p>現在対応中の地域：<?php echo esc_html( $visit_area_summary ); ?>。予約受付ページは準備中です。</p></div><a class="visit-button visit-button--light" href="#visit-area">お申し込みはこちらから <span aria-hidden="true">↑</span></a><span class="visit-final-cta__spark" aria-hidden="true">✳</span></div>
    </section>
  </main></div>
<?php get_footer(); ?>
