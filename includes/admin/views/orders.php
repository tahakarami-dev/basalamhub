<?php
/**
 * Basalam orders: health of the order sync, recent imported orders, failed imports.
 *
 * @package SalamHub
 */

defined( 'ABSPATH' ) || exit;

global $wpdb;
$slh_enabled   = SLH_Order_Sync::enabled();
$slh_polled    = get_option( 'slh_orders_polled_at' );
$slh_poll_err  = get_option( 'slh_orders_poll_error' );
$slh_missing   = SLH_Order_Sync::missing_count();
$slh_imported  = SLH_Order_Sync::imported_count();
$slh_reference = SLH_Inventory::basalam_is_reference();
$slh_pulled    = get_option( 'slh_stock_pulled_at' );
$slh_rows      = $wpdb->get_results( 'SELECT wc_id, basalam_id, sync_status, last_error, last_synced_at FROM ' . SLH_Links::table() . " WHERE object_type = 'order' AND wc_id > 0 ORDER BY id DESC LIMIT 30" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$slh_failed    = SLH_Logger::query( array( 'object_type' => 'parcel', 'level' => 'error', 'unresolved' => 1, 'per_page' => 20 ) );
?>
<header class="slh-page-head">
	<div>
		<h1 class="slh-page-title"><?php esc_html_e( 'سفارش‌های باسلام', 'salamhub' ); ?></h1>
		<p class="slh-card__meta"><?php esc_html_e( 'هر سفارش باسلام یک بار و فقط یک بار در ووکامرس ثبت می‌شود؛ با برچسب و شماره‌ی باسلام، و موجودی سایت هم کم می‌شود.', 'salamhub' ); ?></p>
	</div>
	<?php if ( $slh_enabled ) : ?>
		<button type="button" class="slh-btn slh-btn--primary" data-slh-orders-poll>
			<span class="dashicons dashicons-update" aria-hidden="true"></span>
			<?php esc_html_e( 'دریافت سفارش‌ها الان', 'salamhub' ); ?>
		</button>
	<?php endif; ?>
</header>

<p class="slh-field__hint" data-slh-message aria-live="polite"></p>

<?php if ( ! SLH_Settings::is_connected() ) : ?>
	<p class="slh-alert slh-alert--warning"><?php esc_html_e( 'اول از تنظیمات به باسلام وصل شو. توکن باید دسترسی «سفارش‌های غرفه» هم داشته باشد.', 'salamhub' ); ?></p>
<?php elseif ( ! $slh_enabled ) : ?>
	<p class="slh-alert slh-alert--warning">
		<?php esc_html_e( 'دریافت سفارش‌ها خاموش است.', 'salamhub' ); ?>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=salamhub-settings#slh-orders-settings' ) ); ?>" data-slh-nav><?php esc_html_e( 'روشن کردن در تنظیمات', 'salamhub' ); ?></a>
	</p>
<?php elseif ( $slh_poll_err ) : ?>
	<p class="slh-alert slh-alert--error">
		<?php
		/* translators: %s: error */
		echo esc_html( sprintf( __( 'آخرین دریافت سفارش‌ها ناموفق بود: %s خودکار دوباره تلاش می‌شود؛ جزئیات در لاگ.', 'salamhub' ), $slh_poll_err ) );
		?>
	</p>
<?php endif; ?>

<section class="slh-tiles slh-section" aria-label="<?php esc_attr_e( 'خلاصه', 'salamhub' ); ?>">
	<div class="slh-tile">
		<span class="slh-tile__label"><?php esc_html_e( 'سفارش باسلام در سایت', 'salamhub' ); ?></span>
		<span class="slh-tile__value"><?php echo esc_html( slh_fa_number( $slh_imported ) ); ?></span>
		<span class="slh-tile__meta"><?php esc_html_e( 'با برچسب «باسلام» در سفارش‌های ووکامرس', 'salamhub' ); ?></span>
	</div>
	<div class="slh-tile<?php echo $slh_missing ? ' slh-tile--alert' : ''; ?>">
		<span class="slh-tile__label"><?php esc_html_e( 'سفارش جاافتاده', 'salamhub' ); ?></span>
		<span class="slh-tile__value"><?php echo esc_html( slh_fa_number( $slh_missing ) ); ?></span>
		<span class="slh-tile__meta"><?php echo $slh_missing ? esc_html__( 'پایین همین صفحه، با دلیل و تلاش مجدد', 'salamhub' ) : esc_html__( 'هیچ سفارشی جا نیفتاده', 'salamhub' ); ?></span>
	</div>
	<div class="slh-tile">
		<span class="slh-tile__label"><?php esc_html_e( 'آخرین بررسی', 'salamhub' ); ?></span>
		<span class="slh-tile__value slh-tile__value--sm"><?php echo esc_html( $slh_polled ? slh_time_ago( $slh_polled ) : __( 'هنوز نه', 'salamhub' ) ); ?></span>
		<?php /* translators: %s: minutes */ ?>
		<span class="slh-tile__meta"><?php echo esc_html( sprintf( __( 'هر %s دقیقه، خودکار', 'salamhub' ), slh_fa_number( SLH_Settings::get( 'orders_interval', 5 ) ) ) ); ?></span>
	</div>
	<div class="slh-tile">
		<span class="slh-tile__label"><?php esc_html_e( 'مرجع موجودی', 'salamhub' ); ?></span>
		<span class="slh-tile__value slh-tile__value--sm"><?php echo $slh_reference ? esc_html__( 'باسلام', 'salamhub' ) : esc_html__( 'سایت', 'salamhub' ); ?></span>
		<?php /* translators: %s: safety stock */ ?>
		<span class="slh-tile__meta"><?php echo esc_html( sprintf( __( 'موجودی اطمینان: %s', 'salamhub' ), slh_fa_number( SLH_Settings::get( 'safety_stock', 0 ) ) ) ); ?></span>
	</div>
</section>

<?php if ( $slh_failed['items'] ) : ?>
	<section class="slh-card slh-section">
		<div class="slh-card__head"><h2 class="slh-card__title"><?php esc_html_e( 'سفارش‌هایی که ثبت نشدند', 'salamhub' ); ?></h2></div>
		<div class="slh-table-wrap">
			<table class="slh-table">
				<thead><tr>
					<th scope="col"><?php esc_html_e( 'سفارش باسلام', 'salamhub' ); ?></th>
					<th scope="col"><?php esc_html_e( 'چه شد و چه کنم', 'salamhub' ); ?></th>
					<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'عملیات', 'salamhub' ); ?></span></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $slh_failed['items'] as $slh_log ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $slh_log->title ); ?></strong><span class="slh-table__why"><?php echo esc_html( slh_time_ago( $slh_log->created_at ) ); ?></span></td>
						<td><?php echo esc_html( $slh_log->message ); ?><span class="slh-table__why"><?php echo esc_html( trim( $slh_log->reason . ' ' . $slh_log->suggestion ) ); ?></span></td>
						<td><button type="button" class="slh-btn" data-slh-retry="<?php echo esc_attr( $slh_log->id ); ?>"><?php esc_html_e( 'تلاش مجدد', 'salamhub' ); ?></button></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</section>
