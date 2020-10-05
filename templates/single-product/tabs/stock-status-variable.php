<?php
if ( empty( $quantities ) ) {
	return;
}
?>

<?php foreach ( $quantities as $location_id => $warehouse ) : ?>

	<h3><?php esc_html_e( $warehouse['location_title'] ); ?></h3>

	<table class="shop_table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Variation', 'konekt-merit-aktiva' ); ?></th>
				<th><?php esc_html_e( 'Stock count', 'konekt-merit-aktiva' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $warehouse['products'] as $product ) : ?>

				<?php
				if ( ! $product['product']->is_in_stock() ) {
					continue;
				}
				?>

				<tr>
					<td><?php esc_html_e( $product['product']->get_attribute_summary() ); ?></td>
					<td><?php esc_html_e( wc_stock_amount( $product['quantity'] ) ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
<?php endforeach; ?>