<?php
/**
 * Basalam orders: health of the order sync, recent imported orders, failed imports.
 *
 * @package BasalamHub
 */

defined( 'ABSPATH' ) || exit;

global $wpdb;
$bsh_enabled   = BSH_Order_Sync::enabled();
$bsh_polled    = get_option( 'bsh_orders_polled_at' );
$bsh_poll_err  = get_option( 'bsh_orders_poll_error' );
$bsh_missing   = BSH_Order_Sync::missing_count();
$bsh_imported  = BSH_Order_Sync::imported_count();
$bsh_reference = BSH_Inventory::basalam_is_reference();
$bsh_pulled    = get_option( 'bsh_stock_pulled_at' );
$bsh_rows      = $wpdb->get_results( 'SELECT wc_id, basalam_id, sync_status, last_error, last_synced_at FROM ' . BSH_Links::table() . " WHERE object_type = 'order' AND wc_id > 0 ORDER BY id DESC LIMIT 30" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$bsh_failed    = BSH_Logger::query(
	array(
		'object_type' => 'parcel',
		'level'       => 'error',
		'unresolved'  => 1,
		'per_page'    => 20,
	)
);
?>
<header class="bsh-page-head">
	<div>
		<h1 class="bsh-page-title"><?php esc_html_e( 'سفارش‌های باسلام', 'basalamhub' ); ?></h1>
		<p class="bsh-card__meta"><?php esc_html_e( 'هر سفارش باسلام یک بار و فقط یک بار در ووکامرس ثبت می‌شود؛ با برچسب و شماره‌ی باسلام، و موجودی سایت هم کم می‌شود.', 'basalamhub' ); ?></p>
	</div>
	<?php if ( $bsh_enabled ) : ?>
		<button type="button" class="bsh-btn bsh-btn--primary" data-bsh-orders-poll>
			<span class="dashicons dashicons-update" aria-hidden="true"></span>
			<?php esc_html_e( 'دریافت سفارش‌ها الان', 'basalamhub' ); ?>
		</button>
	<?php endif; ?>
</header>

<p class="bsh-field__hint" data-bsh-message aria-live="polite"></p>

<?php if ( ! BSH_Settings::is_connected() ) : ?>
	<p class="bsh-alert bsh-alert--warning"><?php esc_html_e( 'اول از تنظیمات به باسلام وصل شو. توکن باید دسترسی «سفارش‌های غرفه» هم داشته باشد.', 'basalamhub' ); ?></p>
<?php elseif ( ! $bsh_enabled ) : ?>
	<p class="bsh-alert bsh-alert--warning">
		<?php esc_html_e( 'دریافت سفارش‌ها خاموش است.', 'basalamhub' ); ?>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=basalamhub-settings#bsh-orders-settings' ) ); ?>" data-bsh-nav><?php esc_html_e( 'روشن کردن در تنظیمات', 'basalamhub' ); ?></a>
	</p>
<?php elseif ( $bsh_poll_err ) : ?>
	<p class="bsh-alert bsh-alert--error">
		<?php
		/* translators: %s: error */
		echo esc_html( sprintf( __( 'آخرین دریافت سفارش‌ها ناموفق بود: %s خودکار دوباره تلاش می‌شود؛ جزئیات در لاگ.', 'basalamhub' ), $bsh_poll_err ) );
		?>
	</p>
<?php endif; ?>

<section class="bsh-tiles bsh-section" aria-label="<?php esc_attr_e( 'خلاصه', 'basalamhub' ); ?>">
	<div class="bsh-tile">
		<span class="bsh-tile__label"><?php esc_html_e( 'سفارش باسلام در سایت', 'basalamhub' ); ?></span>
		<span class="bsh-tile__value"><?php echo esc_html( bsh_fa_number( $bsh_imported ) ); ?></span>
		<span class="bsh-tile__meta"><?php esc_html_e( 'با برچسب «باسلام» در سفارش‌های ووکامرس', 'basalamhub' ); ?></span>
	</div>
	<div class="bsh-tile<?php echo $bsh_missing ? ' bsh-tile--alert' : ''; ?>">
		<span class="bsh-tile__label"><?php esc_html_e( 'سفارش جاافتاده', 'basalamhub' ); ?></span>
		<span class="bsh-tile__value"><?php echo esc_html( bsh_fa_number( $bsh_missing ) ); ?></span>
		<span class="bsh-tile__meta"><?php echo $bsh_missing ? esc_html__( 'پایین همین صفحه، با دلیل و تلاش مجدد', 'basalamhub' ) : esc_html__( 'هیچ سفارشی جا نیفتاده', 'basalamhub' ); ?></span>
	</div>
	<div class="bsh-tile">
		<span class="bsh-tile__label"><?php esc_html_e( 'آخرین بررسی', 'basalamhub' ); ?></span>
		<span class="bsh-tile__value bsh-tile__value--sm"><?php echo esc_html( $bsh_polled ? bsh_time_ago( $bsh_polled ) : __( 'هنوز نه', 'basalamhub' ) ); ?></span>
		<?php /* translators: %s: minutes */ ?>
		<span class="bsh-tile__meta"><?php echo esc_html( sprintf( __( 'هر %s دقیقه، خودکار', 'basalamhub' ), bsh_fa_number( BSH_Settings::get( 'orders_interval', 5 ) ) ) ); ?></span>
	</div>
	<div class="bsh-tile">
		<span class="bsh-tile__label"><?php esc_html_e( 'مرجع موجودی', 'basalamhub' ); ?></span>
		<span class="bsh-tile__value bsh-tile__value--sm"><?php echo $bsh_reference ? esc_html__( 'باسلام', 'basalamhub' ) : esc_html__( 'سایت', 'basalamhub' ); ?></span>
		<?php /* translators: %s: safety stock */ ?>
		<span class="bsh-tile__meta"><?php echo esc_html( sprintf( __( 'موجودی اطمینان: %s', 'basalamhub' ), bsh_fa_number( BSH_Settings::get( 'safety_stock', 0 ) ) ) ); ?></span>
	</div>
</section>

<?php if ( $bsh_failed['items'] ) : ?>
	<section class="bsh-card bsh-section">
		<div class="bsh-card__head"><h2 class="bsh-card__title"><?php esc_html_e( 'سفارش‌هایی که ثبت نشدند', 'basalamhub' ); ?></h2></div>
		<div class="bsh-table-wrap">
			<table class="bsh-table">
				<thead><tr>
					<th scope="col"><?php esc_html_e( 'سفارش باسلام', 'basalamhub' ); ?></th>
					<th scope="col"><?php esc_html_e( 'چه شد و چه کنم', 'basalamhub' ); ?></th>
					<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'عملیات', 'basalamhub' ); ?></span></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $bsh_failed['items'] as $bsh_log ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $bsh_log->title ); ?></strong><span class="bsh-table__why"><?php echo esc_html( bsh_time_ago( $bsh_log->created_at ) ); ?></span></td>
						<td><?php echo esc_html( $bsh_log->message ); ?><span class="bsh-table__why"><?php echo esc_html( trim( $bsh_log->reason . ' ' . $bsh_log->suggestion ) ); ?></span></td>
						<td><button type="button" class="bsh-btn" data-bsh-retry="<?php echo esc_attr( $bsh_log->id ); ?>"><?php esc_html_e( 'تلاش مجدد', 'basalamhub' ); ?></button></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</section>
