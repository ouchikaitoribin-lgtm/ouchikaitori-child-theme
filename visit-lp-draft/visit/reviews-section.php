<?php
/**
 * Shared customer review section for the visit landing page and regional pages.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! shortcode_exists( 'sc' ) ) {
	return;
}
?>
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
