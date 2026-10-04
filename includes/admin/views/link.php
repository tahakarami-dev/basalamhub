<?php
/**
 * Link existing booth products: preview with «قطعی / مشکوک / بدون جفت», approve to link.
 *
 * @package BasalamHub
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
$bsh_tab   = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'certain';
$bsh_paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
// phpcs:enable
$bsh_tabs = array(
	'certain' => __( 'قطعی', 'basalamhub' ),
	'suspect' => __( 'مشکوک', 'basalamhub' ),
	'none'    => __( 'بدون جفت', 'basalamhub' ),
	'linked'  => __( 'متصل‌شده', 'basalamhub' ),
);
$bsh_tab     = isset( $bsh_tabs[ $bsh_tab ] ) ? $bsh_tab : 'certain';
$bsh_state   = BSH_Linker::state();
$bsh_running = BSH_Linker::is_running();
$bsh_counts  = BSH_Linker::counts();
$bsh_rows    = BSH_Linker::rows( $bsh_tab, $bsh_paged, 50 );
$bsh_pages   = (int) ceil( $bsh_rows['total'] / 50 );
$bsh_mult    = BSH_Product_Mapper::rial_multiplier();
$bsh_base    = admin_url( 'admin.php?page=basalamhub-link' );
$bsh_any     = array_sum( $bsh_counts ) > 0;

/** A WooCommerce product summary (title, SKU, price) for the right column. */
$bsh_wc_cell = function ( $wc_id ) {
	$p = $wc_id ? wc_get_product( $wc_id ) : null;
	if ( ! $p ) {
		return '<span class="bsh-muted">—</span>';
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
		$meta[] = sprintf( __( '%s تنوع', 'basalamhub' ), bsh_fa_number( count( $p->get_children() ) ) );
	}
	return '<a href="' . esc_url( get_edit_post_link( $p->get_id() ) ) . '">' . esc_html( $p->get_name() ) . '</a><span class="bsh-table__why">' . esc_html( implode( ' · ', $meta ) ) . '</span>';
};
?>
<header class="bsh-page-head bsh-page-head--row">
	<div>
		<h1 class="bsh-page-title"><?php esc_html_e( 'اتصال محصولات غرفه', 'basalamhub' ); ?></h1>
		<p class="bsh-card__meta"><?php esc_html_e( 'محصولاتی که از قبل در غرفه‌ی باسلام داری را به محصولات سایت وصل کن تا دوباره ساخته نشوند. اول پیش‌نمایش می‌بینی؛ تا تأیید نکنی چیزی متصل نمی‌شود.', 'basalamhub' ); ?></p>
	</div>
	<button type="button" class="bsh-btn<?php echo $bsh_any ? '' : ' bsh-btn--primary'; ?>" data-bsh-link-start <?php disabled( $bsh_running || ! BSH_Settings::is_connected() ); ?>>
		<span class="dashicons dashicons-update" aria-hidden="true"></span>
		<?php echo $bsh_any ? esc_html__( 'دریافت و تطبیق دوباره', 'basalamhub' ) : esc_html__( 'دریافت محصولات غرفه و تطبیق', 'basalamhub' ); ?>
	</button>
</header>

<section class="bsh-card bsh-section" data-bsh-link-progress data-running="<?php echo $bsh_running ? '1' : '0'; ?>" <?php echo $bsh_running ? '' : 'hidden'; ?>>
	<div data-bsh-link-html><?php BSH_Admin_Tools::link_progress_html( $bsh_state ); ?></div>
	<p class="bsh-field__hint" data-bsh-link-message aria-live="polite"></p>
</section>

<?php if ( 'failed' === $bsh_state['status'] ) : ?>
	<p class="bsh-alert bsh-alert--error"><?php echo esc_html( $bsh_state['error'] ); ?></p>
<?php endif; ?>

<?php if ( ! $bsh_any && ! $bsh_running ) : ?>
	<div class="bsh-card bsh-empty">
		<span class="dashicons dashicons-admin-links" aria-hidden="true"></span>
		<p>
			<?php
			if ( 'ready' === $bsh_state['status'] ) {
				esc_html_e( 'غرفه‌ی باسلام هنوز محصولی ندارد؛ چیزی برای اتصال نیست. محصولات سایت را از «ارسال گروهی» بفرست.', 'basalamhub' );
			} else {
				esc_html_e( 'هنوز محصولات غرفه دریافت نشده. دکمه‌ی بالا را بزن؛ تطبیق با SKU و بعد با نام انجام می‌شود و نتیجه در سه دسته‌ی قطعی، مشکوک و بدون جفت نمایش داده می‌شود.', 'basalamhub' );
			}
			?>
		</p>
	</div>
