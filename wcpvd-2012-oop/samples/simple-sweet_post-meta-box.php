<?php
function my_sweet_meta_box( $post ) { ?>
	<label>
		<input type="checkbox" value="1" name="sweet_post"
			<?php checked( get_post_meta( $post->ID, 'sweet_post', true ) ) ?> />
		This post is sweet.
	</label>

	<?php wp_nonce_field( 'sweet_post_nonce', 'sweet_post_nonce' );
}

function my_add_meta_boxes() {
	add_meta_box( 'sweet-post-meta-box', 'Sweet Post?', 'my_sweet_meta_box', 'post', 'side' );
}
add_action( 'add_meta_boxes', 'my_add_meta_boxes' );

function my_sweet_post_save_post( $post_id ) {
	if( !isset( $_POST['sweet_post_nonce'] ) || 
		!wp_verify_nonce( $_POST['sweet_post_nonce'], 'sweet_post_nonce' ) )
		return;

	if( isset( $_POST['sweet_post'] ) && $_POST['sweet_post'] )
		update_post_meta( $post_id, 'sweet_post', true );
	else
		delete_post_meta( $post_id, 'sweet_post' );
}
add_action( 'save_post', 'my_sweet_post_save_post' );