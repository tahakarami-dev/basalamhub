<?php
/**
 * Dashboard: KPIs, sync activity, recent events and system health.
 *
 * @package SalamHub
 */

defined( 'ABSPATH' ) || exit;

$slh_connected = SLH_Settings::is_connected();
$slh_counts    = SLH_Links::counts( 'product' );
$slh_queue     = SLH_Queue::stats();
$slh_open_err  = SLH_Logger::count_open_errors( 24 );
$slh_last_sync = get_option( 'slh_last_sync_at', '' );
$slh_total     = SLH_App::product_total();
$slh_batch     = SLH_Bulk::current();
$slh_activity  = SLH_App::activity( 14 );
$slh_checks    = SLH_App::health_checks();
$slh_events    = SLH_Logger::query( array( 'per_page' => 7 ) )['items'];
$slh_user      = wp_get_current_user();

$slh_sum_ok  = array_sum( wp_list_pluck( $slh_activity, 'ok' ) );
$slh_sum_err = array_sum( wp_list_pluck( $slh_activity, 'error' ) );
$slh_peak    = max( 1, max( array_map( function ( $d ) {
	return $d['ok'] + $d['error'];
}, $slh_activity ) ) );
// A clean axis top: 1, 2, 5 × 10^n.
$slh_mag  = pow( 10, floor( log10( $slh_peak ) ) );
$slh_axis = $slh_mag;
foreach ( array( 1, 2, 5, 10 ) as $slh_step ) {
	if ( $slh_step * $slh_mag >= $slh_peak ) {
		$slh_axis = $slh_step * $slh_mag;
		break;
	}
}
$slh_bad_checks = count( array_filter( $slh_checks, function ( $c ) {
	return 'ok' !== $c[0];
} ) );
$slh_level_icon = array( 'success' => 'dashicons-yes-alt', 'warning' => 'dashicons-clock', 'error' => 'dashicons-warning', 'info' => 'dashicons-info-outline' );
?>
<header class="slh-page-head slh-page-head--row">
	<div>
		<?php /* translators: %s: user name */ ?>
		<h1 class="slh-page-title"><?php echo esc_html( sprintf( __( 'سلام %s', 'salamhub' ), $slh_user->display_name ) ); ?></h1>
		<p class="slh-card__meta">
			<?php
			echo esc_html(
				$slh_connected
					/* translators: 1: booth, 2: relative time */
					? sprintf( __( 'غرفه‌ی «%1$s» · آخرین همگام‌سازی %2$s', 'salamhub' ), SLH_Settings::connection()['vendor_title'], slh_time_ago( $slh_last_sync ) )
					: __( 'هنوز به باسلام وصل نشده‌ای. چند دقیقه بیشتر طول نمی‌کشد.', 'salamhub' )
			);
			?>
		</p>
	</div>
	<?php if ( $slh_connected ) : ?>
		<a class="slh-btn slh-btn--primary" href="<?php echo esc_url( admin_url( 'admin.php?page=salamhub-bulk' ) ); ?>" data-slh-nav>
			<span class="dashicons dashicons-upload" aria-hidden="true"></span><?php esc_html_e( 'ارسال محصولات', 'salamhub' ); ?>
		</a>
	<?php else : ?>
		<a class="slh-btn slh-btn--primary" href="<?php echo esc_url( admin_url( 'admin.php?page=salamhub-settings' ) ); ?>" data-slh-nav><?php esc_html_e( 'اتصال به باسلام', 'salamhub' ); ?></a>
	<?php endif; ?>
</header>