<?php endif; ?>

<section class="bsh-card bsh-section">
	<div class="bsh-card__head"><h2 class="bsh-card__title"><?php esc_html_e( 'آخرین سفارش‌ها', 'basalamhub' ); ?></h2></div>
	<?php if ( ! $bsh_rows ) : ?>
		<div class="bsh-empty">
			<span class="dashicons dashicons-cart" aria-hidden="true"></span>
			<p><?php echo $bsh_enabled ? esc_html__( 'هنوز سفارشی از باسلام نیامده. سفارش بعدی خودکار اینجا و در سفارش‌های ووکامرس ظاهر می‌شود.', 'basalamhub' ) : esc_html__( 'با روشن کردن دریافت سفارش‌ها، سفارش‌های باسلام اینجا می‌آیند.', 'basalamhub' ); ?></p>
		</div>
	<?php else : ?>
		<div class="bsh-table-wrap">
			<table class="bsh-table">
				<thead><tr>
					<th scope="col"><?php esc_html_e( 'سفارش ووکامرس', 'basalamhub' ); ?></th>
					<th scope="col"><?php esc_html_e( 'مشتری', 'basalamhub' ); ?></th>
					<th scope="col"><?php esc_html_e( 'مبلغ', 'basalamhub' ); ?></th>
					<th scope="col"><?php esc_html_e( 'وضعیت در باسلام', 'basalamhub' ); ?></th>
					<th scope="col"><?php esc_html_e( 'وضعیت در سایت', 'basalamhub' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $bsh_rows as $bsh_row ) : ?>
					<?php
					$bsh_order = wc_get_order( (int) $bsh_row->wc_id );
					if ( ! $bsh_order ) {
						continue;
					}
					?>
					<tr>
						<td>
							<a href="<?php echo esc_url( $bsh_order->get_edit_order_url() ); ?>"><strong><?php echo esc_html( '#' . $bsh_order->get_order_number() ); ?></strong></a>
							<?php /* translators: %s: parcel id */ ?>
							<span class="bsh-table__why"><?php echo esc_html( sprintf( __( 'باسلام #%s', 'basalamhub' ), $bsh_row->basalam_id ) ); ?> · <?php echo esc_html( $bsh_order->get_date_created() ? bsh_time_ago( $bsh_order->get_date_created()->date( 'Y-m-d H:i:s' ) ) : '' ); ?></span>
						</td>
						<td><?php echo esc_html( trim( $bsh_order->get_formatted_billing_full_name() ) ); ?><span class="bsh-table__why"><?php echo esc_html( $bsh_order->get_billing_city() ); ?></span></td>
						<td><?php echo wp_kses_post( $bsh_order->get_formatted_order_total() ); ?></td>
						<td>
							<?php echo BSH_Order_UI::status_badge( (int) $bsh_order->get_meta( '_bsh_parcel_status' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php if ( 'error' === $bsh_row->sync_status && $bsh_row->last_error ) : ?>
								<span class="bsh-table__why"><?php echo esc_html( $bsh_row->last_error ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( wc_get_order_status_name( $bsh_order->get_status() ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</section>

<?php $bsh_rec = BSH_Reconcile::last(); ?>
<section class="bsh-card bsh-section">
	<div class="bsh-card__head">
		<div>
			<h2 class="bsh-card__title"><?php esc_html_e( 'تطبیق شبانه', 'basalamhub' ); ?></h2>
			<p class="bsh-card__meta"><?php esc_html_e( 'هر شب حدود ساعت ۳، همه‌ی سفارش‌های ۷ روز اخیر باسلام با سفارش‌های سایت مقایسه می‌شوند و هر سفارش جاافتاده ثبت و گزارش می‌شود؛ حتی اگر سایت ساعت‌ها قطع بوده باشد.', 'basalamhub' ); ?></p>
		</div>
		<?php
		if ( ! $bsh_rec ) {
			echo '<span class="bsh-badge bsh-badge--stale">' . esc_html__( 'هنوز اجرا نشده', 'basalamhub' ) . '</span>';
		} elseif ( $bsh_rec['error'] ) {
			echo '<span class="bsh-badge bsh-badge--error">' . esc_html__( 'ناموفق', 'basalamhub' ) . '</span>';
		} elseif ( $bsh_rec['missing'] ) {
			echo '<span class="bsh-badge bsh-badge--stale">' . esc_html__( 'جاافتاده پیدا شد', 'basalamhub' ) . '</span>';
		} else {
			echo '<span class="bsh-badge bsh-badge--synced">' . esc_html__( 'همه‌چیز سر جایش', 'basalamhub' ) . '</span>';
		}
		?>
	</div>
	<?php if ( $bsh_rec ) : ?>
		<p class="bsh-card__meta">
			<?php
			echo esc_html(
				$bsh_rec['error']
					/* translators: 1: relative time, 2: error */
					? sprintf( __( 'آخرین اجرا %1$s: %2$s', 'basalamhub' ), bsh_time_ago( $bsh_rec['at'] ), $bsh_rec['error'] )
					/* translators: 1: relative time, 2: checked, 3: missing */
					: sprintf( __( 'آخرین اجرا %1$s: %2$s سفارش بررسی شد، %3$s سفارش جاافتاده پیدا و در صف ثبت قرار گرفت.', 'basalamhub' ), bsh_time_ago( $bsh_rec['at'] ), bsh_fa_number( $bsh_rec['checked'] ), bsh_fa_number( $bsh_rec['missing'] ) )
			);
			?>
		</p>
	<?php endif; ?>
	<div class="bsh-card__foot">
		<button type="button" class="bsh-btn" data-bsh-reconcile <?php disabled( ! $bsh_enabled ); ?>><?php esc_html_e( 'تطبیق الان', 'basalamhub' ); ?></button>
	</div>
</section>

<section class="bsh-card bsh-section">
	<div class="bsh-card__head"><h2 class="bsh-card__title"><?php esc_html_e( 'سریع‌تر با وب‌هوک (اختیاری)', 'basalamhub' ); ?></h2></div>
	<p class="bsh-card__meta"><?php esc_html_e( 'بدون وب‌هوک هم هیچ سفارشی گم نمی‌شود؛ فقط تا چند دقیقه دیرتر می‌رسد. اگر در پنل توسعه‌دهندگان باسلام وب‌هوک سفارش ساختی، این آدرس را بده. باسلام‌هاب به محتوای وب‌هوک اعتماد نمی‌کند و فقط با آن زودتر سفارش‌ها را از API می‌خواند.', 'basalamhub' ); ?></p>
	<div class="bsh-copy">
		<input class="bsh-field__input" type="text" readonly value="<?php echo esc_attr( BSH_Order_Sync::webhook_url() ); ?>" aria-label="<?php esc_attr_e( 'آدرس وب‌هوک', 'basalamhub' ); ?>" data-bsh-copy-src>
		<button type="button" class="bsh-btn" data-bsh-copy><?php esc_html_e( 'کپی', 'basalamhub' ); ?></button>
	</div>
	<?php $bsh_hook_last = get_option( 'bsh_webhook_last' ); ?>
	<?php /* translators: %s: relative time */ ?>
	<p class="bsh-field__hint"><?php echo esc_html( $bsh_hook_last ? sprintf( __( 'آخرین وب‌هوک: %s', 'basalamhub' ), bsh_time_ago( $bsh_hook_last ) ) : __( 'هنوز وب‌هوکی نرسیده.', 'basalamhub' ) ); ?></p>
</section>

<?php if ( $bsh_reference ) : ?>
	<section class="bsh-card bsh-section">
		<div class="bsh-card__head"><h2 class="bsh-card__title"><?php esc_html_e( 'موجودی از باسلام', 'basalamhub' ); ?></h2></div>
		<?php /* translators: %s: relative time */ ?>
		<p class="bsh-card__meta"><?php echo esc_html( $bsh_pulled ? sprintf( __( 'آخرین یکی‌سازی: %s · هر ساعت خودکار', 'basalamhub' ), bsh_time_ago( $bsh_pulled ) ) : __( 'هنوز انجام نشده · هر ساعت خودکار', 'basalamhub' ) ); ?></p>
		<div class="bsh-card__foot">
			<button type="button" class="bsh-btn" data-bsh-stock-pull><?php esc_html_e( 'یکی‌سازی موجودی الان', 'basalamhub' ); ?></button>
		</div>
	</section>
<?php endif; ?>
