<?php
/**
 * Category mapping page.
 *
 * @package BasalamHub
 */

defined( 'ABSPATH' ) || exit;

$bsh_user   = get_current_user_id();
$bsh_failed = get_transient( 'bsh_map_errors_' . $bsh_user );
delete_transient( 'bsh_map_errors_' . $bsh_user );
$bsh_errors = $bsh_failed ? $bsh_failed['errors'] : array();
$bsh_input  = $bsh_failed ? $bsh_failed['input'] : array();

$bsh_cache    = BSH_Categories::cache();
$bsh_items    = $bsh_cache['items'];
$bsh_map      = BSH_Categories::map();
$bsh_tree     = BSH_Admin_Tools::wc_category_tree();
$bsh_attrs    = get_option( BSH_Categories::ATTR_OPTION, array() );
$bsh_unmapped = count( BSH_Categories::unmapped_terms() );
$bsh_default  = (int) BSH_Settings::get( 'default_category_id', 0 );

/** Text shown in a row's input: rejected input, else "Path (id)", else the bare id. */
$bsh_input_value = function ( $term_id ) use ( $bsh_input, $bsh_errors, $bsh_map ) {
	if ( isset( $bsh_errors[ $term_id ], $bsh_input[ $term_id ]['category_id'] ) ) {
		return (string) $bsh_input[ $term_id ]['category_id'];
	}
	if ( ! isset( $bsh_map[ $term_id ] ) ) {
		return '';
	}
	$id  = (int) $bsh_map[ $term_id ]['category_id'];
	$cat = BSH_Categories::find( $id );
	return $cat ? $cat['path'] . ' (' . $id . ')' : (string) $id;
};

/** Nearest mapped ancestor of a term, for the "inherited" hint. */
$bsh_inherited = function ( $term_id ) use ( $bsh_map ) {
	foreach ( get_ancestors( $term_id, 'product_cat', 'taxonomy' ) as $ancestor ) {
		if ( isset( $bsh_map[ $ancestor ] ) ) {
			$t = get_term( $ancestor, 'product_cat' );
			return $t && ! is_wp_error( $t ) ? $t->name : '';
		}
	}
	return '';
};
?>
<header class="bsh-page-head">
	<h1 class="bsh-page-title"><?php esc_html_e( 'نگاشت دسته‌ها', 'basalamhub' ); ?></h1>
	<p class="bsh-card__meta"><?php esc_html_e( 'برای هر دسته‌ی ووکامرس یک بار دسته‌ی باسلام را انتخاب کن؛ برای همه‌ی محصولات آن دسته و زیردسته‌هایش اعمال می‌شود.', 'basalamhub' ); ?></p>
</header>

<?php BSH_Admin::print_notice(); ?>

