<?php
/**
 * Dashboard: KPIs, sync activity, recent events and system health.
 *
 * @package BasalamHub
 */

defined( 'ABSPATH' ) || exit;

$bsh_connected = BSH_Settings::is_connected();
$bsh_counts    = BSH_Links::counts( 'product' );
$bsh_queue     = BSH_Queue::stats();
$bsh_open_err  = BSH_Logger::count_open_errors( 24 );
$bsh_last_sync = get_option( 'bsh_last_sync_at', '' );
$bsh_total     = BSH_App::product_total();
$bsh_batch     = BSH_Bulk::current();
$bsh_activity  = BSH_App::activity( 14 );
$bsh_checks    = BSH_App::health_checks();
$bsh_events    = BSH_Logger::query( array( 'per_page' => 7 ) )['items'];
$bsh_user      = wp_get_current_user();
$bsh_today     = BSH_Insights::today();
$bsh_score     = BSH_Insights::score();
$bsh_demo      = BSH_Demo::active();

$bsh_sum_ok  = array_sum( wp_list_pluck( $bsh_activity, 'ok' ) );
$bsh_sum_err = array_sum( wp_list_pluck( $bsh_activity, 'error' ) );
$bsh_peak    = max(
	1,
	max(
		array_map(
			function ( $d ) {
				return $d['ok'] + $d['error'];
			},
			$bsh_activity
		)
	)
);
// A clean axis top: 1, 2, 5 × 10^n.
$bsh_mag  = pow( 10, floor( log10( $bsh_peak ) ) );
$bsh_axis = $bsh_mag;
foreach ( array( 1, 2, 5, 10 ) as $bsh_step ) {
	if ( $bsh_step * $bsh_mag >= $bsh_peak ) {
		$bsh_axis = $bsh_step * $bsh_mag;
		break;
	}
}
$bsh_bad_checks = count(
	array_filter(
		$bsh_checks,
		function ( $c ) {
			return 'ok' !== $c[0];
		}
	)
);
$bsh_level_icon = array(
	'success' => 'check',
	'warning' => 'clock',
	'error'   => 'alert',
	'info'    => 'info',
);
?>
<header class="bsh-page-head bsh-page-head--row">
	<div>
		<?php /* translators: 1: greeting, 2: user name */ ?>
		<h1 class="bsh-page-title"><?php echo esc_html( sprintf( __( '%1$s، %2$s', 'basalamhub' ), BSH_Insights::greeting(), $bsh_user->display_name ) ); ?></h1>
		<p class="bsh-card__meta">
			<?php
			echo esc_html(
				$bsh_demo && ! $bsh_connected
					? __( 'داری با داده‌ی نمایشی کار می‌کنی؛ هیچ چیزی به باسلام فرستاده نمی‌شود.', 'basalamhub' )
					: ( $bsh_connected
					/* translators: 1: booth, 2: relative time */
					? sprintf( __( 'غرفه‌ی «%1$s» · آخرین همگام‌سازی %2$s', 'basalamhub' ), BSH_Settings::connection()['vendor_title'], bsh_time_ago( $bsh_last_sync ) )
					: __( 'هنوز به باسلام وصل نشده‌ای. چند دقیقه بیشتر طول نمی‌کشد.', 'basalamhub' ) )
			);
			?>
		</p>
	</div>
	<?php if ( $bsh_connected ) : ?>
		<a class="bsh-btn bsh-btn--primary" href="<?php echo esc_url( admin_url( 'admin.php?page=basalamhub-bulk' ) ); ?>" data-bsh-nav>
			<?php BSH_Icons::e( 'upload' ); ?><?php esc_html_e( 'ارسال محصولات', 'basalamhub' ); ?>
		</a>
	<?php else : ?>
		<a class="bsh-btn bsh-btn--primary" href="<?php echo esc_url( admin_url( 'admin.php?page=basalamhub-settings' ) ); ?>" data-bsh-nav><?php esc_html_e( 'اتصال به باسلام', 'basalamhub' ); ?></a>
	<?php endif; ?>
</header>

