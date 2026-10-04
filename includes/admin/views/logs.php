<?php
/**
 * Log center page.
 *
 * @package BasalamHub
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
$bsh_filters = array(
	'level'       => isset( $_GET['level'] ) ? sanitize_key( wp_unslash( $_GET['level'] ) ) : '',
	'object_id'   => isset( $_GET['object_id'] ) ? absint( $_GET['object_id'] ) : 0,
	'object_type' => isset( $_GET['object_type'] ) ? sanitize_key( wp_unslash( $_GET['object_type'] ) ) : '',
	'unresolved' => ! empty( $_GET['unresolved'] ),
	'search'     => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
	'page'       => isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1,
	'per_page'   => 30,
);
// phpcs:enable
$bsh_result = BSH_Logger::query( $bsh_filters );
$bsh_rows   = $bsh_result['items'];
$bsh_pages  = (int) ceil( $bsh_result['total'] / $bsh_filters['per_page'] );
$bsh_levels = array(
	''        => __( 'همه', 'basalamhub' ),
	'error'   => __( 'خطا', 'basalamhub' ),
	'warning' => __( 'تلاش دوباره', 'basalamhub' ),
	'success' => __( 'موفق', 'basalamhub' ),
	'info'    => __( 'اطلاع', 'basalamhub' ),
);
$bsh_open = BSH_Logger::count_open_errors( 24 * 30 );
?>
<header class="bsh-page-head">
	<h1 class="bsh-page-title"><?php esc_html_e( 'لاگ همگام‌سازی', 'basalamhub' ); ?></h1>
	<p class="bsh-card__meta"><?php esc_html_e( 'هر رویداد: چه چیزی، چه شد، چرا و چه کار کنی. جدیدترین بالا.', 'basalamhub' ); ?></p>
</header>

<form class="bsh-toolbar" method="get">
	<input type="hidden" name="page" value="basalamhub-logs">
	<?php if ( $bsh_filters['object_type'] ) : ?>
		<input type="hidden" name="object_type" value="<?php echo esc_attr( $bsh_filters['object_type'] ); ?>">
	<?php endif; ?>
	<?php if ( $bsh_filters['object_id'] ) : ?>
		<input type="hidden" name="object_id" value="<?php echo esc_attr( $bsh_filters['object_id'] ); ?>">
	<?php endif; ?>
	<label class="bsh-field bsh-field--inline">
		<span class="bsh-field__label"><?php esc_html_e( 'نوع', 'basalamhub' ); ?></span>
		<select class="bsh-field__select" name="level">
			<?php foreach ( $bsh_levels as $bsh_key => $bsh_label ) : ?>
				<option value="<?php echo esc_attr( $bsh_key ); ?>" <?php selected( $bsh_filters['level'], $bsh_key ); ?>><?php echo esc_html( $bsh_label ); ?></option>
			<?php endforeach; ?>
		</select>
	</label>
	<label class="bsh-field bsh-field--inline">
		<span class="bsh-field__label"><?php esc_html_e( 'جستجو', 'basalamhub' ); ?></span>
		<input class="bsh-field__input" type="search" name="s" value="<?php echo esc_attr( $bsh_filters['search'] ); ?>" placeholder="<?php esc_attr_e( 'نام محصول یا متن خطا', 'basalamhub' ); ?>">
	</label>
	<label class="bsh-checkbox">
		<input type="checkbox" name="unresolved" value="1" <?php checked( $bsh_filters['unresolved'] ); ?>>
		<?php esc_html_e( 'فقط موارد حل‌نشده', 'basalamhub' ); ?>
	</label>
	<button class="bsh-btn" type="submit"><?php esc_html_e( 'اعمال فیلتر', 'basalamhub' ); ?></button>
	<span class="bsh-toolbar__spacer"></span>
	<?php if ( $bsh_open ) : ?>
		<button type="button" class="bsh-btn bsh-btn--primary" data-bsh-retry-all>
			<?php
			/* translators: %s: count */
			echo esc_html( sprintf( __( 'تلاش مجدد همه‌ی خطاها (%s)', 'basalamhub' ), bsh_fa_digits( $bsh_open ) ) );
			?>
		</button>
	<?php endif; ?>
</form>
<p class="bsh-field__hint" data-bsh-message aria-live="polite"></p>

<?php if ( $bsh_filters['object_id'] ) : ?>
	<p class="bsh-card__meta">
		<?php
		/* translators: %s: product name */
		echo esc_html( sprintf( __( 'فقط رویدادهای «%s»', 'basalamhub' ), get_the_title( $bsh_filters['object_id'] ) ) );
		?>
		· <a href="<?php echo esc_url( admin_url( 'admin.php?page=basalamhub-logs' ) ); ?>"><?php esc_html_e( 'نمایش همه', 'basalamhub' ); ?></a>
	</p>
<?php endif; ?>

<?php if ( ! $bsh_rows ) : ?>
	<div class="bsh-card"><p class="bsh-card__body"><?php esc_html_e( 'رویدادی با این فیلتر پیدا نشد.', 'basalamhub' ); ?></p></div>
<?php else : ?>
	<?php include __DIR__ . '/log-table.php'; ?>
	<?php if ( $bsh_pages > 1 ) : ?>
		<nav class="bsh-pagination" aria-label="<?php esc_attr_e( 'صفحه‌بندی لاگ', 'basalamhub' ); ?>">
			<?php
			echo wp_kses_post(
				paginate_links(
					array(
						'base'      => add_query_arg( 'paged', '%#%' ),
						'format'    => '',
						'current'   => $bsh_filters['page'],
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