<?php if ( ! $slh_connected ) : ?>
	<section class="slh-card slh-section slh-onboard">
		<div class="slh-card__head"><h2 class="slh-card__title"><?php esc_html_e( 'راه‌اندازی در چهار قدم', 'salamhub' ); ?></h2></div>
		<ol class="slh-onboard__steps">
			<li><strong><?php esc_html_e( 'توکن بساز', 'salamhub' ); ?></strong><span><?php esc_html_e( 'در پنل توسعه‌دهندگان باسلام، با دسترسی محصولات غرفه.', 'salamhub' ); ?></span></li>
			<li><strong><?php esc_html_e( 'وصل شو', 'salamhub' ); ?></strong><span><?php esc_html_e( 'توکن را در تنظیمات بچسبان و تست اتصال بزن.', 'salamhub' ); ?></span></li>
			<li><strong><?php esc_html_e( 'دسته‌ها را نگاشت کن', 'salamhub' ); ?></strong><span><?php esc_html_e( 'برای هر دسته‌ی ووکامرس، دسته‌ی باسلام.', 'salamhub' ); ?></span></li>
			<li><strong><?php esc_html_e( 'بفرست', 'salamhub' ); ?></strong><span><?php esc_html_e( 'اول یک محصول، بعد بقیه با ارسال گروهی.', 'salamhub' ); ?></span></li>
		</ol>
	</section>
<?php endif; ?>

<?php if ( $slh_batch && 'running' === $slh_batch['status'] ) : ?>
	<section class="slh-card slh-section">
		<?php SLH_Admin_Tools::progress_html( $slh_batch, SLH_Bulk::progress( $slh_batch ) ); ?>
		<div class="slh-card__foot">
			<a class="slh-btn slh-btn--ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=salamhub-bulk' ) ); ?>" data-slh-nav><?php esc_html_e( 'جزئیات ارسال گروهی', 'salamhub' ); ?></a>
		</div>
	</section>
<?php endif; ?>

<section class="slh-tiles slh-section" aria-label="<?php esc_attr_e( 'خلاصه', 'salamhub' ); ?>">
	<a class="slh-tile" href="<?php echo esc_url( admin_url( 'admin.php?page=salamhub-products&status=synced' ) ); ?>" data-slh-nav>
		<span class="slh-tile__label"><?php esc_html_e( 'محصول متصل', 'salamhub' ); ?></span>
		<span class="slh-tile__value"><?php echo esc_html( slh_fa_number( $slh_counts['linked'] ) ); ?></span>
		<?php /* translators: %s: total products */ ?>
		<span class="slh-tile__meta"><?php echo esc_html( sprintf( __( 'از %s محصول منتشرشده', 'salamhub' ), slh_fa_number( $slh_total ) ) ); ?></span>
		<span class="slh-meter" aria-hidden="true"><span style="width:<?php echo esc_attr( $slh_total ? min( 100, round( 100 * $slh_counts['linked'] / $slh_total ) ) : 0 ); ?>%"></span></span>
	</a>
	<a class="slh-tile" href="<?php echo esc_url( admin_url( 'admin.php?page=salamhub-products&status=queued' ) ); ?>" data-slh-nav>
		<span class="slh-tile__label"><?php esc_html_e( 'در صف', 'salamhub' ); ?></span>
		<span class="slh-tile__value"><?php echo esc_html( slh_fa_number( $slh_queue['pending'] + $slh_queue['running'] ) ); ?></span>
		<span class="slh-tile__meta"><?php echo $slh_queue['running'] ? esc_html__( 'در حال پردازش…', 'salamhub' ) : esc_html__( 'کار در پس‌زمینه', 'salamhub' ); ?></span>
	</a>
	<a class="slh-tile<?php echo $slh_open_err ? ' slh-tile--alert' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=salamhub-logs&level=error&unresolved=1' ) ); ?>" data-slh-nav>
		<span class="slh-tile__label"><?php esc_html_e( 'خطای باز در ۲۴ ساعت', 'salamhub' ); ?></span>
		<span class="slh-tile__value"><?php echo esc_html( slh_fa_number( $slh_open_err ) ); ?></span>
		<span class="slh-tile__meta">
			<?php if ( $slh_open_err ) : ?>
				<span class="dashicons dashicons-warning" aria-hidden="true"></span><?php esc_html_e( 'دلیل و راه‌حل در لاگ', 'salamhub' ); ?>
			<?php else : ?>
				<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><?php esc_html_e( 'همه‌چیز تحت کنترل است', 'salamhub' ); ?>
			<?php endif; ?>
		</span>
	</a>
	<div class="slh-tile slh-tile--muted">
		<span class="slh-tile__label"><?php esc_html_e( 'سفارش جاافتاده', 'salamhub' ); ?></span>
		<span class="slh-tile__value">—</span>
		<span class="slh-tile__meta"><?php esc_html_e( 'با فعال‌شدن همگام‌سازی سفارش‌ها', 'salamhub' ); ?></span>
	</div>