<section class="bsh-card bsh-section">
	<div class="bsh-card__head">
		<h2 class="bsh-card__title"><?php esc_html_e( 'فهرست دسته‌های باسلام', 'basalamhub' ); ?></h2>
		<?php echo $bsh_items ? bsh_badge( 'synced' ) : bsh_badge( 'stale' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</div>
	<p class="bsh-card__meta">
		<?php
		echo esc_html(
			$bsh_items
				/* translators: 1: count, 2: relative time */
				? sprintf( __( '%1$s دسته · دریافت‌شده %2$s', 'basalamhub' ), bsh_fa_number( count( $bsh_items ) ), bsh_time_ago( $bsh_cache['fetched_at'] ) )
				: __( 'هنوز دریافت نشده. برای جستجوی دسته با نام، فهرست را از باسلام بگیر.', 'basalamhub' )
		);
		?>
	</p>
	<div class="bsh-card__foot">
		<button type="button" class="bsh-btn" data-bsh-cat-refresh <?php disabled( ! BSH_Settings::is_connected() ); ?>>
			<span class="dashicons dashicons-update" aria-hidden="true"></span>
			<?php echo $bsh_items ? esc_html__( 'به‌روزرسانی فهرست', 'basalamhub' ) : esc_html__( 'دریافت فهرست از باسلام', 'basalamhub' ); ?>
		</button>
	</div>
	<p class="bsh-field__hint" data-bsh-cat-message aria-live="polite"></p>
</section>

<?php if ( $bsh_unmapped ) : ?>
	<p class="bsh-alert bsh-alert--warning">
		<?php
		echo esc_html(
			$bsh_default
				/* translators: %s: count */
				? sprintf( __( '%s دسته‌ی دارای محصول نگاشت نشده‌اند؛ محصولاتشان با دسته‌ی پیش‌فرض تنظیمات ارسال می‌شوند.', 'basalamhub' ), bsh_fa_number( $bsh_unmapped ) )
				/* translators: %s: count */
				: sprintf( __( '%s دسته‌ی دارای محصول نگاشت نشده‌اند؛ محصولاتشان ارسال نمی‌شوند و دلیلش در لاگ می‌آید.', 'basalamhub' ), bsh_fa_number( $bsh_unmapped ) )
		);
		?>
	</p>
<?php endif; ?>

<?php if ( ! $bsh_tree ) : ?>
	<div class="bsh-card"><p class="bsh-card__body"><?php esc_html_e( 'فروشگاه هنوز دسته‌بندی محصول ندارد.', 'basalamhub' ); ?></p></div>
<?php else : ?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<?php wp_nonce_field( 'bsh_save_category_map' ); ?>
	<input type="hidden" name="action" value="bsh_save_category_map">

	<?php if ( $bsh_items ) : ?>
		<datalist id="bsh-basalam-categories">
			<?php foreach ( $bsh_items as $bsh_id => $bsh_cat ) : ?>
				<?php if ( $bsh_cat['leaf'] ) : ?>
					<option value="<?php echo esc_attr( $bsh_cat['path'] . ' (' . $bsh_id . ')' ); ?>"></option>
				<?php endif; ?>
			<?php endforeach; ?>
		</datalist>
	<?php endif; ?>

	<div class="bsh-table-wrap">
	<table class="bsh-table bsh-map-table">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'دسته‌ی ووکامرس', 'basalamhub' ); ?></th>
				<th scope="col"><?php esc_html_e( 'دسته‌ی باسلام', 'basalamhub' ); ?></th>
				<th scope="col"><?php esc_html_e( 'وضعیت', 'basalamhub' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $bsh_tree as $bsh_row ) : ?>
			<?php
			list( $bsh_term, $bsh_depth ) = $bsh_row;
			$bsh_tid                      = (int) $bsh_term->term_id;
			$bsh_mapped                   = isset( $bsh_map[ $bsh_tid ] );
			$bsh_parent                   = $bsh_mapped ? '' : $bsh_inherited( $bsh_tid );
			$bsh_cat_id                   = $bsh_mapped ? (int) $bsh_map[ $bsh_tid ]['category_id'] : 0;
			$bsh_cached                   = $bsh_cat_id && isset( $bsh_attrs[ $bsh_cat_id ] ) ? $bsh_attrs[ $bsh_cat_id ]['attrs'] : null;
			$bsh_err                      = isset( $bsh_errors[ $bsh_tid ] ) ? $bsh_errors[ $bsh_tid ] : '';
			?>
			<tr data-term="<?php echo esc_attr( $bsh_tid ); ?>">
				<td>
					<span class="bsh-map-name" style="padding-inline-start:<?php echo esc_attr( 16 * $bsh_depth ); ?>px"><?php echo esc_html( $bsh_term->name ); ?></span>
					<?php /* translators: %s: product count */ ?>
					<span class="bsh-table__why"><?php echo esc_html( sprintf( __( '%s محصول', 'basalamhub' ), bsh_fa_number( $bsh_term->count ) ) ); ?></span>
				</td>
				<td>
					<div class="bsh-field<?php echo $bsh_err ? ' bsh-field--error' : ''; ?>">
						<input class="bsh-field__input" type="text" name="map[<?php echo esc_attr( $bsh_tid ); ?>][category_id]" value="<?php echo esc_attr( $bsh_input_value( $bsh_tid ) ); ?>"
							<?php echo $bsh_items ? 'list="bsh-basalam-categories"' : ''; ?>
							placeholder="<?php echo esc_attr( $bsh_parent ? __( 'از دسته‌ی والد', 'basalamhub' ) : ( $bsh_items ? __( 'نام دسته را تایپ کن…', 'basalamhub' ) : __( 'شناسه‌ی دسته', 'basalamhub' ) ) ); ?>"
							aria-label="<?php /* translators: %s: category name */ echo esc_attr( sprintf( __( 'دسته‌ی باسلام برای %s', 'basalamhub' ), $bsh_term->name ) ); ?>">
						<?php if ( $bsh_err ) : ?>
							<span class="bsh-field__hint"><?php echo esc_html( $bsh_err ); ?></span>
						<?php endif; ?>
					</div>
					<div class="bsh-map-attrs" data-bsh-attrs>
						<?php
						if ( is_array( $bsh_cached ) ) {
							BSH_Admin_Tools::attribute_fields( $bsh_tid, $bsh_cached, $bsh_map[ $bsh_tid ]['attrs'] );
						} elseif ( $bsh_cat_id ) {
							echo '<button type="button" class="bsh-btn bsh-btn--ghost" data-bsh-load-attrs>' . esc_html__( 'بررسی ویژگی‌های اجباری', 'basalamhub' ) . '</button>';
						}
						?>
					</div>
				</td>
				<td>
					<?php
					if ( $bsh_mapped ) {
						echo '<span class="bsh-badge bsh-badge--synced">' . esc_html__( 'نگاشت شده', 'basalamhub' ) . '</span>';
					} elseif ( $bsh_parent ) {
						/* translators: %s: parent category */
						echo '<span class="bsh-table__why">' . esc_html( sprintf( __( 'از «%s»', 'basalamhub' ), $bsh_parent ) ) . '</span>';
					} elseif ( $bsh_term->count ) {
						echo '<span class="bsh-badge bsh-badge--stale">' . esc_html__( 'نگاشت نشده', 'basalamhub' ) . '</span>';
					} else {
						echo '<span class="bsh-muted">—</span>';
					}
					?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	</div>
	<div class="bsh-form-actions">
		<button type="submit" class="bsh-btn bsh-btn--primary"><?php esc_html_e( 'ذخیره‌ی نگاشت', 'basalamhub' ); ?></button>
		<span class="bsh-field__hint"><?php esc_html_e( 'برای حذف نگاشت یک دسته، کادرش را خالی کن.', 'basalamhub' ); ?></span>
	</div>
</form>
<?php endif; ?>
