<?php
/**
 * Category mapping page.
 *
 * @package SalamHub
 */

defined( 'ABSPATH' ) || exit;

$slh_user   = get_current_user_id();
$slh_failed = get_transient( 'slh_map_errors_' . $slh_user );
delete_transient( 'slh_map_errors_' . $slh_user );
$slh_errors = $slh_failed ? $slh_failed['errors'] : array();
$slh_input  = $slh_failed ? $slh_failed['input'] : array();

$slh_cache    = SLH_Categories::cache();
$slh_items    = $slh_cache['items'];
$slh_map      = SLH_Categories::map();
$slh_tree     = SLH_Admin_Tools::wc_category_tree();
$slh_attrs    = get_option( SLH_Categories::ATTR_OPTION, array() );
$slh_unmapped = count( SLH_Categories::unmapped_terms() );
$slh_default  = (int) SLH_Settings::get( 'default_category_id', 0 );

/** Text shown in a row's input: rejected input, else "Path (id)", else the bare id. */
$slh_input_value = function ( $term_id ) use ( $slh_input, $slh_errors, $slh_map ) {
	if ( isset( $slh_errors[ $term_id ], $slh_input[ $term_id ]['category_id'] ) ) {
		return (string) $slh_input[ $term_id ]['category_id'];
	}
	if ( ! isset( $slh_map[ $term_id ] ) ) {
		return '';
	}
	$id  = (int) $slh_map[ $term_id ]['category_id'];
	$cat = SLH_Categories::find( $id );
	return $cat ? $cat['path'] . ' (' . $id . ')' : (string) $id;
};

/** Nearest mapped ancestor of a term, for the "inherited" hint. */
$slh_inherited = function ( $term_id ) use ( $slh_map ) {
	foreach ( get_ancestors( $term_id, 'product_cat', 'taxonomy' ) as $ancestor ) {
		if ( isset( $slh_map[ $ancestor ] ) ) {
			$t = get_term( $ancestor, 'product_cat' );
			return $t && ! is_wp_error( $t ) ? $t->name : '';
		}
	}
	return '';
};
?>
<header class="slh-page-head">
	<h1 class="slh-page-title"><?php esc_html_e( 'نگاشت دسته‌ها', 'salamhub' ); ?></h1>
	<p class="slh-card__meta"><?php esc_html_e( 'برای هر دسته‌ی ووکامرس یک بار دسته‌ی باسلام را انتخاب کن؛ برای همه‌ی محصولات آن دسته و زیردسته‌هایش اعمال می‌شود.', 'salamhub' ); ?></p>
</header>

<?php SLH_Admin::print_notice(); ?>

