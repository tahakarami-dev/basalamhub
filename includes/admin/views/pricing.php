<?php
/**
 * Price rules page.
 *
 * @package BasalamHub
 */

defined( 'ABSPATH' ) || exit;

$bsh_user   = get_current_user_id();
$bsh_failed = get_transient( 'bsh_price_errors_' . $bsh_user );
delete_transient( 'bsh_price_errors_' . $bsh_user );
$bsh_errors = $bsh_failed ? $bsh_failed['errors'] : array();
$bsh_rules  = BSH_Price_Rules::get();
$bsh_tree   = BSH_Admin_Tools::wc_category_tree();

/**
 * Renders the three controls of one rule.
 */
$bsh_rule_fields = function ( $name, array $rule, $error ) {
	?>
	<div class="bsh-rule<?php echo $error ? ' bsh-field--error' : ''; ?>">
		<select class="bsh-field__select" name="<?php echo esc_attr( $name ); ?>[type]" aria-label="<?php esc_attr_e( 'نوع تغییر', 'basalamhub' ); ?>">
			<option value="none" <?php selected( $rule['type'], 'none' ); ?>><?php esc_html_e( 'بدون تغییر', 'basalamhub' ); ?></option>
			<option value="percent" <?php selected( $rule['type'], 'percent' ); ?>><?php esc_html_e( 'درصدی', 'basalamhub' ); ?></option>
			<option value="fixed" <?php selected( $rule['type'], 'fixed' ); ?>><?php esc_html_e( 'مبلغ ثابت (تومان)', 'basalamhub' ); ?></option>
		</select>
		<select class="bsh-field__select" name="<?php echo esc_attr( $name ); ?>[direction]" aria-label="<?php esc_attr_e( 'جهت', 'basalamhub' ); ?>">
			<option value="up" <?php selected( $rule['direction'], 'up' ); ?>><?php esc_html_e( 'افزایش', 'basalamhub' ); ?></option>
			<option value="down" <?php selected( $rule['direction'], 'down' ); ?>><?php esc_html_e( 'کاهش', 'basalamhub' ); ?></option>
		</select>
		<input class="bsh-field__input" type="text" inputmode="decimal" name="<?php echo esc_attr( $name ); ?>[value]" value="<?php echo esc_attr( $rule['value'] ? $rule['value'] : '' ); ?>" aria-label="<?php esc_attr_e( 'مقدار', 'basalamhub' ); ?>" placeholder="<?php esc_attr_e( 'مقدار', 'basalamhub' ); ?>">
		<?php if ( $error ) : ?>
			<span class="bsh-field__hint"><?php echo esc_html( $error ); ?></span>
		<?php endif; ?>
	</div>
	<?php
};

// Preview: the 8 most recent published simple products (a variable product's price is per variant).
$bsh_preview = wc_get_products( array( 'status' => 'publish', 'type' => 'simple', 'limit' => 8, 'orderby' => 'date', 'order' => 'DESC' ) );
$bsh_mult    = BSH_Product_Mapper::rial_multiplier();
?>
<header class="bsh-page-head">
	<h1 class="bsh-page-title"><?php esc_html_e( 'قوانین قیمت', 'basalamhub' ); ?></h1>
	<p class="bsh-card__meta"><?php esc_html_e( 'قیمت محصول در باسلام می‌تواند با سایت فرق داشته باشد؛ مثلاً برای پوشش کارمزد باسلام. قیمت سایت دست نمی‌خورد.', 'basalamhub' ); ?></p>
</header>

