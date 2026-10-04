<?php
/**
 * Link existing booth products: preview with «قطعی / مشکوک / بدون جفت», approve to link.
 *
 * @package SalamHub
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
$slh_tab   = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'certain';
$slh_paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
// phpcs:enable
$slh_tabs = array(
	'certain' => __( 'قطعی', 'salamhub' ),
	'suspect' => __( 'مشکوک', 'salamhub' ),
	'none'    => __( 'بدون جفت', 'salamhub' ),
	'linked'  => __( 'متصل‌شده', 'salamhub' ),
);
$slh_tab     = isset( $slh_tabs[ $slh_tab ] ) ? $slh_tab : 'certain';
$slh_state   = SLH_Linker::state();
$slh_running = SLH_Linker::is_running();
$slh_counts  = SLH_Linker::counts();
$slh_rows    = SLH_Linker::rows( $slh_tab, $slh_paged, 50 );
$slh_pages   = (int) ceil( $slh_rows['total'] / 50 );
$slh_mult    = SLH_Product_Mapper::rial_multiplier();
$slh_base    = admin_url( 'admin.php?page=salamhub-link' );
$slh_any     = array_sum( $slh_counts ) > 0;

/** A WooCommerce product summary (title, SKU, price) for the right column. */
$slh_wc_cell = function ( $wc_id ) {
	$p = $wc_id ? wc_get_product( $wc_id ) : null;
	if ( ! $p ) {
		return '<span class="slh-muted">—</span>';
	}
	$meta = array();
	if ( $p->get_sku() ) {
		$meta[] = 'SKU: ' . $p->get_sku();
	}
	if ( '' !== $p->get_price() ) {
		$meta[] = wp_strip_all_tags( wc_price( $p->get_price() ) );
	}
	if ( $p->is_type( 'variable' ) ) {
		/* translators: %s: count */
		$meta[] = sprintf( __( '%s تنوع', 'salamhub' ), slh_fa_number( count( $p->get_children() ) ) );
	}
	return '<a href="' . esc_url( get_edit_post_link( $p->get_id() ) ) . '">' . esc_html( $p->get_name() ) . '</a><span class="slh-table__why">' . esc_html( implode( ' · ', $meta ) ) . '</span>';
};
?>
<header class="slh-page-head slh-page-head--row">
	<div>
		<h1 class="slh-page-title"><?php esc_html_e( 'اتصال محصولات غرفه', 'salamhub' ); ?></h1>
		<p class="slh-card__meta"><?php esc_html_e( 'محصولاتی که از قبل در غرفه‌ی باسلام داری را به محصولات سایت وصل کن تا دوباره ساخته نشوند. اول پیش‌نمایش می‌بینی؛ تا تأیید نکنی چیزی متصل نمی‌شود.', 'salamhub' ); ?></p>
	</div>
	<button type="button" class="slh-btn<?php echo $slh_any ? '' : ' slh-btn--primary'; ?>" data-slh-link-start <?php disabled( $slh_running || ! SLH_Settings::is_connected() ); ?>>
		<span class="dashicons dashicons-update" aria-hidden="true"></span>
		<?php echo $slh_any ? esc_html__( 'دریافت و تطبیق دوباره', 'salamhub' ) : esc_html__( 'دریافت محصولات غرفه و تطبیق', 'salamhub' ); ?>
	</button>
</header>

<section class="slh-card slh-section" data-slh-link-progress data-running="<?php echo $slh_running ? '1' : '0'; ?>" <?php echo $slh_running ? '' : 'hidden'; ?>>
	<div data-slh-link-html><?php SLH_Admin_Tools::link_progress_html( $slh_state ); ?></div>
	<p class="slh-field__hint" data-slh-link-message aria-live="polite"></p>
</section>

<?php if ( 'failed' === $slh_state['status'] ) : ?>
	<p class="slh-alert slh-alert--error"><?php echo esc_html( $slh_state['error'] ); ?></p>
<?php endif; ?>

<?php if ( ! $slh_any && ! $slh_running ) : ?>
	<div class="slh-card slh-empty">
		<span class="dashicons dashicons-admin-links" aria-hidden="true"></span>
		<p>
			<?php
			if ( 'ready' === $slh_state['status'] ) {
				esc_html_e( 'غرفه‌ی باسلام هنوز محصولی ندارد؛ چیزی برای اتصال نیست. محصولات سایت را از «ارسال گروهی» بفرست.', 'salamhub' );
			} else {
				esc_html_e( 'هنوز محصولات غرفه دریافت نشده. دکمه‌ی بالا را بزن؛ تطبیق با SKU و بعد با نام انجام می‌شود و نتیجه در سه دسته‌ی قطعی، مشکوک و بدون جفت نمایش داده می‌شود.', 'salamhub' );
			}
			?>
		</p>
	</div>
