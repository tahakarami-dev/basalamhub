<?php
/**
 * Products page: every WooCommerce product with its Basalam status.
 *
 * @package BasalamHub
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
$bsh_status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
$bsh_search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
$bsh_paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
// phpcs:enable
$bsh_tabs   = BSH_App::product_tabs();
$bsh_status = isset( $bsh_tabs[ $bsh_status ] ) ? $bsh_status : '';
$bsh_result = BSH_App::products( array( 'status' => $bsh_status, 'search' => $bsh_search, 'page' => $bsh_paged, 'per_page' => 20 ) );
$bsh_pages  = (int) ceil( $bsh_result['total'] / 20 );
$bsh_conn   = BSH_Settings::connection();
$bsh_base   = admin_url( 'admin.php?page=basalamhub-products' );
?>
<header class="bsh-page-head bsh-page-head--row">
	<div>
		<h1 class="bsh-page-title"><?php esc_html_e( 'محصولات', 'basalamhub' ); ?></h1>
		<p class="bsh-card__meta"><?php esc_html_e( 'وضعیت هر محصول ووکامرس در باسلام. برای ویرایش خود محصول، روی نامش بزن.', 'basalamhub' ); ?></p>
	</div>
	<a class="bsh-btn" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=product' ) ); ?>"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span><?php esc_html_e( 'محصول جدید', 'basalamhub' ); ?></a>
</header>

<nav class="bsh-tabs" aria-label="<?php esc_attr_e( 'فیلتر وضعیت', 'basalamhub' ); ?>">
	<?php foreach ( $bsh_tabs as $bsh_key => $bsh_label ) : ?>
		<a class="bsh-tabs__item<?php echo $bsh_key === $bsh_status ? ' is-active' : ''; ?>" data-bsh-nav
			href="<?php echo esc_url( add_query_arg( array_filter( array( 'status' => $bsh_key, 's' => $bsh_search ) ), $bsh_base ) ); ?>"
			<?php echo $bsh_key === $bsh_status ? 'aria-current="page"' : ''; ?>>
			<?php echo esc_html( $bsh_label ); ?>
			<span class="bsh-tabs__count"><?php echo esc_html( bsh_fa_number( $bsh_result['counts'][ $bsh_key ] ) ); ?></span>
		</a>
	<?php endforeach; ?>
</nav>

<?php if ( $bsh_search ) : ?>
	<p class="bsh-card__meta bsh-section-gap">
		<?php /* translators: %s: search */ ?>
		<?php echo esc_html( sprintf( __( 'نتایج جستجوی «%s»', 'basalamhub' ), $bsh_search ) ); ?>
		· <a href="<?php echo esc_url( add_query_arg( array_filter( array( 'status' => $bsh_status ) ), $bsh_base ) ); ?>" data-bsh-nav><?php esc_html_e( 'پاک کردن جستجو', 'basalamhub' ); ?></a>
	</p>
<?php endif; ?>

<div class="bsh-bulkbar" data-bsh-bulkbar hidden>
	<span data-bsh-selected-count></span>
	<button type="button" class="bsh-btn bsh-btn--primary" data-bsh-send-selected><?php esc_html_e( 'ارسال انتخاب‌شده‌ها به باسلام', 'basalamhub' ); ?></button>
	<span class="bsh-field__hint" data-bsh-message aria-live="polite"></span>
</div>

<?php if ( ! $bsh_result['items'] ) : ?>
	<div class="bsh-card bsh-empty">
		<span class="dashicons dashicons-products" aria-hidden="true"></span>
		<p><?php esc_html_e( 'محصولی با این فیلتر نیست.', 'basalamhub' ); ?></p>
	</div>