<?php BSH_Admin::print_notice(); ?>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<?php wp_nonce_field( 'bsh_save_price_rules' ); ?>
	<input type="hidden" name="action" value="bsh_save_price_rules">

	<section class="bsh-card bsh-section">
		<div class="bsh-card__head"><h2 class="bsh-card__title"><?php esc_html_e( 'قانون سراسری', 'basalamhub' ); ?></h2></div>
		<p class="bsh-card__meta"><?php esc_html_e( 'روی همه‌ی محصولات اعمال می‌شود، مگر دسته‌ای که قانون جدا دارد.', 'basalamhub' ); ?></p>
		<?php $bsh_rule_fields( 'global', $bsh_rules['global'], isset( $bsh_errors['global'] ) ? $bsh_errors['global'] : '' ); ?>
	</section>

	<section class="bsh-card bsh-section">
		<div class="bsh-card__head"><h2 class="bsh-card__title"><?php esc_html_e( 'گردکردن', 'basalamhub' ); ?></h2></div>
		<div class="bsh-rule">
			<select class="bsh-field__select" name="rounding[unit]" aria-label="<?php esc_attr_e( 'واحد گردکردن', 'basalamhub' ); ?>">
				<option value="0" <?php selected( (int) $bsh_rules['rounding']['unit'], 0 ); ?>><?php esc_html_e( 'گرد نشود', 'basalamhub' ); ?></option>
				<option value="100" <?php selected( (int) $bsh_rules['rounding']['unit'], 100 ); ?>><?php esc_html_e( 'به ۱۰۰ تومان', 'basalamhub' ); ?></option>
				<option value="1000" <?php selected( (int) $bsh_rules['rounding']['unit'], 1000 ); ?>><?php esc_html_e( 'به ۱٬۰۰۰ تومان', 'basalamhub' ); ?></option>
				<option value="10000" <?php selected( (int) $bsh_rules['rounding']['unit'], 10000 ); ?>><?php esc_html_e( 'به ۱۰٬۰۰۰ تومان', 'basalamhub' ); ?></option>
			</select>
			<select class="bsh-field__select" name="rounding[mode]" aria-label="<?php esc_attr_e( 'جهت گردکردن', 'basalamhub' ); ?>">
				<option value="up" <?php selected( $bsh_rules['rounding']['mode'], 'up' ); ?>><?php esc_html_e( 'رو به بالا', 'basalamhub' ); ?></option>
				<option value="nearest" <?php selected( $bsh_rules['rounding']['mode'], 'nearest' ); ?>><?php esc_html_e( 'نزدیک‌ترین', 'basalamhub' ); ?></option>
				<option value="down" <?php selected( $bsh_rules['rounding']['mode'], 'down' ); ?>><?php esc_html_e( 'رو به پایین', 'basalamhub' ); ?></option>
			</select>
		</div>
		<p class="bsh-field__hint"><?php esc_html_e( 'بعد از اعمال قانون انجام می‌شود. قیمت صفر یا منفی در هر حالت ارسال نمی‌شود.', 'basalamhub' ); ?></p>
	</section>

	<?php if ( $bsh_tree ) : ?>
	<?php
	$bsh_names = array();
	foreach ( $bsh_tree as $bsh_row ) {
		$bsh_names[ (int) $bsh_row[0]->term_id ] = $bsh_row[0];
	}
	// Rows: categories that have a rule (or a rejected one to fix).
	$bsh_rows = array_keys( $bsh_rules['categories'] );
	foreach ( array_keys( $bsh_errors ) as $bsh_key ) {
		if ( 0 === strpos( $bsh_key, 'cat_' ) ) {
			$bsh_rows[] = (int) substr( $bsh_key, 4 );
		}
	}
	$bsh_rows = array_values( array_unique( array_filter( $bsh_rows, function ( $id ) use ( $bsh_names ) {
		return isset( $bsh_names[ $id ] );
	} ) ) );
	?>
	<section class="bsh-section">
		<div class="bsh-section__head">
			<h2 class="bsh-card__title"><?php esc_html_e( 'قانون جدا برای دسته‌ها', 'basalamhub' ); ?></h2>
		</div>
		<p class="bsh-card__meta"><?php esc_html_e( 'قانون دسته بر قانون سراسری اولویت دارد و زیردسته‌ها هم از آن پیروی می‌کنند.', 'basalamhub' ); ?></p>
		<div class="bsh-table-wrap" data-bsh-rules-wrap <?php echo $bsh_rows ? '' : 'hidden'; ?>>
		<table class="bsh-table">
			<thead><tr><th scope="col"><?php esc_html_e( 'دسته', 'basalamhub' ); ?></th><th scope="col"><?php esc_html_e( 'قانون', 'basalamhub' ); ?></th><th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'حذف', 'basalamhub' ); ?></span></th></tr></thead>
			<tbody data-bsh-rules>
			<?php foreach ( $bsh_rows as $bsh_tid ) : ?>
				<tr data-term="<?php echo esc_attr( $bsh_tid ); ?>">
					<td><?php echo esc_html( $bsh_names[ $bsh_tid ]->name ); ?></td>
					<td><?php $bsh_rule_fields( 'categories[' . $bsh_tid . ']', isset( $bsh_rules['categories'][ $bsh_tid ] ) ? $bsh_rules['categories'][ $bsh_tid ] : BSH_Price_Rules::empty_rule(), isset( $bsh_errors[ 'cat_' . $bsh_tid ] ) ? $bsh_errors[ 'cat_' . $bsh_tid ] : '' ); ?></td>
					<td><button type="button" class="bsh-btn bsh-btn--ghost" data-bsh-rule-remove><?php esc_html_e( 'حذف', 'basalamhub' ); ?></button></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<div class="bsh-rule bsh-rule-add">
			<select class="bsh-field__select" data-bsh-rule-term aria-label="<?php esc_attr_e( 'دسته', 'basalamhub' ); ?>">
				<option value=""><?php esc_html_e( 'یک دسته انتخاب کن…', 'basalamhub' ); ?></option>
				<?php foreach ( $bsh_tree as $bsh_row ) : ?>
					<option value="<?php echo esc_attr( $bsh_row[0]->term_id ); ?>" data-name="<?php echo esc_attr( $bsh_row[0]->name ); ?>" <?php disabled( in_array( (int) $bsh_row[0]->term_id, $bsh_rows, true ) ); ?>><?php echo esc_html( str_repeat( '— ', $bsh_row[1] ) . $bsh_row[0]->name ); ?></option>
				<?php endforeach; ?>
			</select>
			<button type="button" class="bsh-btn" data-bsh-rule-add><?php esc_html_e( 'افزودن قانون برای این دسته', 'basalamhub' ); ?></button>
		</div>
		<template data-bsh-rule-template>
			<tr data-term="__TERM__">
				<td>__NAME__</td>
				<td><?php $bsh_rule_fields( 'categories[__TERM__]', array( 'type' => 'percent', 'direction' => 'up', 'value' => 0 ), '' ); ?></td>
				<td><button type="button" class="bsh-btn bsh-btn--ghost" data-bsh-rule-remove><?php esc_html_e( 'حذف', 'basalamhub' ); ?></button></td>
			</tr>
		</template>
	</section>
	<?php endif; ?>

	<div class="bsh-form-actions">
		<button type="submit" class="bsh-btn bsh-btn--primary"><?php esc_html_e( 'ذخیره‌ی قوانین', 'basalamhub' ); ?></button>
	</div>
