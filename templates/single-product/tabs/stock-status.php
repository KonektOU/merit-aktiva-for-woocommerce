<?php
if ( empty( $quantities ) ) {
	return;
}
?>

<table class="shop_table">
	<thead>
		<tr>
			<th><?php esc_html_e( 'Warehouse', 'wc-merit-aktiva' ); ?></th>
			<th><?php esc_html_e( 'Stock count', 'wc-merit-aktiva' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php foreach ( $quantities as $quantity ) : ?>
			<tr>
				<td><?php esc_html_e( $quantity['location_title'] ); ?></td>
				<td><?php esc_html_e( wc_stock_amount( $quantity['quantity'] ) ); ?></td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>
