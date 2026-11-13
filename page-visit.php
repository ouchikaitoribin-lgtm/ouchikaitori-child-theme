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

add_action( 'wp_enqueue_scripts', static function () {
    if ( ! is_page_template( 'page-visit.php' ) ) {
        return;
    }

    $visit_asset_uri = get_stylesheet_directory_uri() . '/visit-lp-draft/';
    wp_enqueue_style( 'ouchi-visit-lp', $visit_asset_uri . 'visit.css', array(), '1.0.0' );
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
          <p class="visit-hero__note">本・CD・DVD・ゲーム・カメラ・小型家電など<br><strong>現在の対応地域：<?php echo esc_html( $visit_area_summary ); ?></strong></p>
          <a class="visit-button visit-button--primary" href="#visit-area">対応エリアを確認する <span aria-hidden="true">↓</span></a>
          <p class="visit-hero__fineprint">非対面回収には事前の本人確認と回収場所の条件があります。<br>予約受付ページは準備中です。</p>
        </div>
        <div class="visit-hero-art">
          <img src="<?php echo esc_url( get_stylesheet_directory_uri() . '/visit-lp-draft/assets/hero-pickup-v2.webp' ); ?>" width="1536" height="1024" alt="閉じた玄関ドアの外に本やゲーム機を置いておく置き配のイメージ" fetchpriority="high" decoding="async">
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
          <img class="visit-contactless__image" src="<?php echo esc_url( get_stylesheet_directory_uri() . '/visit-lp-draft/assets/contactless-pickup-v2.webp' ); ?>" width="1536" height="1024" alt="閉じた玄関ドアの外に置いた買取品を、後からスタッフが回収する置き配のイメージ" loading="lazy" decoding="async">
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

    <!-- 既存トップページの口コミコードを挿入する枠。開発プレビューでのみ表示できます。 -->
    <section class="visit-insert visit-reviews" aria-labelledby="visit-reviews-title">
      <div class="visit-container"><p class="visit-eyebrow">CUSTOMER VOICE</p><h2 id="visit-reviews-title">お客様の声</h2>
        <!-- 既存トップページの口コミ表示コードをここに挿入する -->
        <div class="visit-content-placeholder">既存の口コミ表示コードを挿入</div>
      </div>
    </section>

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
        <div class="visit-section-heading"><div><p class="visit-eyebrow">WHAT WE BUY</p><h2 id="visit-items-title">買取できるもの</h2></div><figure class="visit-items-illustration"><img src="<?php echo esc_url( get_stylesheet_directory_uri() . '/visit-lp-draft/assets/accepted-items.webp' ); ?>" width="1536" height="1024" alt="本、CD、DVD、ゲーム機、カメラ、小型家電のイラスト" loading="lazy" decoding="async"></figure></div>
        <ul class="visit-item-grid">
          <li class="visit-item-card"><span class="visit-item-icon" aria-hidden="true"><svg viewBox="0 0 48 48"><path d="M10 8h25v32H10zM15 13h15M15 18h15M15 23h12M35 12h4v28h-4"/></svg></span><strong>本・漫画・コミック</strong><small>小説、専門書、コミックなど</small></li>
          <li class="visit-item-card"><span class="visit-item-icon" aria-hidden="true"><svg viewBox="0 0 48 48"><circle cx="24" cy="24" r="16"/><circle cx="24" cy="24" r="5"/><circle cx="24" cy="24" r="1"/></svg></span><strong>CD</strong><small>音楽CDなど</small></li>
          <li class="visit-item-card"><span class="visit-item-icon" aria-hidden="true"><svg viewBox="0 0 48 48"><rect x="8" y="8" width="32" height="32" rx="4"/><path d="M12 13h24v22H12zM18 39v-4m12 4v-4"/><circle cx="24" cy="24" r="4"/></svg></span><strong>DVD・Blu-ray</strong><small>映像ソフトなど</small></li>
          <li class="visit-item-card"><span class="visit-item-icon" aria-hidden="true"><svg viewBox="0 0 48 48"><rect x="13" y="6" width="22" height="36" rx="4"/><path d="M20 11h8M21 36h6"/><circle cx="24" cy="25" r="5"/></svg></span><strong>ゲームソフト</strong><small>各種ゲームソフト</small></li>
          <li class="visit-item-card"><span class="visit-item-icon" aria-hidden="true"><svg viewBox="0 0 48 48"><rect x="6" y="15" width="36" height="18" rx="8"/><path d="M15 24h8m-4-4v8m13-6h.1m4 4h.1"/></svg></span><strong>ゲーム機本体</strong><small>携帯型・据え置き型</small></li>
          <li class="visit-item-card"><span class="visit-item-icon" aria-hidden="true"><svg viewBox="0 0 48 48"><path d="M8 18h8l4-5h9l4 5h7v20H8z"/><circle cx="24" cy="28" r="8"/><circle cx="24" cy="28" r="4"/><path d="M35 22h.1"/></svg></span><strong>カメラ</strong><small>デジタルカメラなど</small></li>
          <li class="visit-item-card"><span class="visit-item-icon" aria-hidden="true"><svg viewBox="0 0 48 48"><rect x="12" y="7" width="24" height="34" rx="3"/><path d="M17 13h14v16H17zM19 35h10"/><circle cx="24" cy="33" r="1"/></svg></span><strong>小型家電</strong><small>大型家電を除く家電製品</small></li>
        </ul>
        <p class="visit-exclusion"><span aria-hidden="true">!</span><span><strong>買取対象外</strong> テレビ・冷蔵庫・洗濯機・エアコンなどの大型家電は対象外です。</span></p>
      </div>
    </section>

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

    <section class="visit-section visit-area" id="visit-area" aria-labelledby="visit-area-title">
      <div class="visit-container">
        <div class="visit-area-heading"><div><p class="visit-eyebrow">SERVICE AREA</p><h2 id="visit-area-title">出張買取の対応エリア</h2></div><p>現在は<?php echo esc_html( $visit_area_summary ); ?>で出張買取を承っています。<br>対応地域は順次拡大予定です。</p></div>
        <div class="visit-prefecture-grid" data-visit-areas>
          <?php foreach ( $visit_areas as $visit_area ) : ?>
            <article class="visit-prefecture-card" data-prefecture="<?php echo esc_attr( $visit_area['prefecture'] ); ?>">
              <div class="visit-prefecture-card__top">
                <span class="visit-prefecture-card__pin" aria-hidden="true">⌖</span>
                <div><span class="visit-prefecture-card__status"><i></i> 対応中</span><h3><?php echo esc_html( $visit_area['prefecture'] ); ?></h3></div>
                <span class="visit-prefecture-card__count"><?php echo esc_html( (string) count( $visit_area['municipalities'] ) ); ?> <small>自治体</small></span>
              </div>
              <?php foreach ( $visit_area['municipalities'] as $visit_city ) : ?>
                <div class="visit-municipality">
                  <div class="visit-municipality__heading">
                    <strong>
                      <?php if ( ! empty( $visit_city['url'] ) ) : ?>
                        <a href="<?php echo esc_url( $visit_city['url'] ); ?>"><?php echo esc_html( $visit_city['name'] ); ?></a>
                      <?php else : ?>
                        <?php echo esc_html( $visit_city['name'] ); ?>
                      <?php endif; ?>
                      （<?php echo esc_html( $visit_city['coverage'] ); ?>）
                    </strong>
                    <span><?php echo esc_html( $visit_city['status'] ); ?></span>
                  </div>
                  <p><?php echo esc_html( $visit_city['description'] ); ?></p>
                  <?php if ( ! empty( $visit_city['districts'] ) ) : ?>
                    <ul aria-label="<?php echo esc_attr( $visit_city['name'] . 'の対応区' ); ?>">
                      <?php foreach ( $visit_city['districts'] as $visit_district ) : ?><li><?php echo esc_html( $visit_district ); ?></li><?php endforeach; ?>
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
          <details class="visit-faq-item"><summary><span>Q</span>非対面での回収はできますか？<i aria-hidden="true"></i></summary><p>はい。事前にWEB上で所定の本人確認を行い、回収場所などの条件を確認したうえで、玄関前など指定場所から回収します。</p></details>
          <details class="visit-faq-item"><summary><span>Q</span>回収時に家にいる必要はありますか？<i aria-hidden="true"></i></summary><p>非対面回収では、事前に決めた場所へ商品を置いていただければ、ご不在中の回収も可能です。場所はスタッフが安全に確認できる範囲に限ります。</p></details>
          <details class="visit-faq-item"><summary><span>Q</span>スタッフに直接渡すこともできますか？<i aria-hidden="true"></i></summary><p>はい。ご希望に応じて、回収時にスタッフへ直接お渡しいただく方法もお選びいただけます。</p></details>
          <details class="visit-faq-item"><summary><span>Q</span>大型家電も買い取ってもらえますか？<i aria-hidden="true"></i></summary><p>テレビ・冷蔵庫・洗濯機・エアコンなどの大型家電は買取対象外です。小型家電は対象商品としてご案内しています。</p></details>
          <details class="visit-faq-item"><summary><span>Q</span>費用や最低点数、査定後の返却について教えてください。<i aria-hidden="true"></i></summary><p>費用・最低買取数量・返却条件は、公開前に確定した利用条件を設定してご案内します。現時点では本ページに未確定の条件を記載していません。</p></details>
          <details class="visit-faq-item"><summary><span>Q</span>予約はどこからできますか？<i aria-hidden="true"></i></summary><p>予約ページは現在準備中です。対応地域や予約方法が整い次第、このページからご案内します。</p></details>
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
      <div class="visit-container visit-final-cta__inner"><div class="visit-final-cta__text"><p class="visit-eyebrow">SERVICE AREAS</p><h2 id="visit-final-cta-title">まずは対応エリアを<br>ご確認ください。</h2><p>現在対応中の地域：<?php echo esc_html( $visit_area_summary ); ?>。予約受付ページは準備中です。</p></div><a class="visit-button visit-button--light" href="#visit-area">対応エリアを見る <span aria-hidden="true">↑</span></a><span class="visit-final-cta__spark" aria-hidden="true">✳</span></div>
    </section>
  </main></div>
<?php get_footer(); ?>
