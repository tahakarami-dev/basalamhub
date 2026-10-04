<?php
/**
 * Products page: every WooCommerce product with its Basalam status.
 *
 * @package SalamHub
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
$slh_status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
$slh_search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
$slh_paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
// phpcs:enable
$slh_tabs   = SLH_App::product_tabs();
$slh_status = isset( $slh_tabs[ $slh_status ] ) ? $slh_status : '';
$slh_result = SLH_App::products( array( 'status' => $slh_status, 'search' => $slh_search, 'page' => $slh_paged, 'per_page' => 20 ) );
$slh_pages  = (int) ceil( $slh_result['total'] / 20 );
$slh_conn   = SLH_Settings::connection();
$slh_base   = admin_url( 'admin.php?page=salamhub-products' );
?>
<header class="slh-page-head slh-page-head--row">
	<div>
		<h1 class="slh-page-title"><?php esc_html_e( 'محصولات', 'salamhub' ); ?></h1>
		<p class="slh-card__meta"><?php esc_html_e( 'وضعیت هر محصول ووکامرس در باسلام. برای ویرایش خود محصول، روی نامش بزن.', 'salamhub' ); ?></p>
	</div>
	<a class="slh-btn" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=product' ) ); ?>"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span><?php esc_html_e( 'محصول جدید', 'salamhub' ); ?></a>
</header>

<nav class="slh-tabs" aria-label="<?php esc_attr_e( 'فیلتر وضعیت', 'salamhub' ); ?>">
	<?php foreach ( $slh_tabs as $slh_key => $slh_label ) : ?>
		<a class="slh-tabs__item<?php echo $slh_key === $slh_status ? ' is-active' : ''; ?>" data-slh-nav
			href="<?php echo esc_url( add_query_arg( array_filter( array( 'status' => $slh_key, 's' => $slh_search ) ), $slh_base ) ); ?>"
			<?php echo $slh_key === $slh_status ? 'aria-current="page"' : ''; ?>>
			<?php echo esc_html( $slh_label ); ?>
			<span class="slh-tabs__count"><?php echo esc_html( slh_fa_number( $slh_result['counts'][ $slh_key ] ) ); ?></span>
		</a>
	<?php endforeach; ?>
</nav>

<?php if ( $slh_search ) : ?>
	<p class="slh-card__meta slh-section-gap">
		<?php /* translators: %s: search */ ?>
		<?php echo esc_html( sprintf( __( 'نتایج جستجوی «%s»', 'salamhub' ), $slh_search ) ); ?>
		· <a href="<?php echo esc_url( add_query_arg( array_filter( array( 'status' => $slh_status ) ), $slh_base ) ); ?>" data-slh-nav><?php esc_html_e( 'پاک کردن جستجو', 'salamhub' ); ?></a>
	</p>
<?php endif; ?>

<div class="slh-bulkbar" data-slh-bulkbar hidden>
	<span data-slh-selected-count></span>
	<button type="button" class="slh-btn slh-btn--primary" data-slh-send-selected><?php esc_html_e( 'ارسال انتخاب‌شده‌ها به باسلام', 'salamhub' ); ?></button>
	<span class="slh-field__hint" data-slh-message aria-live="polite"></span>
</div>

<?php if ( ! $slh_result['items'] ) : ?>
	<div class="slh-card slh-empty">
		<span class="dashicons dashicons-products" aria-hidden="true"></span>
		<p><?php esc_html_e( 'محصولی با این فیلتر نیست.', 'salamhub' ); ?></p>
	</div>
