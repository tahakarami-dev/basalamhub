<?php
/**
 * Log table partial. Expects $bsh_rows (log rows).
 *
 * @package BasalamHub
 */

defined( 'ABSPATH' ) || exit;

$bsh_level_badge = array(
	'success' => 'synced',
	'warning' => 'queued',
	'error'   => 'error',
);
?>
<div class="bsh-table-wrap">
<table class="bsh-table">
	<thead>
		<tr>
			<th scope="col"><?php esc_html_e( 'زمان', 'basalamhub' ); ?></th>
			<th scope="col"><?php esc_html_e( 'مورد', 'basalamhub' ); ?></th>
			<th scope="col"><?php esc_html_e( 'وضعیت', 'basalamhub' ); ?></th>
			<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'اقدام', 'basalamhub' ); ?></span></th>
		</tr>
	</thead>
	<tbody>
	<?php foreach ( $bsh_rows as $bsh_row ) : ?>
		<?php
		$bsh_title = $bsh_row->title;
		$bsh_edit  = 'product' === $bsh_row->object_type && $bsh_row->object_id ? get_edit_post_link( (int) $bsh_row->object_id ) : '';
		?>
		<tr id="bsh-log-<?php echo esc_attr( $bsh_row->id ); ?>" class="<?php echo $bsh_row->resolved ? 'bsh-row--resolved' : ''; ?>">
			<td class="bsh-table__time"><?php echo esc_html( bsh_format_time( $bsh_row->created_at ) ); ?></td>
			<td>
				<?php if ( $bsh_edit ) : ?>
					<a href="<?php echo esc_url( $bsh_edit ); ?>"><?php echo esc_html( $bsh_title ); ?></a>
				<?php else : ?>
					<?php echo esc_html( $bsh_title ); ?>
				<?php endif; ?>
				<span class="bsh-table__why">
					<?php echo esc_html( $bsh_row->message ); ?>
					<?php if ( $bsh_row->reason ) : ?>
						<?php echo esc_html( $bsh_row->reason ); ?>
					<?php endif; ?>
				</span>
				<?php if ( $bsh_row->suggestion && 'success' !== $bsh_row->level ) : ?>
					<span class="bsh-table__todo"><?php echo esc_html( $bsh_row->suggestion ); ?></span>
				<?php endif; ?>
				<?php if ( $bsh_row->context ) : ?>
					<details class="bsh-details">
						<summary><?php esc_html_e( 'جزئیات فنی', 'basalamhub' ); ?></summary>
						<pre dir="ltr"><?php echo esc_html( $bsh_row->context ); ?></pre>
					</details>
				<?php endif; ?>
			</td>
			<td>
				<?php
				if ( isset( $bsh_level_badge[ $bsh_row->level ] ) ) {
					echo bsh_badge( $bsh_level_badge[ $bsh_row->level ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				} else {
					echo '<span class="bsh-muted">' . esc_html__( 'اطلاع', 'basalamhub' ) . '</span>';
				}
				if ( $bsh_row->resolved && 'error' === $bsh_row->level ) {
					echo '<span class="bsh-table__resolved">' . esc_html__( 'برطرف شد', 'basalamhub' ) . '</span>';
				}
				?>
			</td>
			<td>
				<?php if ( 'error' === $bsh_row->level && ! $bsh_row->resolved && $bsh_row->retry_hook ) : ?>
					<button type="button" class="bsh-btn bsh-btn--ghost" data-bsh-retry="<?php echo esc_attr( $bsh_row->id ); ?>"><?php esc_html_e( 'تلاش مجدد', 'basalamhub' ); ?></button>
				<?php endif; ?>
			</td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
</div>