<?php elseif ( $bsh_any ) : ?>
	<?php if ( 'ready' === $bsh_state['status'] && $bsh_state['finished_at'] ) : ?>
		<?php /* translators: %s: time */ ?>
		<p class="bsh-card__meta bsh-section-gap"><?php echo esc_html( sprintf( __( 'آخرین تطبیق: %s', 'basalamhub' ), bsh_time_ago( $bsh_state['finished_at'] ) ) ); ?></p>
	<?php endif; ?>

	<nav class="bsh-tabs" aria-label="<?php esc_attr_e( 'نتیجه‌ی تطبیق', 'basalamhub' ); ?>">
		<?php foreach ( $bsh_tabs as $bsh_key => $bsh_label ) : ?>
			<a class="bsh-tabs__item<?php echo $bsh_key === $bsh_tab ? ' is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'tab', $bsh_key, $bsh_base ) ); ?>" <?php echo $bsh_key === $bsh_tab ? 'aria-current="page"' : ''; ?>>
				<?php echo esc_html( $bsh_label ); ?>
				<span class="bsh-tabs__count"><?php echo esc_html( bsh_fa_number( $bsh_counts[ $bsh_key ] ) ); ?></span>
			</a>
		<?php endforeach; ?>
	</nav>

	<?php
	$bsh_hints = array(
		'certain' => __( 'SKU این محصولات در باسلام و سایت یکی است. با اطمینان می‌توانی همه را تأیید کنی.', 'basalamhub' ),
		'suspect' => __( 'جفت پیشنهادی بر اساس نام است یا تعارض دارد. هر ردیف را نگاه کن؛ اگر جفت درست نیست، از فهرست گزینه‌ی دیگری انتخاب کن.', 'basalamhub' ),
		'none'    => __( 'برای این محصولات جفتی در سایت پیدا نشد. می‌توانی بعداً آن‌ها را از باسلام به سایت ایمپورت کنی (نسخه‌ی بعدی).', 'basalamhub' ),
		'linked'  => __( 'این محصولات به سایت متصل‌اند و از این به بعد همگام می‌شوند.', 'basalamhub' ),
	);
	?>
	<p class="bsh-card__meta bsh-section-gap"><?php echo esc_html( $bsh_hints[ $bsh_tab ] ); ?></p>

	<?php if ( in_array( $bsh_tab, array( 'certain', 'suspect' ), true ) && $bsh_rows['items'] ) : ?>
		<div class="bsh-bulkbar bsh-linkbar">
			<label class="bsh-checkbox"><input type="checkbox" data-bsh-link-push> <?php esc_html_e( 'بعد از اتصال، اطلاعات سایت (قیمت، موجودی و…) به باسلام فرستاده شود', 'basalamhub' ); ?></label>
			<span class="bsh-toolbar__spacer"></span>
			<?php if ( 'certain' === $bsh_tab ) : ?>
				<?php /* translators: %s: count */ ?>
				<button type="button" class="bsh-btn bsh-btn--primary" data-bsh-link-all><?php echo esc_html( sprintf( __( 'تأیید و اتصال همه‌ی قطعی‌ها (%s)', 'basalamhub' ), bsh_fa_number( $bsh_counts['certain'] ) ) ); ?></button>
				<button type="button" class="bsh-btn" data-bsh-link-selected><?php esc_html_e( 'اتصال انتخاب‌شده‌ها', 'basalamhub' ); ?></button>
			<?php else : ?>
				<button type="button" class="bsh-btn bsh-btn--primary" data-bsh-link-selected><?php esc_html_e( 'اتصال انتخاب‌شده‌ها', 'basalamhub' ); ?></button>
			<?php endif; ?>
		</div>
		<p class="bsh-field__hint" data-bsh-link-result aria-live="polite"></p>
	<?php endif; ?>

	<?php if ( ! $bsh_rows['items'] ) : ?>
		<div class="bsh-card bsh-empty"><p><?php esc_html_e( 'موردی در این دسته نیست.', 'basalamhub' ); ?></p></div>
	<?php else : ?>
		<div class="bsh-table-wrap">
		<table class="bsh-table bsh-link-table">
			<thead>
				<tr>
					<?php if ( in_array( $bsh_tab, array( 'certain', 'suspect' ), true ) ) : ?>
						<th scope="col" class="bsh-products__check"><input type="checkbox" data-bsh-link-check-all aria-label="<?php esc_attr_e( 'انتخاب همه', 'basalamhub' ); ?>"></th>
					<?php endif; ?>
					<th scope="col"><?php esc_html_e( 'در باسلام', 'basalamhub' ); ?></th>
					<th scope="col" aria-hidden="true"></th>
					<th scope="col"><?php esc_html_e( 'در سایت', 'basalamhub' ); ?></th>
					<th scope="col"><?php esc_html_e( 'چرا', 'basalamhub' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $bsh_rows['items'] as $bsh_row ) : ?>
				<?php
				$bsh_vars  = $bsh_row->variants ? (array) json_decode( $bsh_row->variants, true ) : array();
				$bsh_cands = $bsh_row->candidates ? (array) json_decode( $bsh_row->candidates, true ) : array();
				$bsh_price = null !== $bsh_row->price && $bsh_mult ? bsh_fa_number( (int) $bsh_row->price / 10 ) . ' ' . __( 'تومان', 'basalamhub' ) : '';
				?>
				<tr data-basalam-id="<?php echo esc_attr( $bsh_row->basalam_id ); ?>">
					<?php if ( in_array( $bsh_tab, array( 'certain', 'suspect' ), true ) ) : ?>
						<td class="bsh-products__check"><input type="checkbox" data-bsh-link-check <?php checked( 'certain' === $bsh_tab ); ?> aria-label="<?php echo esc_attr( $bsh_row->title ); ?>"></td>
					<?php endif; ?>
					<td>
						<div class="bsh-products__item">
							<span class="bsh-products__thumb">
								<?php if ( $bsh_row->photo ) : ?>
									<img src="<?php echo esc_url( $bsh_row->photo ); ?>" alt="" loading="lazy" width="40" height="40">
								<?php else : ?>
									<span class="dashicons dashicons-format-image" aria-hidden="true"></span>
								<?php endif; ?>
							</span>
							<span>
								<strong><?php echo esc_html( $bsh_row->title ); ?></strong>
								<span class="bsh-table__why">
									<?php
									$bsh_meta = array( '#' . $bsh_row->basalam_id );
									if ( $bsh_row->sku ) {
										$bsh_meta[] = 'SKU: ' . $bsh_row->sku;
									}
									if ( $bsh_price ) {
										$bsh_meta[] = $bsh_price;
									}
									if ( null !== $bsh_row->stock ) {
										/* translators: %s: stock */
										$bsh_meta[] = sprintf( __( 'موجودی %s', 'basalamhub' ), bsh_fa_number( $bsh_row->stock ) );
									}
									if ( $bsh_vars ) {
										/* translators: %s: count */
										$bsh_meta[] = sprintf( __( '%s تنوع', 'basalamhub' ), bsh_fa_number( count( $bsh_vars ) ) );
									}
									echo esc_html( implode( ' · ', $bsh_meta ) );
									?>
								</span>
							</span>
						</div>
					</td>
					<td class="bsh-link-table__arrow" aria-hidden="true"><span class="dashicons dashicons-leftright"></span></td>
					<td>
						<?php if ( 'suspect' === $bsh_tab && count( $bsh_cands ) > 1 ) : ?>
							<select class="bsh-field__select" data-bsh-link-target aria-label="<?php esc_attr_e( 'محصول سایت', 'basalamhub' ); ?>">
								<?php foreach ( $bsh_cands as $bsh_cid ) : ?>
									<?php $bsh_cp = wc_get_product( $bsh_cid ); ?>
									<?php if ( $bsh_cp ) : ?>
										<option value="<?php echo esc_attr( $bsh_cid ); ?>" <?php selected( (int) $bsh_row->match_wc_id, (int) $bsh_cid ); ?>><?php echo esc_html( $bsh_cp->get_name() . ( $bsh_cp->get_sku() ? ' · ' . $bsh_cp->get_sku() : '' ) ); ?></option>
									<?php endif; ?>
								<?php endforeach; ?>
							</select>
						<?php else : ?>
							<input type="hidden" data-bsh-link-target value="<?php echo esc_attr( (int) $bsh_row->match_wc_id ); ?>">
							<?php echo $bsh_wc_cell( (int) $bsh_row->match_wc_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the closure. ?>
						<?php endif; ?>
					</td>
					<td class="bsh-table__why">
						<?php
						echo esc_html( (string) $bsh_row->match_reason );
						if ( 'suspect' === $bsh_tab && $bsh_row->match_score ) {
							echo '<span class="bsh-score" style="--score:' . esc_attr( (int) $bsh_row->match_score ) . '%"><span></span></span>';
						}
						?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<?php if ( $bsh_pages > 1 ) : ?>
			<nav class="bsh-pagination" aria-label="<?php esc_attr_e( 'صفحه‌بندی', 'basalamhub' ); ?>">
				<?php echo wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $bsh_paged, 'total' => $bsh_pages, 'prev_text' => __( 'قبلی', 'basalamhub' ), 'next_text' => __( 'بعدی', 'basalamhub' ) ) ) ); ?>
			</nav>
		<?php endif; ?>
	<?php endif; ?>
<?php endif; ?>