<?php else : ?>
	<div class="slh-table-wrap">
	<table class="slh-table slh-products">
		<thead>
			<tr>
				<th scope="col" class="slh-products__check"><input type="checkbox" data-slh-check-all aria-label="<?php esc_attr_e( 'انتخاب همه', 'salamhub' ); ?>"></th>
				<th scope="col"><?php esc_html_e( 'محصول', 'salamhub' ); ?></th>
				<th scope="col"><?php esc_html_e( 'قیمت', 'salamhub' ); ?></th>
				<th scope="col"><?php esc_html_e( 'موجودی', 'salamhub' ); ?></th>
				<th scope="col"><?php esc_html_e( 'باسلام', 'salamhub' ); ?></th>
				<th scope="col"><?php esc_html_e( 'آخرین همگام‌سازی', 'salamhub' ); ?></th>
				<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'اقدام', 'salamhub' ); ?></span></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $slh_result['items'] as $slh_row ) : ?>
			<?php
			$slh_p = wc_get_product( $slh_row->ID );
			if ( ! $slh_p ) {
				continue;
			}
			$slh_sendable = $slh_p->is_type( 'simple' ) && 'publish' === $slh_p->get_status();
			?>
			<tr data-product-id="<?php echo esc_attr( $slh_row->ID ); ?>">
				<td class="slh-products__check">
					<?php if ( $slh_sendable ) : ?>
						<?php /* translators: %s: product */ ?>
						<input type="checkbox" value="<?php echo esc_attr( $slh_row->ID ); ?>" data-slh-check aria-label="<?php echo esc_attr( sprintf( __( 'انتخاب %s', 'salamhub' ), $slh_p->get_name() ) ); ?>">
					<?php endif; ?>
				</td>
				<td>
					<div class="slh-products__item">
						<span class="slh-products__thumb"><?php echo $slh_p->get_image_id() ? wp_get_attachment_image( $slh_p->get_image_id(), array( 40, 40 ) ) : '<span class="dashicons dashicons-format-image" aria-hidden="true"></span>'; ?></span>
						<span>
							<a href="<?php echo esc_url( get_edit_post_link( $slh_row->ID ) ); ?>"><?php echo esc_html( $slh_p->get_name() ); ?></a>
							<span class="slh-table__why">
								<?php
								$slh_meta = array();
								if ( $slh_p->get_sku() ) {
									$slh_meta[] = 'SKU: ' . $slh_p->get_sku();
								}
								if ( ! $slh_p->is_type( 'simple' ) ) {
									$slh_meta[] = $slh_p->is_type( 'variable' ) ? __( 'متغیر (نسخه‌ی بعد)', 'salamhub' ) : __( 'نوع پشتیبانی‌نشده', 'salamhub' );
								}
								if ( 'publish' !== $slh_p->get_status() ) {
									$slh_meta[] = __( 'منتشرنشده', 'salamhub' );
								}
								echo esc_html( implode( ' · ', $slh_meta ) );
								?>
							</span>
						</span>
					</div>
				</td>
				<td class="slh-products__num"><?php echo '' !== $slh_p->get_price() ? wp_kses_post( wc_price( $slh_p->get_price() ) ) : '—'; ?></td>
				<td class="slh-products__num">
					<?php
					if ( $slh_p->managing_stock() ) {
						echo esc_html( slh_fa_number( (int) $slh_p->get_stock_quantity() ) );
					} else {
						echo 'outofstock' === $slh_p->get_stock_status() ? esc_html__( 'ناموجود', 'salamhub' ) : esc_html__( 'موجود', 'salamhub' );
					}
					?>
				</td>
				<td data-slh-row-status>
					<?php
					if ( $slh_row->sync_status ) {
						echo slh_badge( $slh_row->sync_status, 'error' === $slh_row->sync_status ? admin_url( 'admin.php?page=salamhub-logs&object_id=' . (int) $slh_row->ID ) : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						if ( $slh_row->basalam_id ) {
							$slh_id = (int) $slh_row->basalam_id;
							echo '<span class="slh-table__why">';
							if ( $slh_conn['vendor_identifier'] ) {
								echo '<a href="' . esc_url( 'https://basalam.com/' . rawurlencode( $slh_conn['vendor_identifier'] ) . '/product/' . $slh_id ) . '" target="_blank" rel="noopener">#' . esc_html( $slh_id ) . '</a>';
							} else {
								echo '#' . esc_html( $slh_id );
							}
							echo '</span>';
						}
					} else {
						echo '<span class="slh-muted">' . esc_html__( 'ارسال نشده', 'salamhub' ) . '</span>';
					}
					?>
				</td>
				<td class="slh-table__time"><?php echo esc_html( $slh_row->last_synced_at ? slh_time_ago( $slh_row->last_synced_at ) : '—' ); ?></td>
				<td class="slh-products__actions">
					<?php if ( $slh_sendable ) : ?>
						<button type="button" class="slh-btn slh-btn--ghost" data-slh-row-send="<?php echo esc_attr( $slh_row->ID ); ?>">
							<?php echo $slh_row->basalam_id ? esc_html__( 'به‌روزرسانی', 'salamhub' ) : esc_html__( 'ارسال', 'salamhub' ); ?>
						</button>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	</div>
	<?php if ( $slh_pages > 1 ) : ?>
		<nav class="slh-pagination" aria-label="<?php esc_attr_e( 'صفحه‌بندی', 'salamhub' ); ?>">
			<?php
			echo wp_kses_post(
				paginate_links(
					array(
						'base'      => add_query_arg( 'paged', '%#%' ),
						'format'    => '',
						'current'   => $slh_paged,
						'total'     => $slh_pages,
						'prev_text' => __( 'قبلی', 'salamhub' ),
						'next_text' => __( 'بعدی', 'salamhub' ),
					)
				)
			);
			?>
		</nav>
	<?php endif; ?>
<?php endif; ?>
