<?php
/**
 * «فروش»: site vs Basalam sales, Basalam profit after commission, best sellers, low stock.
 *
 * @package BasalamHub
 */

defined( 'ABSPATH' ) || exit;

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only period switch.
$bsh_days  = isset( $_GET['days'] ) ? absint( $_GET['days'] ) : 30;
$bsh_days  = in_array( $bsh_days, array( 7, 30, 90 ), true ) ? $bsh_days : 30;
$bsh_s     = BSH_Sales::summary( $bsh_days );
$bsh_b     = $bsh_s['channels']['basalam'];
$bsh_w     = $bsh_s['channels']['site'];
$bsh_total = $bsh_b['revenue'] + $bsh_w['revenue'];
$bsh_low   = BSH_Stock_Alerts::low_items( 20 );

/** «▲ ۱۲٪ نسبت به دوره‌ی قبل» or nothing. */
$bsh_trend = function ( $now, $then ) {
	$c = BSH_Sales::change( $now, $then );
	if ( null === $c ) {
		return '';
	}
	$up = $c >= 0;
	return '<span class="bsh-trend bsh-trend--' . ( $up ? 'up' : 'down' ) . '">' . ( $up ? '▲' : '▼' ) . ' ' . esc_html( bsh_fa_number( abs( round( $c ) ) ) ) . '٪</span>';
};

// Chart axis: a clean top value (1, 2, 5 × 10^n).
$bsh_peak = 1;
foreach ( $bsh_s['series'] as $bsh_d ) {
	$bsh_peak = max( $bsh_peak, $bsh_d['basalam'] + $bsh_d['site'] );
}
$bsh_mag  = pow( 10, floor( log10( $bsh_peak ) ) );
$bsh_axis = $bsh_mag;
foreach ( array( 1, 2, 5, 10 ) as $bsh_step ) {
	if ( $bsh_step * $bsh_mag >= $bsh_peak ) {
		$bsh_axis = $bsh_step * $bsh_mag;
		break;
	}
}
$bsh_periods = array(
	7  => __( '۷ روز', 'basalamhub' ),
	30 => __( '۳۰ روز', 'basalamhub' ),
	90 => __( '۹۰ روز', 'basalamhub' ),
);
?>
<header class="bsh-page-head bsh-page-head--row">
	<div>
		<h1 class="bsh-page-title"><?php esc_html_e( 'فروش دوکاناله', 'basalamhub' ); ?></h1>
		<p class="bsh-card__meta"><?php esc_html_e( 'فروش سایت و باسلام کنار هم؛ سفارش‌های در حال انجام، تکمیل‌شده و در انتظار. لغوشده و مسترد حساب نمی‌شوند.', 'basalamhub' ); ?></p>
	</div>
	<nav class="bsh-tabs" aria-label="<?php esc_attr_e( 'بازه', 'basalamhub' ); ?>">
		<?php foreach ( $bsh_periods as $bsh_p => $bsh_label ) : ?>
			<a class="bsh-tabs__item<?php echo $bsh_p === $bsh_days ? ' is-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=basalamhub-sales&days=' . $bsh_p ) ); ?>" data-bsh-nav <?php echo $bsh_p === $bsh_days ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $bsh_label ); ?></a>
		<?php endforeach; ?>
	</nav>
</header>

<?php BSH_Admin::print_notice(); ?>