<?php endif; ?>

<section class="slh-card slh-section">
	<div class="slh-card__head"><h2 class="slh-card__title"><?php esc_html_e( 'آخرین سفارش‌ها', 'salamhub' ); ?></h2></div>
	<?php if ( ! $slh_rows ) : ?>
		<div class="slh-empty">
			<span class="dashicons dashicons-cart" aria-hidden="true"></span>
			<p><?php echo $slh_enabled ? esc_html__( 'هنوز سفارشی از باسلام نیامده. سفارش بعدی خودکار اینجا و در سفارش‌های ووکامرس ظاهر می‌شود.', 'salamhub' ) : esc_html__( 'با روشن کردن دریافت سفارش‌ها، سفارش‌های باسلام اینجا می‌آیند.', 'salamhub' ); ?></p>
		</div>
	<?php else : ?>
		<div class="slh-table-wrap">
			<table class="slh-table">
				<thead><tr>
					<th scope="col"><?php esc_html_e( 'سفارش ووکامرس', 'salamhub' ); ?></th>
					<th scope="col"><?php esc_html_e( 'مشتری', 'salamhub' ); ?></th>
					<th scope="col"><?php esc_html_e( 'مبلغ', 'salamhub' ); ?></th>
					<th scope="col"><?php esc_html_e( 'وضعیت در باسلام', 'salamhub' ); ?></th>
					<th scope="col"><?php esc_html_e( 'وضعیت در سایت', 'salamhub' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $slh_rows as $slh_row ) : ?>
					<?php
					$slh_order = wc_get_order( (int) $slh_row->wc_id );
					if ( ! $slh_order ) {
						continue;
					}
					?>
					<tr>
						<td>
							<a href="<?php echo esc_url( $slh_order->get_edit_order_url() ); ?>"><strong><?php echo esc_html( '#' . $slh_order->get_order_number() ); ?></strong></a>
							<?php /* translators: %s: parcel id */ ?>
							<span class="slh-table__why"><?php echo esc_html( sprintf( __( 'باسلام #%s', 'salamhub' ), $slh_row->basalam_id ) ); ?> · <?php echo esc_html( $slh_order->get_date_created() ? slh_time_ago( $slh_order->get_date_created()->date( 'Y-m-d H:i:s' ) ) : '' ); ?></span>
						</td>
						<td><?php echo esc_html( trim( $slh_order->get_formatted_billing_full_name() ) ); ?><span class="slh-table__why"><?php echo esc_html( $slh_order->get_billing_city() ); ?></span></td>
						<td><?php echo wp_kses_post( $slh_order->get_formatted_order_total() ); ?></td>
						<td>
							<?php echo SLH_Order_UI::status_badge( (int) $slh_order->get_meta( '_slh_parcel_status' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php if ( 'error' === $slh_row->sync_status && $slh_row->last_error ) : ?>
								<span class="slh-table__why"><?php echo esc_html( $slh_row->last_error ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( wc_get_order_status_name( $slh_order->get_status() ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</section>

<?php $slh_rec = SLH_Reconcile::last(); ?>
<section class="slh-card slh-section">
	<div class="slh-card__head">
		<div>
			<h2 class="slh-card__title"><?php esc_html_e( 'تطبیق شبانه', 'salamhub' ); ?></h2>
			<p class="slh-card__meta"><?php esc_html_e( 'هر شب حدود ساعت ۳، همه‌ی سفارش‌های ۷ روز اخیر باسلام با سفارش‌های سایت مقایسه می‌شوند و هر سفارش جاافتاده ثبت و گزارش می‌شود؛ حتی اگر سایت ساعت‌ها قطع بوده باشد.', 'salamhub' ); ?></p>
		</div>
		<?php
		if ( ! $slh_rec ) {
			echo '<span class="slh-badge slh-badge--stale">' . esc_html__( 'هنوز اجرا نشده', 'salamhub' ) . '</span>';
		} elseif ( $slh_rec['error'] ) {
			echo '<span class="slh-badge slh-badge--error">' . esc_html__( 'ناموفق', 'salamhub' ) . '</span>';
		} elseif ( $slh_rec['missing'] ) {
			echo '<span class="slh-badge slh-badge--stale">' . esc_html__( 'جاافتاده پیدا شد', 'salamhub' ) . '</span>';
		} else {
			echo '<span class="slh-badge slh-badge--synced">' . esc_html__( 'همه‌چیز سر جایش', 'salamhub' ) . '</span>';
		}
		?>
	</div>
	<?php if ( $slh_rec ) : ?>
		<p class="slh-card__meta">
			<?php
			echo esc_html(
				$slh_rec['error']
					/* translators: 1: relative time, 2: error */
					? sprintf( __( 'آخرین اجرا %1$s: %2$s', 'salamhub' ), slh_time_ago( $slh_rec['at'] ), $slh_rec['error'] )
					/* translators: 1: relative time, 2: checked, 3: missing */
					: sprintf( __( 'آخرین اجرا %1$s: %2$s سفارش بررسی شد، %3$s سفارش جاافتاده پیدا و در صف ثبت قرار گرفت.', 'salamhub' ), slh_time_ago( $slh_rec['at'] ), slh_fa_number( $slh_rec['checked'] ), slh_fa_number( $slh_rec['missing'] ) )
			);
			?>
		</p>
	<?php endif; ?>
	<div class="slh-card__foot">
		<button type="button" class="slh-btn" data-slh-reconcile <?php disabled( ! $slh_enabled ); ?>><?php esc_html_e( 'تطبیق الان', 'salamhub' ); ?></button>
	</div>
</section>

<section class="slh-card slh-section">
	<div class="slh-card__head"><h2 class="slh-card__title"><?php esc_html_e( 'سریع‌تر با وب‌هوک (اختیاری)', 'salamhub' ); ?></h2></div>
	<p class="slh-card__meta"><?php esc_html_e( 'بدون وب‌هوک هم هیچ سفارشی گم نمی‌شود؛ فقط تا چند دقیقه دیرتر می‌رسد. اگر در پنل توسعه‌دهندگان باسلام وب‌هوک سفارش ساختی، این آدرس را بده. باسلام‌هاب به محتوای وب‌هوک اعتماد نمی‌کند و فقط با آن زودتر سفارش‌ها را از API می‌خواند.', 'salamhub' ); ?></p>
	<div class="slh-copy">
		<input class="slh-field__input" type="text" readonly value="<?php echo esc_attr( SLH_Order_Sync::webhook_url() ); ?>" aria-label="<?php esc_attr_e( 'آدرس وب‌هوک', 'salamhub' ); ?>" data-slh-copy-src>
		<button type="button" class="slh-btn" data-slh-copy><?php esc_html_e( 'کپی', 'salamhub' ); ?></button>
	</div>
	<?php $slh_hook_last = get_option( 'slh_webhook_last' ); ?>
	<?php /* translators: %s: relative time */ ?>
	<p class="slh-field__hint"><?php echo esc_html( $slh_hook_last ? sprintf( __( 'آخرین وب‌هوک: %s', 'salamhub' ), slh_time_ago( $slh_hook_last ) ) : __( 'هنوز وب‌هوکی نرسیده.', 'salamhub' ) ); ?></p>
</section>

<?php if ( $slh_reference ) : ?>
	<section class="slh-card slh-section">
		<div class="slh-card__head"><h2 class="slh-card__title"><?php esc_html_e( 'موجودی از باسلام', 'salamhub' ); ?></h2></div>
		<?php /* translators: %s: relative time */ ?>
		<p class="slh-card__meta"><?php echo esc_html( $slh_pulled ? sprintf( __( 'آخرین یکی‌سازی: %s · هر ساعت خودکار', 'salamhub' ), slh_time_ago( $slh_pulled ) ) : __( 'هنوز انجام نشده · هر ساعت خودکار', 'salamhub' ) ); ?></p>
		<div class="slh-card__foot">
			<button type="button" class="slh-btn" data-slh-stock-pull><?php esc_html_e( 'یکی‌سازی موجودی الان', 'salamhub' ); ?></button>
		</div>
	</section>
<?php endif; ?>
