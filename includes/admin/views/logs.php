<?php
/**
 * Log center page.
 *
 * @package SalamHub
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
$slh_filters = array(
	'level'       => isset( $_GET['level'] ) ? sanitize_key( wp_unslash( $_GET['level'] ) ) : '',
	'object_id'   => isset( $_GET['object_id'] ) ? absint( $_GET['object_id'] ) : 0,
	'object_type' => isset( $_GET['object_type'] ) ? sanitize_key( wp_unslash( $_GET['object_type'] ) ) : '',
	'unresolved' => ! empty( $_GET['unresolved'] ),
	'search'     => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
	'page'       => isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1,
	'per_page'   => 30,
);
// phpcs:enable
$slh_result = SLH_Logger::query( $slh_filters );
$slh_rows   = $slh_result['items'];
$slh_pages  = (int) ceil( $slh_result['total'] / $slh_filters['per_page'] );
$slh_levels = array(
	''        => __( 'همه', 'salamhub' ),
	'error'   => __( 'خطا', 'salamhub' ),
	'warning' => __( 'تلاش دوباره', 'salamhub' ),
	'success' => __( 'موفق', 'salamhub' ),
	'info'    => __( 'اطلاع', 'salamhub' ),
);
$slh_open = SLH_Logger::count_open_errors( 24 * 30 );
?>
<header class="slh-page-head">
	<h1 class="slh-page-title"><?php esc_html_e( 'لاگ همگام‌سازی', 'salamhub' ); ?></h1>
	<p class="slh-card__meta"><?php esc_html_e( 'هر رویداد: چه چیزی، چه شد، چرا و چه کار کنی. جدیدترین بالا.', 'salamhub' ); ?></p>
</header>

<form class="slh-toolbar" method="get">
	<input type="hidden" name="page" value="salamhub-logs">
	<?php if ( $slh_filters['object_type'] ) : ?>
		<input type="hidden" name="object_type" value="<?php echo esc_attr( $slh_filters['object_type'] ); ?>">
	<?php endif; ?>
	<?php if ( $slh_filters['object_id'] ) : ?>
		<input type="hidden" name="object_id" value="<?php echo esc_attr( $slh_filters['object_id'] ); ?>">
	<?php endif; ?>
	<label class="slh-field slh-field--inline">
		<span class="slh-field__label"><?php esc_html_e( 'نوع', 'salamhub' ); ?></span>
		<select class="slh-field__select" name="level">
			<?php foreach ( $slh_levels as $slh_key => $slh_label ) : ?>
				<option value="<?php echo esc_attr( $slh_key ); ?>" <?php selected( $slh_filters['level'], $slh_key ); ?>><?php echo esc_html( $slh_label ); ?></option>
			<?php endforeach; ?>
		</select>
	</label>
	<label class="slh-field slh-field--inline">
		<span class="slh-field__label"><?php esc_html_e( 'جستجو', 'salamhub' ); ?></span>
		<input class="slh-field__input" type="search" name="s" value="<?php echo esc_attr( $slh_filters['search'] ); ?>" placeholder="<?php esc_attr_e( 'نام محصول یا متن خطا', 'salamhub' ); ?>">
	</label>
	<label class="slh-checkbox">
		<input type="checkbox" name="unresolved" value="1" <?php checked( $slh_filters['unresolved'] ); ?>>
		<?php esc_html_e( 'فقط موارد حل‌نشده', 'salamhub' ); ?>
	</label>
	<button class="slh-btn" type="submit"><?php esc_html_e( 'اعمال فیلتر', 'salamhub' ); ?></button>
	<span class="slh-toolbar__spacer"></span>
	<?php if ( $slh_open ) : ?>
		<button type="button" class="slh-btn slh-btn--primary" data-slh-retry-all>
			<?php
			/* translators: %s: count */
			echo esc_html( sprintf( __( 'تلاش مجدد همه‌ی خطاها (%s)', 'salamhub' ), slh_fa_digits( $slh_open ) ) );
			?>
		</button>
	<?php endif; ?>
</form>
<p class="slh-field__hint" data-slh-message aria-live="polite"></p>

<?php if ( $slh_filters['object_id'] ) : ?>
	<p class="slh-card__meta">
		<?php
		/* translators: %s: product name */
		echo esc_html( sprintf( __( 'فقط رویدادهای «%s»', 'salamhub' ), get_the_title( $slh_filters['object_id'] ) ) );
		?>
		· <a href="<?php echo esc_url( admin_url( 'admin.php?page=salamhub-logs' ) ); ?>"><?php esc_html_e( 'نمایش همه', 'salamhub' ); ?></a>
	</p>
<?php endif; ?>

<?php if ( ! $slh_rows ) : ?>
	<div class="slh-card"><p class="slh-card__body"><?php esc_html_e( 'رویدادی با این فیلتر پیدا نشد.', 'salamhub' ); ?></p></div>
<?php else : ?>
	<?php include __DIR__ . '/log-table.php'; ?>
	<?php if ( $slh_pages > 1 ) : ?>
		<nav class="slh-pagination" aria-label="<?php esc_attr_e( 'صفحه‌بندی لاگ', 'salamhub' ); ?>">
			<?php
			echo wp_kses_post(
				paginate_links(
					array(
						'base'      => add_query_arg( 'paged', '%#%' ),
						'format'    => '',
						'current'   => $slh_filters['page'],
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