<?php else : ?>
	<div class="bsh-table-wrap">
	<table class="bsh-table bsh-products">
		<thead>
			<tr>
				<th scope="col" class="bsh-products__check"><input type="checkbox" data-bsh-check-all aria-label="<?php esc_attr_e( 'انتخاب همه', 'basalamhub' ); ?>"></th>
				<th scope="col"><?php esc_html_e( 'محصول', 'basalamhub' ); ?></th>
				<th scope="col"><?php esc_html_e( 'قیمت', 'basalamhub' ); ?></th>
				<th scope="col"><?php esc_html_e( 'موجودی', 'basalamhub' ); ?></th>
				<th scope="col"><?php esc_html_e( 'باسلام', 'basalamhub' ); ?></th>
				<th scope="col"><?php esc_html_e( 'آخرین همگام‌سازی', 'basalamhub' ); ?></th>
				<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'اقدام', 'basalamhub' ); ?></span></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $bsh_result['items'] as $bsh_row ) : ?>
			<?php
			$bsh_p = wc_get_product( $bsh_row->ID );
			if ( ! $bsh_p ) {
				continue;
			}
			$bsh_sendable = ( $bsh_p->is_type( 'simple' ) || $bsh_p->is_type( 'variable' ) ) && 'publish' === $bsh_p->get_status();
			?>
			<tr data-product-id="<?php echo esc_attr( $bsh_row->ID ); ?>">
				<td class="bsh-products__check">
					<?php if ( $bsh_sendable ) : ?>
						<?php /* translators: %s: product */ ?>
						<input type="checkbox" value="<?php echo esc_attr( $bsh_row->ID ); ?>" data-bsh-check aria-label="<?php echo esc_attr( sprintf( __( 'انتخاب %s', 'basalamhub' ), $bsh_p->get_name() ) ); ?>">
					<?php endif; ?>
				</td>
				<td>
					<div class="bsh-products__item">
						<span class="bsh-products__thumb"><?php echo $bsh_p->get_image_id() ? wp_get_attachment_image( $bsh_p->get_image_id(), array( 40, 40 ) ) : '<span class="dashicons dashicons-format-image" aria-hidden="true"></span>'; ?></span>
						<span>
							<a href="<?php echo esc_url( get_edit_post_link( $bsh_row->ID ) ); ?>"><?php echo esc_html( $bsh_p->get_name() ); ?></a>
							<span class="bsh-table__why">
								<?php
								$bsh_meta = array();
								if ( $bsh_p->get_sku() ) {
									$bsh_meta[] = 'SKU: ' . $bsh_p->get_sku();
								}
								if ( $bsh_p->is_type( 'variable' ) ) {
									/* translators: %s: variation count */
									$bsh_meta[] = sprintf( __( 'متغیر · %s تنوع', 'basalamhub' ), bsh_fa_number( count( $bsh_p->get_children() ) ) );
								} elseif ( ! $bsh_p->is_type( 'simple' ) ) {
									$bsh_meta[] = __( 'نوع پشتیبانی‌نشده', 'basalamhub' );
								}
								if ( 'publish' !== $bsh_p->get_status() ) {
									$bsh_meta[] = __( 'منتشرنشده', 'basalamhub' );
								}
								echo esc_html( implode( ' · ', $bsh_meta ) );
								?>
							</span>
						</span>
					</div>
				</td>
				<td class="bsh-products__num"><?php echo '' !== $bsh_p->get_price() ? wp_kses_post( wc_price( $bsh_p->get_price() ) ) : '—'; ?></td>
				<td class="bsh-products__num">
					<?php
					if ( $bsh_p->managing_stock() ) {
						echo esc_html( bsh_fa_number( (int) $bsh_p->get_stock_quantity() ) );
					} else {
						echo 'outofstock' === $bsh_p->get_stock_status() ? esc_html__( 'ناموجود', 'basalamhub' ) : esc_html__( 'موجود', 'basalamhub' );
					}
					?>
				</td>
				<td data-bsh-row-status>
					<?php
					if ( $bsh_row->sync_status ) {
						echo bsh_badge( $bsh_row->sync_status, 'error' === $bsh_row->sync_status ? admin_url( 'admin.php?page=basalamhub-logs&object_id=' . (int) $bsh_row->ID ) : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						if ( $bsh_row->basalam_id ) {
							$bsh_id = (int) $bsh_row->basalam_id;
							echo '<span class="bsh-table__why">';
							if ( $bsh_conn['vendor_identifier'] ) {
								echo '<a href="' . esc_url( 'https://basalam.com/' . rawurlencode( $bsh_conn['vendor_identifier'] ) . '/product/' . $bsh_id ) . '" target="_blank" rel="noopener">#' . esc_html( $bsh_id ) . '</a>';
							} else {
								echo '#' . esc_html( $bsh_id );
							}
							echo '</span>';
						}
					} else {
						echo '<span class="bsh-muted">' . esc_html__( 'ارسال نشده', 'basalamhub' ) . '</span>';
					}
					?>
				</td>
				<td class="bsh-table__time"><?php echo esc_html( $bsh_row->last_synced_at ? bsh_time_ago( $bsh_row->last_synced_at ) : '—' ); ?></td>
				<td class="bsh-products__actions">
					<?php if ( $bsh_sendable ) : ?>
						<button type="button" class="bsh-btn bsh-btn--ghost" data-bsh-row-send="<?php echo esc_attr( $bsh_row->ID ); ?>">
							<?php echo $bsh_row->basalam_id ? esc_html__( 'به‌روزرسانی', 'basalamhub' ) : esc_html__( 'ارسال', 'basalamhub' ); ?>
						</button>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	</div>
	<?php if ( $bsh_pages > 1 ) : ?>
		<nav class="bsh-pagination" aria-label="<?php esc_attr_e( 'صفحه‌بندی', 'basalamhub' ); ?>">
			<?php
			echo wp_kses_post(
				paginate_links(
					array(
						'base'      => add_query_arg( 'paged', '%#%' ),
						'format'    => '',
						'current'   => $bsh_paged,
						'total'     => $bsh_pages,
						'prev_text' => __( 'قبلی', 'basalamhub' ),
						'next_text' => __( 'بعدی', 'basalamhub' ),
					)
				)
			);
			?>
		</nav>
	<?php endif; ?>
<?php endif; ?>