<?php
$bsh_ring_r    = 52;
$bsh_ring_len  = 2 * M_PI * $bsh_ring_r;
$bsh_basa_pct  = $bsh_today['revenue'] > 0 ? round( 100 * $bsh_today['basalam_revenue'] / $bsh_today['revenue'] ) : 0;
?>
<div class="bsh-hero bsh-section">
	<section class="bsh-card bsh-today bsh-today--<?php echo esc_attr( $bsh_today['mood'] ); ?>" aria-labelledby="bsh-today-title">
		<div class="bsh-card__head">
			<h2 class="bsh-card__title" id="bsh-today-title"><?php BSH_Icons::e( 'sun', 20 ); ?><?php esc_html_e( 'امروز چی شد', 'basalamhub' ); ?></h2>
			<time class="bsh-card__meta"><?php echo esc_html( wp_date( 'l j F' ) ); ?></time>
		</div>
		<p class="bsh-today__sentence"><?php echo esc_html( $bsh_today['sentence'] ); ?></p>
		<dl class="bsh-today__stats">
			<div><dt><?php esc_html_e( 'سفارش امروز', 'basalamhub' ); ?></dt><dd><?php echo esc_html( bsh_fa_number( $bsh_today['orders'] ) ); ?></dd></div>
			<div><dt><?php esc_html_e( 'فروش امروز', 'basalamhub' ); ?></dt><dd><?php echo esc_html( BSH_Sales::compact( $bsh_today['revenue'] ) ); ?></dd></div>
			<?php /* translators: %s: percent */ ?>
			<div><dt><?php esc_html_e( 'سهم باسلام', 'basalamhub' ); ?></dt><dd><?php echo esc_html( sprintf( __( '%s٪', 'basalamhub' ), bsh_fa_number( $bsh_basa_pct ) ) ); ?></dd></div>
		</dl>
		<a class="bsh-btn bsh-btn--ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=basalamhub-sales' ) ); ?>" data-bsh-nav><?php BSH_Icons::e( 'chart', 18 ); ?><?php esc_html_e( 'گزارش فروش', 'basalamhub' ); ?></a>
	</section>

	<section class="bsh-card bsh-hscore bsh-hscore--<?php echo esc_attr( $bsh_score['level'] ); ?>" aria-labelledby="bsh-hscore-title">
		<div class="bsh-card__head">
			<h2 class="bsh-card__title" id="bsh-hscore-title"><?php esc_html_e( 'امتیاز سلامت فروشگاه', 'basalamhub' ); ?></h2>
		</div>
		<div class="bsh-hscore__body">
			<?php /* translators: %s: score */ ?>
			<div class="bsh-hscore__ring" role="img" aria-label="<?php echo esc_attr( sprintf( __( 'امتیاز %s از ۱۰۰', 'basalamhub' ), bsh_fa_number( $bsh_score['score'] ) ) ); ?>">
				<svg viewBox="0 0 120 120" width="132" height="132" aria-hidden="true" focusable="false">
					<circle class="bsh-hscore__track" cx="60" cy="60" r="<?php echo esc_attr( $bsh_ring_r ); ?>"></circle>
					<circle class="bsh-hscore__arc" cx="60" cy="60" r="<?php echo esc_attr( $bsh_ring_r ); ?>" stroke-dasharray="<?php echo esc_attr( round( $bsh_ring_len, 2 ) ); ?>" stroke-dashoffset="<?php echo esc_attr( round( $bsh_ring_len * ( 1 - $bsh_score['score'] / 100 ), 2 ) ); ?>"></circle>
				</svg>
				<span class="bsh-hscore__num"><strong><?php echo esc_html( bsh_fa_number( $bsh_score['score'] ) ); ?></strong><small><?php esc_html_e( 'از ۱۰۰', 'basalamhub' ); ?></small></span>
			</div>
			<div class="bsh-hscore__why">
				<p class="bsh-hscore__label"><?php echo esc_html( $bsh_score['label'] ); ?></p>
				<?php if ( $bsh_score['reasons'] ) : ?>
					<ul class="bsh-hscore__reasons">
						<?php foreach ( array_slice( $bsh_score['reasons'], 0, 4 ) as $bsh_reason ) : ?>
							<li>
								<?php /* translators: %s: points */ ?>
								<span class="bsh-hscore__pts"><?php echo esc_html( sprintf( __( '−%s', 'basalamhub' ), bsh_fa_number( $bsh_reason['points'] ) ) ); ?></span>
								<?php if ( $bsh_reason['url'] ) : ?>
									<a href="<?php echo esc_url( $bsh_reason['url'] ); ?>" data-bsh-nav><?php echo esc_html( $bsh_reason['text'] ); ?></a>
								<?php else : ?>
									<span><?php echo esc_html( $bsh_reason['text'] ); ?></span>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php else : ?>
					<p class="bsh-card__meta"><?php esc_html_e( 'اتصال، صف، سفارش‌ها و موجودی همه سالم‌اند. همین‌طور ادامه بده!', 'basalamhub' ); ?></p>
				<?php endif; ?>
			</div>
		</div>
	</section>