<section class="slh-card slh-section">
	<div class="slh-card__head">
		<h2 class="slh-card__title"><?php esc_html_e( 'فهرست دسته‌های باسلام', 'salamhub' ); ?></h2>
		<?php echo $slh_items ? slh_badge( 'synced' ) : slh_badge( 'stale' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</div>
	<p class="slh-card__meta">
		<?php
		echo esc_html(
			$slh_items
				/* translators: 1: count, 2: relative time */
				? sprintf( __( '%1$s دسته · دریافت‌شده %2$s', 'salamhub' ), slh_fa_number( count( $slh_items ) ), slh_time_ago( $slh_cache['fetched_at'] ) )
				: __( 'هنوز دریافت نشده. برای جستجوی دسته با نام، فهرست را از باسلام بگیر.', 'salamhub' )
		);
		?>
	</p>
	<div class="slh-card__foot">
		<button type="button" class="slh-btn" data-slh-cat-refresh <?php disabled( ! SLH_Settings::is_connected() ); ?>>
			<span class="dashicons dashicons-update" aria-hidden="true"></span>
			<?php echo $slh_items ? esc_html__( 'به‌روزرسانی فهرست', 'salamhub' ) : esc_html__( 'دریافت فهرست از باسلام', 'salamhub' ); ?>
		</button>
	</div>
	<p class="slh-field__hint" data-slh-cat-message aria-live="polite"></p>
</section>

<?php if ( $slh_unmapped ) : ?>
	<p class="slh-alert slh-alert--warning">
		<?php
		echo esc_html(
			$slh_default
				/* translators: %s: count */
				? sprintf( __( '%s دسته‌ی دارای محصول نگاشت نشده‌اند؛ محصولاتشان با دسته‌ی پیش‌فرض تنظیمات ارسال می‌شوند.', 'salamhub' ), slh_fa_number( $slh_unmapped ) )
				/* translators: %s: count */
				: sprintf( __( '%s دسته‌ی دارای محصول نگاشت نشده‌اند؛ محصولاتشان ارسال نمی‌شوند و دلیلش در لاگ می‌آید.', 'salamhub' ), slh_fa_number( $slh_unmapped ) )
		);
		?>
	</p>
<?php endif; ?>

<?php if ( ! $slh_tree ) : ?>
	<div class="slh-card"><p class="slh-card__body"><?php esc_html_e( 'فروشگاه هنوز دسته‌بندی محصول ندارد.', 'salamhub' ); ?></p></div>
<?php else : ?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<?php wp_nonce_field( 'slh_save_category_map' ); ?>
	<input type="hidden" name="action" value="slh_save_category_map">

	<?php if ( $slh_items ) : ?>
		<datalist id="slh-basalam-categories">
			<?php foreach ( $slh_items as $slh_id => $slh_cat ) : ?>
				<?php if ( $slh_cat['leaf'] ) : ?>
					<option value="<?php echo esc_attr( $slh_cat['path'] . ' (' . $slh_id . ')' ); ?>"></option>
				<?php endif; ?>
			<?php endforeach; ?>
		</datalist>
	<?php endif; ?>

	<div class="slh-table-wrap">
	<table class="slh-table slh-map-table">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'دسته‌ی ووکامرس', 'salamhub' ); ?></th>
				<th scope="col"><?php esc_html_e( 'دسته‌ی باسلام', 'salamhub' ); ?></th>
				<th scope="col"><?php esc_html_e( 'وضعیت', 'salamhub' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $slh_tree as $slh_row ) : ?>
			<?php
			list( $slh_term, $slh_depth ) = $slh_row;
			$slh_tid     = (int) $slh_term->term_id;
			$slh_mapped  = isset( $slh_map[ $slh_tid ] );
			$slh_parent  = $slh_mapped ? '' : $slh_inherited( $slh_tid );
			$slh_cat_id  = $slh_mapped ? (int) $slh_map[ $slh_tid ]['category_id'] : 0;
			$slh_cached  = $slh_cat_id && isset( $slh_attrs[ $slh_cat_id ] ) ? $slh_attrs[ $slh_cat_id ]['attrs'] : null;
			$slh_err     = isset( $slh_errors[ $slh_tid ] ) ? $slh_errors[ $slh_tid ] : '';
			?>
			<tr data-term="<?php echo esc_attr( $slh_tid ); ?>">
				<td>
					<span class="slh-map-name" style="padding-inline-start:<?php echo esc_attr( 16 * $slh_depth ); ?>px"><?php echo esc_html( $slh_term->name ); ?></span>
					<?php /* translators: %s: product count */ ?>
					<span class="slh-table__why"><?php echo esc_html( sprintf( __( '%s محصول', 'salamhub' ), slh_fa_number( $slh_term->count ) ) ); ?></span>
				</td>
				<td>
					<div class="slh-field<?php echo $slh_err ? ' slh-field--error' : ''; ?>">
						<input class="slh-field__input" type="text" name="map[<?php echo esc_attr( $slh_tid ); ?>][category_id]" value="<?php echo esc_attr( $slh_input_value( $slh_tid ) ); ?>"
							<?php echo $slh_items ? 'list="slh-basalam-categories"' : ''; ?>
							placeholder="<?php echo esc_attr( $slh_parent ? __( 'از دسته‌ی والد', 'salamhub' ) : ( $slh_items ? __( 'نام دسته را تایپ کن…', 'salamhub' ) : __( 'شناسه‌ی دسته', 'salamhub' ) ) ); ?>"
							aria-label="<?php /* translators: %s: category name */ echo esc_attr( sprintf( __( 'دسته‌ی باسلام برای %s', 'salamhub' ), $slh_term->name ) ); ?>">
						<?php if ( $slh_err ) : ?>
							<span class="slh-field__hint"><?php echo esc_html( $slh_err ); ?></span>
						<?php endif; ?>
					</div>
					<div class="slh-map-attrs" data-slh-attrs>
						<?php
						if ( is_array( $slh_cached ) ) {
							SLH_Admin_Tools::attribute_fields( $slh_tid, $slh_cached, $slh_map[ $slh_tid ]['attrs'] );
						} elseif ( $slh_cat_id ) {
							echo '<button type="button" class="slh-btn slh-btn--ghost" data-slh-load-attrs>' . esc_html__( 'بررسی ویژگی‌های اجباری', 'salamhub' ) . '</button>';
						}
						?>
					</div>
				</td>
				<td>
					<?php
					if ( $slh_mapped ) {
						echo '<span class="slh-badge slh-badge--synced">' . esc_html__( 'نگاشت شده', 'salamhub' ) . '</span>';
					} elseif ( $slh_parent ) {
						/* translators: %s: parent category */
						echo '<span class="slh-table__why">' . esc_html( sprintf( __( 'از «%s»', 'salamhub' ), $slh_parent ) ) . '</span>';
					} elseif ( $slh_term->count ) {
						echo '<span class="slh-badge slh-badge--stale">' . esc_html__( 'نگاشت نشده', 'salamhub' ) . '</span>';
					} else {
						echo '<span class="slh-muted">—</span>';
					}
					?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	</div>
	<div class="slh-form-actions">
		<button type="submit" class="slh-btn slh-btn--primary"><?php esc_html_e( 'ذخیره‌ی نگاشت', 'salamhub' ); ?></button>
		<span class="slh-field__hint"><?php esc_html_e( 'برای حذف نگاشت یک دسته، کادرش را خالی کن.', 'salamhub' ); ?></span>
	</div>
</form>
<?php endif; ?>