</section>

<div class="slh-grid slh-section">
	<section class="slh-card slh-grid__wide">
		<div class="slh-card__head">
			<div>
				<h2 class="slh-card__title"><?php esc_html_e( 'فعالیت همگام‌سازی محصولات', 'salamhub' ); ?></h2>
				<?php /* translators: 1: successes, 2: errors */ ?>
				<p class="slh-card__meta"><?php echo esc_html( sprintf( __( '۱۴ روز اخیر · %1$s ارسال موفق، %2$s خطا', 'salamhub' ), slh_fa_number( $slh_sum_ok ), slh_fa_number( $slh_sum_err ) ) ); ?></p>
			</div>
			<ul class="slh-legend" aria-label="<?php esc_attr_e( 'راهنمای نمودار', 'salamhub' ); ?>">
				<li><span class="slh-legend__key slh-legend__key--ok" aria-hidden="true"></span><?php esc_html_e( 'ارسال موفق', 'salamhub' ); ?></li>
				<li><span class="slh-legend__key slh-legend__key--err" aria-hidden="true"></span><?php esc_html_e( 'خطا', 'salamhub' ); ?></li>
			</ul>
		</div>
		<?php if ( 0 === $slh_sum_ok + $slh_sum_err ) : ?>
			<div class="slh-empty">
				<span class="dashicons dashicons-chart-bar" aria-hidden="true"></span>
				<p><?php esc_html_e( 'هنوز فعالیتی ثبت نشده. بعد از اولین ارسال، نمودار اینجا پر می‌شود.', 'salamhub' ); ?></p>
			</div>
		<?php else : ?>
			<div class="slh-chart" data-slh-chart role="img" aria-label="<?php esc_attr_e( 'نمودار ستونی ارسال‌های موفق و خطاها در ۱۴ روز اخیر؛ جدول داده پایین نمودار است.', 'salamhub' ); ?>">
				<div class="slh-chart__axis" aria-hidden="true">
					<span><?php echo esc_html( slh_fa_number( $slh_axis ) ); ?></span>
					<span><?php echo esc_html( slh_fa_number( $slh_axis / 2 ) ); ?></span>
					<span>۰</span>
				</div>
				<div class="slh-chart__plot">
					<div class="slh-chart__grid" aria-hidden="true"><span></span><span></span><span></span></div>
					<div class="slh-chart__cols">
						<?php foreach ( $slh_activity as $slh_i => $slh_day ) : ?>
							<?php
							$slh_ok_h  = 100 * $slh_day['ok'] / $slh_axis;
							$slh_err_h = 100 * $slh_day['error'] / $slh_axis;
							$slh_last  = count( $slh_activity ) - 1 === $slh_i;
							?>
							<div class="slh-chart__col" tabindex="0"
								data-label="<?php echo esc_attr( $slh_day['label'] ); ?>"
								data-ok="<?php echo esc_attr( slh_fa_number( $slh_day['ok'] ) ); ?>"
								data-err="<?php echo esc_attr( slh_fa_number( $slh_day['error'] ) ); ?>">
								<div class="slh-chart__stack">
									<?php if ( $slh_day['error'] ) : ?>
										<span class="slh-chart__bar slh-chart__bar--err" style="height:<?php echo esc_attr( round( $slh_err_h, 2 ) ); ?>%"></span>
									<?php endif; ?>
									<?php if ( $slh_day['ok'] ) : ?>
										<span class="slh-chart__bar slh-chart__bar--ok" style="height:<?php echo esc_attr( round( $slh_ok_h, 2 ) ); ?>%"></span>
									<?php endif; ?>
								</div>
								<span class="slh-chart__day<?php echo $slh_last ? ' is-today' : ''; ?>"><?php echo $slh_last ? esc_html__( 'امروز', 'salamhub' ) : esc_html( $slh_day['day'] ); ?></span>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
				<div class="slh-chart__tip" data-slh-tip hidden></div>
			</div>
			<details class="slh-details slh-chart__table">
				<summary><?php esc_html_e( 'نمایش جدول داده', 'salamhub' ); ?></summary>
				<table class="slh-table">
					<thead><tr><th scope="col"><?php esc_html_e( 'روز', 'salamhub' ); ?></th><th scope="col"><?php esc_html_e( 'ارسال موفق', 'salamhub' ); ?></th><th scope="col"><?php esc_html_e( 'خطا', 'salamhub' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( array_reverse( $slh_activity ) as $slh_day ) : ?>
						<tr><td><?php echo esc_html( $slh_day['label'] ); ?></td><td><?php echo esc_html( slh_fa_number( $slh_day['ok'] ) ); ?></td><td><?php echo esc_html( slh_fa_number( $slh_day['error'] ) ); ?></td></tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</details>
		<?php endif; ?>
	</section>

	<section class="slh-card">
		<div class="slh-card__head">
			<h2 class="slh-card__title"><?php esc_html_e( 'سلامت سیستم', 'salamhub' ); ?></h2>
			<?php if ( $slh_bad_checks ) : ?>
				<?php /* translators: %s: count */ ?>
				<span class="slh-badge slh-badge--stale"><?php echo esc_html( sprintf( __( '%s مورد نیاز به توجه', 'salamhub' ), slh_fa_digits( $slh_bad_checks ) ) ); ?></span>
			<?php else : ?>
				<span class="slh-badge slh-badge--synced"><?php esc_html_e( 'سالم', 'salamhub' ); ?></span>
			<?php endif; ?>
		</div>
		<ul class="slh-health">
			<?php foreach ( $slh_checks as $slh_check ) : ?>
				<li class="slh-health__item slh-health__item--<?php echo esc_attr( $slh_check[0] ); ?>">
					<span class="dashicons <?php echo esc_attr( 'ok' === $slh_check[0] ? 'dashicons-yes-alt' : ( 'warn' === $slh_check[0] ? 'dashicons-warning' : 'dashicons-dismiss' ) ); ?>" aria-hidden="true"></span>
					<span class="slh-health__text">
						<strong><?php echo esc_html( $slh_check[1] ); ?></strong>
						<?php if ( $slh_check[3] ) : ?>
							<a href="<?php echo esc_url( $slh_check[3] ); ?>" data-slh-nav><?php echo esc_html( $slh_check[2] ); ?></a>
						<?php else : ?>
							<span><?php echo esc_html( $slh_check[2] ); ?></span>
						<?php endif; ?>
					</span>
				</li>
			<?php endforeach; ?>
		</ul>
	</section>

	<section class="slh-card">
		<div class="slh-card__head">
			<h2 class="slh-card__title"><?php esc_html_e( 'آخرین رویدادها', 'salamhub' ); ?></h2>
			<a class="slh-btn slh-btn--ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=salamhub-logs' ) ); ?>" data-slh-nav><?php esc_html_e( 'همه‌ی لاگ', 'salamhub' ); ?></a>
		</div>
		<?php if ( ! $slh_events ) : ?>
			<p class="slh-card__meta"><?php esc_html_e( 'هنوز رویدادی ثبت نشده.', 'salamhub' ); ?></p>
		<?php else : ?>
			<ul class="slh-feed">
				<?php foreach ( $slh_events as $slh_ev ) : ?>
					<li class="slh-feed__item slh-feed__item--<?php echo esc_attr( $slh_ev->level ); ?>">
						<span class="slh-feed__icon dashicons <?php echo esc_attr( $slh_level_icon[ $slh_ev->level ] ); ?>" aria-hidden="true"></span>
						<span class="slh-feed__text">
							<strong><?php echo esc_html( $slh_ev->title ); ?></strong>
							<span><?php echo esc_html( $slh_ev->message ); ?></span>
						</span>
						<time class="slh-feed__time" datetime="<?php echo esc_attr( $slh_ev->created_at ); ?>Z"><?php echo esc_html( slh_time_ago( $slh_ev->created_at ) ); ?></time>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</section>
</div>