</form>

<section class="bsh-section">
	<div class="bsh-section__head"><h2 class="bsh-card__title"><?php esc_html_e( 'پیش‌نمایش با قوانین ذخیره‌شده', 'basalamhub' ); ?></h2></div>
	<?php if ( null === $bsh_mult ) : ?>
		<p class="bsh-alert bsh-alert--error"><?php esc_html_e( 'واحد پول فروشگاه قابل تبدیل نیست؛ در تنظیمات واحد قیمت را انتخاب کن.', 'basalamhub' ); ?></p>
	<?php elseif ( ! $bsh_preview ) : ?>
		<div class="bsh-card"><p class="bsh-card__body"><?php esc_html_e( 'محصول ساده‌ی منتشرشده‌ای برای پیش‌نمایش نیست.', 'basalamhub' ); ?></p></div>
	<?php else : ?>
		<div class="bsh-table-wrap">
		<table class="bsh-table">
			<thead><tr>
				<th scope="col"><?php esc_html_e( 'محصول', 'basalamhub' ); ?></th>
				<th scope="col"><?php esc_html_e( 'قیمت سایت', 'basalamhub' ); ?></th>
				<th scope="col"><?php esc_html_e( 'قیمت در باسلام', 'basalamhub' ); ?></th>
				<th scope="col"><?php esc_html_e( 'قانون', 'basalamhub' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $bsh_preview as $bsh_p ) : ?>
				<?php
				$bsh_base = (int) round( (float) wc_get_price_excluding_tax( $bsh_p, array( 'price' => $bsh_p->get_price() ) ) * $bsh_mult );
				$bsh_for  = BSH_Price_Rules::rule_for( $bsh_p );
				$bsh_out  = $bsh_base > 0 ? BSH_Price_Rules::apply( $bsh_base, $bsh_for['rule'], $bsh_rules['rounding'] ) : 0;
				$bsh_src  = 'category' === $bsh_for['source'] ? get_term( $bsh_for['term_id'], 'product_cat' ) : null;
				?>
				<tr>
					<td><a href="<?php echo esc_url( get_edit_post_link( $bsh_p->get_id() ) ); ?>"><?php echo esc_html( $bsh_p->get_name() ); ?></a></td>
					<td><?php echo $bsh_base > 0 ? esc_html( bsh_fa_number( $bsh_base / 10 ) . ' ' . __( 'تومان', 'basalamhub' ) ) : '—'; ?></td>
					<td>
						<?php if ( $bsh_out > 0 ) : ?>
							<strong><?php echo esc_html( bsh_fa_number( $bsh_out / 10 ) . ' ' . __( 'تومان', 'basalamhub' ) ); ?></strong>
						<?php else : ?>
							<span class="bsh-badge bsh-badge--error"><?php esc_html_e( 'ارسال نمی‌شود', 'basalamhub' ); ?></span>
						<?php endif; ?>
					</td>
					<td class="bsh-table__why">
						<?php
						echo esc_html( BSH_Price_Rules::describe( $bsh_for['rule'] ) );
						if ( $bsh_src && ! is_wp_error( $bsh_src ) ) {
							/* translators: %s: category */
							echo ' · ' . esc_html( sprintf( __( 'دسته‌ی «%s»', 'basalamhub' ), $bsh_src->name ) );
						}
						?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>
	<?php endif; ?>
</section>