<?php elseif ( $slh_any ) : ?>
	<?php if ( 'ready' === $slh_state['status'] && $slh_state['finished_at'] ) : ?>
		<?php /* translators: %s: time */ ?>
		<p class="slh-card__meta slh-section-gap"><?php echo esc_html( sprintf( __( 'آخرین تطبیق: %s', 'salamhub' ), slh_time_ago( $slh_state['finished_at'] ) ) ); ?></p>
	<?php endif; ?>

	<nav class="slh-tabs" aria-label="<?php esc_attr_e( 'نتیجه‌ی تطبیق', 'salamhub' ); ?>">
		<?php foreach ( $slh_tabs as $slh_key => $slh_label ) : ?>
			<a class="slh-tabs__item<?php echo $slh_key === $slh_tab ? ' is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'tab', $slh_key, $slh_base ) ); ?>" <?php echo $slh_key === $slh_tab ? 'aria-current="page"' : ''; ?>>
				<?php echo esc_html( $slh_label ); ?>
				<span class="slh-tabs__count"><?php echo esc_html( slh_fa_number( $slh_counts[ $slh_key ] ) ); ?></span>
			</a>
		<?php endforeach; ?>
	</nav>

	<?php
	$slh_hints = array(
		'certain' => __( 'SKU این محصولات در باسلام و سایت یکی است. با اطمینان می‌توانی همه را تأیید کنی.', 'salamhub' ),
		'suspect' => __( 'جفت پیشنهادی بر اساس نام است یا تعارض دارد. هر ردیف را نگاه کن؛ اگر جفت درست نیست، از فهرست گزینه‌ی دیگری انتخاب کن.', 'salamhub' ),
		'none'    => __( 'برای این محصولات جفتی در سایت پیدا نشد. می‌توانی بعداً آن‌ها را از باسلام به سایت ایمپورت کنی (نسخه‌ی بعدی).', 'salamhub' ),
		'linked'  => __( 'این محصولات به سایت متصل‌اند و از این به بعد همگام می‌شوند.', 'salamhub' ),
	);
	?>
	<p class="slh-card__meta slh-section-gap"><?php echo esc_html( $slh_hints[ $slh_tab ] ); ?></p>

	<?php if ( in_array( $slh_tab, array( 'certain', 'suspect' ), true ) && $slh_rows['items'] ) : ?>
		<div class="slh-bulkbar slh-linkbar">
			<label class="slh-checkbox"><input type="checkbox" data-slh-link-push> <?php esc_html_e( 'بعد از اتصال، اطلاعات سایت (قیمت، موجودی و…) به باسلام فرستاده شود', 'salamhub' ); ?></label>
			<span class="slh-toolbar__spacer"></span>
			<?php if ( 'certain' === $slh_tab ) : ?>
				<?php /* translators: %s: count */ ?>
				<button type="button" class="slh-btn slh-btn--primary" data-slh-link-all><?php echo esc_html( sprintf( __( 'تأیید و اتصال همه‌ی قطعی‌ها (%s)', 'salamhub' ), slh_fa_number( $slh_counts['certain'] ) ) ); ?></button>
				<button type="button" class="slh-btn" data-slh-link-selected><?php esc_html_e( 'اتصال انتخاب‌شده‌ها', 'salamhub' ); ?></button>
			<?php else : ?>
				<button type="button" class="slh-btn slh-btn--primary" data-slh-link-selected><?php esc_html_e( 'اتصال انتخاب‌شده‌ها', 'salamhub' ); ?></button>
			<?php endif; ?>
		</div>
		<p class="slh-field__hint" data-slh-link-result aria-live="polite"></p>
	<?php endif; ?>

	<?php if ( ! $slh_rows['items'] ) : ?>
		<div class="slh-card slh-empty"><p><?php esc_html_e( 'موردی در این دسته نیست.', 'salamhub' ); ?></p></div>
	<?php else : ?>
		<div class="slh-table-wrap">
		<table class="slh-table slh-link-table">
			<thead>
				<tr>
					<?php if ( in_array( $slh_tab, array( 'certain', 'suspect' ), true ) ) : ?>
						<th scope="col" class="slh-products__check"><input type="checkbox" data-slh-link-check-all aria-label="<?php esc_attr_e( 'انتخاب همه', 'salamhub' ); ?>"></th>
					<?php endif; ?>
					<th scope="col"><?php esc_html_e( 'در باسلام', 'salamhub' ); ?></th>
					<th scope="col" aria-hidden="true"></th>
					<th scope="col"><?php esc_html_e( 'در سایت', 'salamhub' ); ?></th>
					<th scope="col"><?php esc_html_e( 'چرا', 'salamhub' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $slh_rows['items'] as $slh_row ) : ?>
				<?php
				$slh_vars  = $slh_row->variants ? (array) json_decode( $slh_row->variants, true ) : array();
				$slh_cands = $slh_row->candidates ? (array) json_decode( $slh_row->candidates, true ) : array();
				$slh_price = null !== $slh_row->price && $slh_mult ? slh_fa_number( (int) $slh_row->price / 10 ) . ' ' . __( 'تومان', 'salamhub' ) : '';
				?>
				<tr data-basalam-id="<?php echo esc_attr( $slh_row->basalam_id ); ?>">
					<?php if ( in_array( $slh_tab, array( 'certain', 'suspect' ), true ) ) : ?>
						<td class="slh-products__check"><input type="checkbox" data-slh-link-check <?php checked( 'certain' === $slh_tab ); ?> aria-label="<?php echo esc_attr( $slh_row->title ); ?>"></td>
					<?php endif; ?>
					<td>
						<div class="slh-products__item">
							<span class="slh-products__thumb">
								<?php if ( $slh_row->photo ) : ?>
									<img src="<?php echo esc_url( $slh_row->photo ); ?>" alt="" loading="lazy" width="40" height="40">
								<?php else : ?>
									<span class="dashicons dashicons-format-image" aria-hidden="true"></span>
								<?php endif; ?>
							</span>
							<span>
								<strong><?php echo esc_html( $slh_row->title ); ?></strong>
								<span class="slh-table__why">
									<?php
									$slh_meta = array( '#' . $slh_row->basalam_id );
									if ( $slh_row->sku ) {
										$slh_meta[] = 'SKU: ' . $slh_row->sku;
									}
									if ( $slh_price ) {
										$slh_meta[] = $slh_price;
									}
									if ( null !== $slh_row->stock ) {
										/* translators: %s: stock */
										$slh_meta[] = sprintf( __( 'موجودی %s', 'salamhub' ), slh_fa_number( $slh_row->stock ) );
									}
									if ( $slh_vars ) {
										/* translators: %s: count */
										$slh_meta[] = sprintf( __( '%s تنوع', 'salamhub' ), slh_fa_number( count( $slh_vars ) ) );
									}
									echo esc_html( implode( ' · ', $slh_meta ) );
									?>
								</span>
							</span>
						</div>
					</td>
					<td class="slh-link-table__arrow" aria-hidden="true"><span class="dashicons dashicons-leftright"></span></td>
					<td>
						<?php if ( 'suspect' === $slh_tab && count( $slh_cands ) > 1 ) : ?>
							<select class="slh-field__select" data-slh-link-target aria-label="<?php esc_attr_e( 'محصول سایت', 'salamhub' ); ?>">
								<?php foreach ( $slh_cands as $slh_cid ) : ?>
									<?php $slh_cp = wc_get_product( $slh_cid ); ?>
									<?php if ( $slh_cp ) : ?>
										<option value="<?php echo esc_attr( $slh_cid ); ?>" <?php selected( (int) $slh_row->match_wc_id, (int) $slh_cid ); ?>><?php echo esc_html( $slh_cp->get_name() . ( $slh_cp->get_sku() ? ' · ' . $slh_cp->get_sku() : '' ) ); ?></option>
									<?php endif; ?>
								<?php endforeach; ?>
							</select>
						<?php else : ?>
							<input type="hidden" data-slh-link-target value="<?php echo esc_attr( (int) $slh_row->match_wc_id ); ?>">
							<?php echo $slh_wc_cell( (int) $slh_row->match_wc_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the closure. ?>
						<?php endif; ?>
					</td>
					<td class="slh-table__why">
						<?php
						echo esc_html( (string) $slh_row->match_reason );
						if ( 'suspect' === $slh_tab && $slh_row->match_score ) {
							echo '<span class="slh-score" style="--score:' . esc_attr( (int) $slh_row->match_score ) . '%"><span></span></span>';
						}
						?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<?php if ( $slh_pages > 1 ) : ?>
			<nav class="slh-pagination" aria-label="<?php esc_attr_e( 'صفحه‌بندی', 'salamhub' ); ?>">
				<?php echo wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $slh_paged, 'total' => $slh_pages, 'prev_text' => __( 'قبلی', 'salamhub' ), 'next_text' => __( 'بعدی', 'salamhub' ) ) ) ); ?>
			</nav>
		<?php endif; ?>
	<?php endif; ?>
<?php endif; ?>