</div>

<?php if ( ! $bsh_connected && ! $bsh_demo ) : ?>
	<section class="bsh-card bsh-section bsh-demo-card">
		<div>
			<h2 class="bsh-card__title"><?php BSH_Icons::e( 'image', 20 ); ?><?php esc_html_e( 'اول ببین، بعد وصل شو', 'basalamhub' ); ?></h2>
			<p class="bsh-card__meta"><?php esc_html_e( 'حالت نمایشی چند محصول و یک ماه سفارش نمونه می‌سازد تا داشبورد فروش، هشدار موجودی و اعلان سفارش را همین حالا ببینی. چیزی به باسلام فرستاده نمی‌شود و با یک کلیک پاک می‌شود.', 'basalamhub' ); ?></p>
		</div>
		<button type="button" class="bsh-btn bsh-btn--primary" data-bsh-demo="fill"><?php esc_html_e( 'روشن کردن حالت نمایشی', 'basalamhub' ); ?></button>
	</section>
<?php endif; ?>

<?php if ( ! $bsh_connected && ! $bsh_demo ) : ?>
	<section class="bsh-card bsh-section bsh-onboard">
		<div class="bsh-card__head"><h2 class="bsh-card__title"><?php esc_html_e( 'راه‌اندازی در چهار قدم', 'basalamhub' ); ?></h2></div>
		<ol class="bsh-onboard__steps">
			<li><strong><?php esc_html_e( 'توکن بساز', 'basalamhub' ); ?></strong><span><?php esc_html_e( 'در پنل توسعه‌دهندگان باسلام، با دسترسی محصولات و سفارش‌های غرفه.', 'basalamhub' ); ?></span></li>
			<li><strong><?php esc_html_e( 'وصل شو', 'basalamhub' ); ?></strong><span><?php esc_html_e( 'توکن را در تنظیمات بچسبان و تست اتصال بزن.', 'basalamhub' ); ?></span></li>
			<li><strong><?php esc_html_e( 'دسته‌ها را نگاشت کن', 'basalamhub' ); ?></strong><span><?php esc_html_e( 'برای هر دسته‌ی ووکامرس، دسته‌ی باسلام.', 'basalamhub' ); ?></span></li>
			<li><strong><?php esc_html_e( 'بفرست', 'basalamhub' ); ?></strong><span><?php esc_html_e( 'اول یک محصول، بعد بقیه با ارسال گروهی.', 'basalamhub' ); ?></span></li>
		</ol>
	</section>
<?php endif; ?>

<?php if ( $bsh_batch && 'running' === $bsh_batch['status'] ) : ?>
	<section class="bsh-card bsh-section">
		<?php BSH_Admin_Tools::progress_html( $bsh_batch, BSH_Bulk::progress( $bsh_batch ) ); ?>
		<div class="bsh-card__foot">
			<a class="bsh-btn bsh-btn--ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=basalamhub-bulk' ) ); ?>" data-bsh-nav><?php esc_html_e( 'جزئیات ارسال گروهی', 'basalamhub' ); ?></a>
		</div>
	</section>
<?php endif; ?>