<section class="bsh-tiles bsh-section" aria-label="<?php esc_attr_e( 'خلاصه', 'basalamhub' ); ?>">
	<div class="bsh-tile">
		<span class="bsh-tile__label"><span class="bsh-legend__key bsh-legend__key--ok" aria-hidden="true"></span><?php esc_html_e( 'فروش باسلام', 'basalamhub' ); ?></span>
		<span class="bsh-tile__value"><?php echo esc_html( BSH_Sales::compact( $bsh_b['revenue'] ) ); ?></span>
		<span class="bsh-tile__meta">
			<?php /* translators: %s: orders */ echo esc_html( sprintf( __( '%s سفارش', 'basalamhub' ), bsh_fa_number( $bsh_b['orders'] ) ) ); ?>
			<?php echo $bsh_trend( $bsh_b['revenue'], $bsh_s['previous']['basalam']['revenue'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
		</span>
	</div>
	<div class="bsh-tile">
		<span class="bsh-tile__label"><span class="bsh-legend__key bsh-legend__key--err" aria-hidden="true"></span><?php esc_html_e( 'فروش سایت', 'basalamhub' ); ?></span>
		<span class="bsh-tile__value"><?php echo esc_html( BSH_Sales::compact( $bsh_w['revenue'] ) ); ?></span>
		<span class="bsh-tile__meta">
			<?php /* translators: %s: orders */ echo esc_html( sprintf( __( '%s سفارش', 'basalamhub' ), bsh_fa_number( $bsh_w['orders'] ) ) ); ?>
			<?php echo $bsh_trend( $bsh_w['revenue'], $bsh_s['previous']['site']['revenue'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
		</span>
	</div>
	<div class="bsh-tile">
		<span class="bsh-tile__label"><?php esc_html_e( 'سهم باسلام از فروش', 'basalamhub' ); ?></span>
		<span class="bsh-tile__value"><?php echo $bsh_total > 0 ? esc_html( bsh_fa_number( round( 100 * $bsh_b['revenue'] / $bsh_total ) ) . '٪' ) : '—'; ?></span>
		<span class="bsh-tile__meta">
			<?php
			/* translators: %s: average order value */
			echo esc_html( $bsh_b['orders'] ? sprintf( __( 'میانگین سفارش باسلام: %s', 'basalamhub' ), BSH_Sales::compact( $bsh_b['aov'] ) ) : __( 'هنوز سفارشی از باسلام در این بازه نیست', 'basalamhub' ) );
			?>
		</span>
		<span class="bsh-meter" aria-hidden="true"><span style="width:<?php echo esc_attr( $bsh_total > 0 ? round( 100 * $bsh_b['revenue'] / $bsh_total ) : 0 ); ?>%"></span></span>
	</div>
	<div class="bsh-tile<?php echo $bsh_s['commission'] > 0 ? '' : ' bsh-tile--muted'; ?>">
		<span class="bsh-tile__label"><?php esc_html_e( 'باسلام پس از کمیسیون', 'basalamhub' ); ?></span>
		<span class="bsh-tile__value"><?php echo $bsh_s['commission'] > 0 ? esc_html( BSH_Sales::compact( $bsh_b['net'] ) ) : '—'; ?></span>
		<span class="bsh-tile__meta">
			<?php
			echo esc_html(
				$bsh_s['commission'] > 0
					/* translators: 1: commission percent, 2: amount */
					? sprintf( __( 'کمیسیون %1$s٪ ≈ %2$s (تخمینی)', 'basalamhub' ), bsh_fa_digits( rtrim( rtrim( number_format( $bsh_s['commission'], 2, '.', '' ), '0' ), '.' ) ), BSH_Sales::compact( $bsh_b['commission'] ) )
					: __( 'درصد کمیسیون را پایین همین صفحه وارد کن', 'basalamhub' )
			);
			?>
		</span>
	</div>
</section>

<section class="bsh-card bsh-section">
	<div class="bsh-card__head">
		<div>
			<h2 class="bsh-card__title"><?php esc_html_e( 'فروش روزانه', 'basalamhub' ); ?></h2>
			<?php /* translators: 1: days, 2: total */ ?>
			<p class="bsh-card__meta"><?php echo esc_html( sprintf( __( '%1$s روز اخیر · جمع %2$s', 'basalamhub' ), bsh_fa_number( $bsh_days ), BSH_Sales::money( $bsh_total ) ) ); ?></p>
		</div>
		<ul class="bsh-legend" aria-label="<?php esc_attr_e( 'راهنمای نمودار', 'basalamhub' ); ?>">
			<li><span class="bsh-legend__key bsh-legend__key--ok" aria-hidden="true"></span><?php esc_html_e( 'باسلام', 'basalamhub' ); ?></li>
			<li><span class="bsh-legend__key bsh-legend__key--err" aria-hidden="true"></span><?php esc_html_e( 'سایت', 'basalamhub' ); ?></li>
		</ul>
	</div>
	<?php if ( $bsh_total <= 0 ) : ?>
		<div class="bsh-empty">
			<span class="dashicons dashicons-chart-bar" aria-hidden="true"></span>
			<p><?php esc_html_e( 'در این بازه فروشی ثبت نشده. با اولین سفارش (سایت یا باسلام) نمودار پر می‌شود.', 'basalamhub' ); ?></p>
		</div>
	<?php else : ?>
		<div class="bsh-chart<?php echo 90 === $bsh_days ? ' bsh-chart--dense' : ''; ?>" data-bsh-chart data-series="<?php echo esc_attr( wp_json_encode( array( array( 'ok', __( 'باسلام', 'basalamhub' ) ), array( 'err', __( 'سایت', 'basalamhub' ) ) ) ) ); ?>" role="img" aria-label="<?php esc_attr_e( 'نمودار ستونی انباشته‌ی فروش روزانه‌ی باسلام و سایت؛ جدول داده پایین نمودار است.', 'basalamhub' ); ?>">
			<div class="bsh-chart__axis" aria-hidden="true">
				<span><?php echo esc_html( BSH_Sales::compact( $bsh_axis ) ); ?></span>
				<span><?php echo esc_html( BSH_Sales::compact( $bsh_axis / 2 ) ); ?></span>
				<span>۰</span>
			</div>
			<div class="bsh-chart__plot">
				<div class="bsh-chart__grid" aria-hidden="true"><span></span><span></span><span></span></div>
				<div class="bsh-chart__cols">
					<?php
					$bsh_n = count( $bsh_s['series'] );
					foreach ( $bsh_s['series'] as $bsh_i => $bsh_d ) :
						$bsh_last = $bsh_n - 1 === $bsh_i;
						// Day labels: every day for 7/30, weekly for 90.
						$bsh_show = 90 !== $bsh_days || $bsh_last || 0 === ( $bsh_n - 1 - $bsh_i ) % 7;
						?>
						<div class="bsh-chart__col" tabindex="0"
							data-label="<?php echo esc_attr( $bsh_d['label'] ); ?>"
							data-ok="<?php echo esc_attr( BSH_Sales::money( $bsh_d['basalam'] ) ); ?>"
							data-err="<?php echo esc_attr( BSH_Sales::money( $bsh_d['site'] ) ); ?>">
							<div class="bsh-chart__stack">
								<?php if ( $bsh_d['site'] > 0 ) : ?>
									<span class="bsh-chart__bar bsh-chart__bar--err" style="height:<?php echo esc_attr( round( 100 * $bsh_d['site'] / $bsh_axis, 2 ) ); ?>%"></span>
								<?php endif; ?>
								<?php if ( $bsh_d['basalam'] > 0 ) : ?>
									<span class="bsh-chart__bar bsh-chart__bar--ok" style="height:<?php echo esc_attr( round( 100 * $bsh_d['basalam'] / $bsh_axis, 2 ) ); ?>%"></span>
								<?php endif; ?>
							</div>
							<span class="bsh-chart__day<?php echo $bsh_last ? ' is-today' : ''; ?>"><?php echo $bsh_show ? ( $bsh_last ? esc_html__( 'امروز', 'basalamhub' ) : esc_html( $bsh_d['day'] ) ) : '&nbsp;'; ?></span>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
			<div class="bsh-chart__tip" data-bsh-tip hidden></div>
		</div>
		<details class="bsh-details bsh-chart__table">
			<summary><?php esc_html_e( 'نمایش جدول داده', 'basalamhub' ); ?></summary>
			<table class="bsh-table">
				<thead><tr><th scope="col"><?php esc_html_e( 'روز', 'basalamhub' ); ?></th><th scope="col"><?php esc_html_e( 'باسلام', 'basalamhub' ); ?></th><th scope="col"><?php esc_html_e( 'سایت', 'basalamhub' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( array_reverse( $bsh_s['series'] ) as $bsh_d ) : ?>
					<tr><td><?php echo esc_html( $bsh_d['label'] ); ?></td><td><?php echo esc_html( BSH_Sales::money( $bsh_d['basalam'] ) ); ?></td><td><?php echo esc_html( BSH_Sales::money( $bsh_d['site'] ) ); ?></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</details>
	<?php endif; ?>
</section>

<div class="bsh-grid bsh-grid--halves bsh-section">
	<?php
	foreach ( array(
		'basalam' => __( 'پرفروش‌های باسلام', 'basalamhub' ),
		'site'    => __( 'پرفروش‌های سایت', 'basalamhub' ),
	) as $bsh_ch => $bsh_title ) :
		?>
		<section class="bsh-card">
			<div class="bsh-card__head"><h2 class="bsh-card__title"><?php echo esc_html( $bsh_title ); ?></h2></div>
			<?php if ( ! $bsh_s['top'][ $bsh_ch ] ) : ?>
				<p class="bsh-card__meta"><?php esc_html_e( 'در این بازه فروشی نبوده.', 'basalamhub' ); ?></p>
			<?php else : ?>
				<ol class="bsh-rank">
					<?php foreach ( $bsh_s['top'][ $bsh_ch ] as $bsh_row ) : ?>
						<li>
							<span class="bsh-rank__name">
								<?php if ( $bsh_row['id'] && get_edit_post_link( $bsh_row['id'] ) ) : ?>
									<a href="<?php echo esc_url( get_edit_post_link( $bsh_row['id'] ) ); ?>"><?php echo esc_html( $bsh_row['name'] ); ?></a>
								<?php else : ?>
									<?php echo esc_html( $bsh_row['name'] ); ?>
								<?php endif; ?>
							</span>
							<?php /* translators: %s: units */ ?>
							<span class="bsh-rank__meta"><?php echo esc_html( sprintf( __( '%s عدد', 'basalamhub' ), bsh_fa_number( $bsh_row['qty'] ) ) ); ?> · <?php echo esc_html( BSH_Sales::money( $bsh_row['revenue'] ) ); ?></span>
						</li>
					<?php endforeach; ?>
				</ol>
			<?php endif; ?>
		</section>
	<?php endforeach; ?>
</div>

<section class="bsh-card bsh-section" id="bsh-low-stock">
	<div class="bsh-card__head">
		<div>
			<h2 class="bsh-card__title"><?php esc_html_e( 'کالاهای باسلام رو به اتمام', 'basalamhub' ); ?></h2>
			<p class="bsh-card__meta"><?php esc_html_e( 'قبل از این‌که سفارشی بیاید که نتوانی بفرستی، شارژشان کن.', 'basalamhub' ); ?></p>
		</div>
		<?php echo $bsh_low ? '<span class="bsh-badge bsh-badge--stale">' . esc_html( bsh_fa_number( count( $bsh_low ) ) ) . '</span>' : '<span class="bsh-badge bsh-badge--synced">' . esc_html__( 'همه کافی‌اند', 'basalamhub' ) . '</span>'; ?>
	</div>
	<?php if ( $bsh_low ) : ?>
		<div class="bsh-table-wrap">
			<table class="bsh-table">
				<thead><tr><th scope="col"><?php esc_html_e( 'کالا', 'basalamhub' ); ?></th><th scope="col"><?php esc_html_e( 'موجودی', 'basalamhub' ); ?></th><th scope="col"><?php esc_html_e( 'حد هشدار', 'basalamhub' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $bsh_low as $bsh_item ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( $bsh_item['edit'] ); ?>"><?php echo esc_html( $bsh_item['name'] ); ?></a></td>
						<td><?php echo $bsh_item['stock'] <= 0 ? '<span class="bsh-badge bsh-badge--error">' . esc_html__( 'تمام شده', 'basalamhub' ) . '</span>' : esc_html( bsh_fa_number( $bsh_item['stock'] ) ); ?></td>
						<td><?php echo esc_html( bsh_fa_number( $bsh_item['threshold'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
	<p class="bsh-field__hint">
		<?php esc_html_e( 'حد هشدار و روشن/خاموش‌کردن هشدار در تنظیمات › موجودی دوطرفه است؛ پیام بله/تلگرام در «اعلان‌ها».', 'basalamhub' ); ?>
	</p>
</section>

<section class="bsh-card bsh-section">
	<div class="bsh-card__head"><h2 class="bsh-card__title"><?php esc_html_e( 'کمیسیون باسلام', 'basalamhub' ); ?></h2></div>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bsh-inline-actions">
		<?php wp_nonce_field( 'bsh_save_sales' ); ?>
		<input type="hidden" name="action" value="bsh_save_sales">
		<input type="hidden" name="days" value="<?php echo esc_attr( $bsh_days ); ?>">
		<label class="bsh-field bsh-field--inline">
			<span class="bsh-field__label"><?php esc_html_e( 'درصد کمیسیون', 'basalamhub' ); ?></span>
			<input class="bsh-field__input" type="text" inputmode="decimal" name="commission" value="<?php echo esc_attr( $bsh_s['commission'] > 0 ? $bsh_s['commission'] : '' ); ?>" placeholder="7.5">
		</label>
		<button type="submit" class="bsh-btn"><?php esc_html_e( 'ذخیره', 'basalamhub' ); ?></button>
	</form>
	<p class="bsh-field__hint"><?php esc_html_e( 'درصدی که باسلام از هر فروش برمی‌دارد (از قرارداد یا صورت‌حساب غرفه‌ات). روی مبلغ کالاها حساب می‌شود، نه هزینه‌ی ارسال؛ عدد سود تخمینی است.', 'basalamhub' ); ?></p>
</section>
