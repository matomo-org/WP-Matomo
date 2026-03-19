<?php

namespace WP_Piwik\Template;

class MetaBoxCustomVars extends \WP_Piwik\Template {

	public function addMetabox() {
		add_meta_box(
			'wp-piwik_post_customvars',
			__( 'Piwik Custom Variables', 'wp-piwik' ),
			array( &$this, 'showCustomvars' ),
			array( 'post', 'page', 'custom_post_type' ),
			'side',
			'default'
		);
	}

	public function showCustomvars( $obj_post, $obj_box ) {
		wp_nonce_field( basename( __FILE__ ), 'wp-piwik_post_customvars_nonce' ); ?>
			<table>
				<tr><th></th><th><?php esc_html_e( 'Name', 'wp-piwik' ); ?></th><th><?php esc_html_e( 'Value', 'wp-piwik' ); ?></th></tr>
				<?php for ( $i = 1; $i <= 5; $i++ ) { ?>
				<tr>
					<th><label for="wp-piwik_customvar1"><?php echo esc_attr( $i ); // @phpstan-ignore-line ?>: </label></th>
					<td><input class="widefat" type="text" name="wp-piwik_custom_cat<?php echo esc_attr( $i ); // @phpstan-ignore-line ?>" value="<?php echo esc_attr( get_post_meta( $obj_post->ID, 'wp-piwik_custom_cat' . $i, true ) ); ?>" size="200" /></td>
					<td><input class="widefat" type="text" name="wp-piwik_custom_val<?php echo esc_attr( $i ); // @phpstan-ignore-line ?>" value="<?php echo esc_attr( get_post_meta( $obj_post->ID, 'wp-piwik_custom_val' . $i, true ) ); ?>" size="200" /></td>
				</tr>
			<?php } ?>
			</table>
			<p><?php esc_html_e( 'Set custom variables for a page view', 'wp-piwik' ); ?>. (<a href="http://piwik.org/docs/custom-variables/"><?php esc_html_e( 'More information', 'wp-piwik' ); ?></a>.)</p>
			<?php
	}

	public function saveCustomVars( $int_id, $obj_post ) {
		// Verify the nonce before proceeding.
		$nonce = isset( $_POST['wp-piwik_post_customvars_nonce'] )
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			? wp_unslash( $_POST['wp-piwik_post_customvars_nonce'] )
			: '';
		if (
			empty( $nonce )
			|| ! wp_verify_nonce( $nonce, basename( __FILE__ ) )
		) {
			return $int_id;
		}
		// Get post type object
		$obj_post_type = get_post_type_object( $obj_post->post_type );
		// Check if the current user has permission to edit the post.
		if ( ! current_user_can( $obj_post_type->cap->edit_post, $int_id ) ) {
			return $int_id;
		}
		$ary_names = array( 'cat', 'val' );
		for ( $i = 1; $i <= 5; $i++ ) {
			for ( $j = 0; $j <= 1; $j++ ) {
				// Create key
				$str_meta_key = 'wp-piwik_custom_' . $ary_names[ $j ] . $i;
				// Get data
				$str_meta_val = isset( $_POST[ $str_meta_key ] ) ? esc_html( $str_meta_key ) : '';
				// Get the meta value of the custom field key
				$str_cur_val = get_post_meta( $int_id, $str_meta_key, true );
				if ( $str_meta_val && '' === $str_cur_val ) {
					// Add meta val:
					add_post_meta( $int_id, $str_meta_key, $str_meta_val, true );
				} elseif ( $str_meta_val && $str_meta_val !== $str_cur_val ) {
					// Update meta val:
					update_post_meta( $int_id, $str_meta_key, $str_meta_val );
				} elseif ( '' === $str_meta_val && $str_cur_val ) {
					// Delete meta val:
					delete_post_meta( $int_id, $str_meta_key, $str_cur_val );
				}
			}
		}
	}
}