<section class="bsh-tiles bsh-section" aria-label="<?php esc_attr_e( 'خلاصه', 'basalamhub' ); ?>">
	<a class="bsh-tile" href="<?php echo esc_url( admin_url( 'admin.php?page=basalamhub-products&status=synced' ) ); ?>" data-bsh-nav>
		<span class="bsh-tile__label"><?php esc_html_e( 'محصول متصل', 'basalamhub' ); ?></span>
		<span class="bsh-tile__value"><?php echo esc_html( bsh_fa_number( $bsh_counts['linked'] ) ); ?></span>
		<?php /* translators: %s: total products */ ?>
		<span class="bsh-tile__meta"><?php echo esc_html( sprintf( __( 'از %s محصول منتشرشده', 'basalamhub' ), bsh_fa_number( $bsh_total ) ) ); ?></span>
		<span class="bsh-meter" aria-hidden="true"><span style="width:<?php echo esc_attr( $bsh_total ? min( 100, round( 100 * $bsh_counts['linked'] / $bsh_total ) ) : 0 ); ?>%"></span></span>
	</a>
	<a class="bsh-tile" href="<?php echo esc_url( admin_url( 'admin.php?page=basalamhub-products&status=queued' ) ); ?>" data-bsh-nav>
		<span class="bsh-tile__label"><?php esc_html_e( 'در صف', 'basalamhub' ); ?></span>
		<span class="bsh-tile__value"><?php echo esc_html( bsh_fa_number( $bsh_queue['pending'] + $bsh_queue['running'] ) ); ?></span>
		<span class="bsh-tile__meta"><?php echo $bsh_queue['running'] ? esc_html__( 'در حال پردازش…', 'basalamhub' ) : esc_html__( 'کار در پس‌زمینه', 'basalamhub' ); ?></span>
	</a>
	<a class="bsh-tile<?php echo $bsh_open_err ? ' bsh-tile--alert' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=basalamhub-logs&level=error&unresolved=1' ) ); ?>" data-bsh-nav>
		<span class="bsh-tile__label"><?php esc_html_e( 'خطای باز در ۲۴ ساعت', 'basalamhub' ); ?></span>
		<span class="bsh-tile__value"><?php echo esc_html( bsh_fa_number( $bsh_open_err ) ); ?></span>
		<span class="bsh-tile__meta">
			<?php if ( $bsh_open_err ) : ?>
				<?php BSH_Icons::e( 'alert' ); ?><?php esc_html_e( 'دلیل و راه‌حل در لاگ', 'basalamhub' ); ?>
			<?php else : ?>
				<?php BSH_Icons::e( 'check' ); ?><?php esc_html_e( 'همه‌چیز تحت کنترل است', 'basalamhub' ); ?>
			<?php endif; ?>
		</span>
	</a>
	<?php $bsh_missing = BSH_Order_Sync::missing_count(); ?>
	<a class="bsh-tile<?php echo $bsh_missing ? ' bsh-tile--alert' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=basalamhub-orders' ) ); ?>" data-bsh-nav>
		<span class="bsh-tile__label"><?php esc_html_e( 'سفارش جاافتاده', 'basalamhub' ); ?></span>
		<span class="bsh-tile__value"><?php echo BSH_Order_Sync::enabled() ? esc_html( bsh_fa_number( $bsh_missing ) ) : '—'; ?></span>
		<span class="bsh-tile__meta">
			<?php
			if ( ! BSH_Order_Sync::enabled() ) {
				esc_html_e( 'دریافت سفارش‌ها خاموش است', 'basalamhub' );
			} elseif ( $bsh_missing ) {
				echo BSH_Icons::svg( 'alert', 16 ) . esc_html__( 'در ووکامرس ثبت نشده‌اند', 'basalamhub' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG icon.
			} else {
				/* translators: %s: count */
				echo BSH_Icons::svg( 'check', 16 ) . esc_html( sprintf( __( '%s سفارش باسلام در سایت', 'basalamhub' ), bsh_fa_number( BSH_Order_Sync::imported_count() ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG icon.
			}
			?>
		</span>
	</a>
</section>

<div class="bsh-grid bsh-section">
	<section class="bsh-card bsh-grid__wide">
		<div class="bsh-card__head">
			<div>
				<h2 class="bsh-card__title"><?php esc_html_e( 'فعالیت همگام‌سازی محصولات', 'basalamhub' ); ?></h2>
				<?php /* translators: 1: successes, 2: errors */ ?>
				<p class="bsh-card__meta"><?php echo esc_html( sprintf( __( '۱۴ روز اخیر · %1$s ارسال موفق، %2$s خطا', 'basalamhub' ), bsh_fa_number( $bsh_sum_ok ), bsh_fa_number( $bsh_sum_err ) ) ); ?></p>
			</div>
			<ul class="bsh-legend" aria-label="<?php esc_attr_e( 'راهنمای نمودار', 'basalamhub' ); ?>">
				<li><span class="bsh-legend__key bsh-legend__key--ok" aria-hidden="true"></span><?php esc_html_e( 'ارسال موفق', 'basalamhub' ); ?></li>
				<li><span class="bsh-legend__key bsh-legend__key--err" aria-hidden="true"></span><?php esc_html_e( 'خطا', 'basalamhub' ); ?></li>
			</ul>
		</div>
		<?php if ( 0 === $bsh_sum_ok + $bsh_sum_err ) : ?>
			<div class="bsh-empty">
				<?php BSH_Icons::e( 'chart' ); ?>
				<p><?php esc_html_e( 'هنوز فعالیتی ثبت نشده. بعد از اولین ارسال، نمودار اینجا پر می‌شود.', 'basalamhub' ); ?></p>
			</div>
		<?php else : ?>
			<div class="bsh-chart" data-bsh-chart role="img" aria-label="<?php esc_attr_e( 'نمودار ستونی ارسال‌های موفق و خطاها در ۱۴ روز اخیر؛ جدول داده پایین نمودار است.', 'basalamhub' ); ?>">
				<div class="bsh-chart__axis" aria-hidden="true">
					<span><?php echo esc_html( bsh_fa_number( $bsh_axis ) ); ?></span>
					<span><?php echo esc_html( bsh_fa_number( $bsh_axis / 2 ) ); ?></span>
					<span>۰</span>
				</div>
				<div class="bsh-chart__plot">
					<div class="bsh-chart__grid" aria-hidden="true"><span></span><span></span><span></span></div>
					<div class="bsh-chart__cols">
						<?php foreach ( $bsh_activity as $bsh_i => $bsh_day ) : ?>
							<?php
							$bsh_ok_h  = 100 * $bsh_day['ok'] / $bsh_axis;
							$bsh_err_h = 100 * $bsh_day['error'] / $bsh_axis;
							$bsh_last  = count( $bsh_activity ) - 1 === $bsh_i;
							?>
							<div class="bsh-chart__col" tabindex="0"
								data-label="<?php echo esc_attr( $bsh_day['label'] ); ?>"
								data-ok="<?php echo esc_attr( bsh_fa_number( $bsh_day['ok'] ) ); ?>"
								data-err="<?php echo esc_attr( bsh_fa_number( $bsh_day['error'] ) ); ?>">
								<div class="bsh-chart__stack">
									<?php if ( $bsh_day['error'] ) : ?>
										<span class="bsh-chart__bar bsh-chart__bar--err" style="height:<?php echo esc_attr( round( $bsh_err_h, 2 ) ); ?>%"></span>
									<?php endif; ?>
									<?php if ( $bsh_day['ok'] ) : ?>
										<span class="bsh-chart__bar bsh-chart__bar--ok" style="height:<?php echo esc_attr( round( $bsh_ok_h, 2 ) ); ?>%"></span>
									<?php endif; ?>
								</div>
								<span class="bsh-chart__day<?php echo $bsh_last ? ' is-today' : ''; ?>"><?php echo $bsh_last ? esc_html__( 'امروز', 'basalamhub' ) : esc_html( $bsh_day['day'] ); ?></span>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
				<div class="bsh-chart__tip" data-bsh-tip hidden></div>
			</div>
			<details class="bsh-details bsh-chart__table">
				<summary><?php esc_html_e( 'نمایش جدول داده', 'basalamhub' ); ?></summary>
				<table class="bsh-table">
					<thead><tr><th scope="col"><?php esc_html_e( 'روز', 'basalamhub' ); ?></th><th scope="col"><?php esc_html_e( 'ارسال موفق', 'basalamhub' ); ?></th><th scope="col"><?php esc_html_e( 'خطا', 'basalamhub' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( array_reverse( $bsh_activity ) as $bsh_day ) : ?>
						<tr><td><?php echo esc_html( $bsh_day['label'] ); ?></td><td><?php echo esc_html( bsh_fa_number( $bsh_day['ok'] ) ); ?></td><td><?php echo esc_html( bsh_fa_number( $bsh_day['error'] ) ); ?></td></tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</details>
		<?php endif; ?>
	</section>

	<section class="bsh-card">
		<div class="bsh-card__head">
			<h2 class="bsh-card__title"><?php esc_html_e( 'سلامت سیستم', 'basalamhub' ); ?></h2>
			<?php if ( $bsh_bad_checks ) : ?>
				<?php /* translators: %s: count */ ?>
				<span class="bsh-badge bsh-badge--stale"><?php echo esc_html( sprintf( __( '%s مورد نیاز به توجه', 'basalamhub' ), bsh_fa_digits( $bsh_bad_checks ) ) ); ?></span>
			<?php else : ?>
				<span class="bsh-badge bsh-badge--synced"><?php esc_html_e( 'سالم', 'basalamhub' ); ?></span>
			<?php endif; ?>
		</div>
		<ul class="bsh-health">
			<?php foreach ( $bsh_checks as $bsh_check ) : ?>
				<li class="bsh-health__item bsh-health__item--<?php echo esc_attr( $bsh_check[0] ); ?>">
					<?php BSH_Icons::e( 'ok' === $bsh_check[0] ? 'check' : ( 'warn' === $bsh_check[0] ? 'alert' : 'error' ), 20, 'bsh-health__icon' ); ?>
					<span class="bsh-health__text">
						<strong><?php echo esc_html( $bsh_check[1] ); ?></strong>
						<?php if ( $bsh_check[3] ) : ?>
							<a href="<?php echo esc_url( $bsh_check[3] ); ?>" data-bsh-nav><?php echo esc_html( $bsh_check[2] ); ?></a>
						<?php else : ?>
							<span><?php echo esc_html( $bsh_check[2] ); ?></span>
						<?php endif; ?>
					</span>
				</li>
			<?php endforeach; ?>
		</ul>
	</section>

	<section class="bsh-card">
		<div class="bsh-card__head">
			<h2 class="bsh-card__title"><?php esc_html_e( 'آخرین رویدادها', 'basalamhub' ); ?></h2>
			<a class="bsh-btn bsh-btn--ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=basalamhub-logs' ) ); ?>" data-bsh-nav><?php esc_html_e( 'همه‌ی لاگ', 'basalamhub' ); ?></a>
		</div>
		<?php if ( ! $bsh_events ) : ?>
			<p class="bsh-card__meta"><?php esc_html_e( 'هنوز رویدادی ثبت نشده.', 'basalamhub' ); ?></p>
		<?php else : ?>
			<ul class="bsh-feed">
				<?php foreach ( $bsh_events as $bsh_ev ) : ?>
					<li class="bsh-feed__item bsh-feed__item--<?php echo esc_attr( $bsh_ev->level ); ?>">
						<span class="bsh-feed__icon"><?php BSH_Icons::e( $bsh_level_icon[ $bsh_ev->level ], 16 ); ?></span>
						<span class="bsh-feed__text">
							<strong><?php echo esc_html( $bsh_ev->title ); ?></strong>
							<span><?php echo esc_html( $bsh_ev->message ); ?></span>
						</span>
						<time class="bsh-feed__time" datetime="<?php echo esc_attr( $bsh_ev->created_at ); ?>Z"><?php echo esc_html( bsh_time_ago( $bsh_ev->created_at ) ); ?></time>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</section>
</div>
