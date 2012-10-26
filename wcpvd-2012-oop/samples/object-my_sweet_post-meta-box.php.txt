<?php
class My_Sweet_Meta_Box {
	function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post', array( $this, 'action_save_post' ) );
	}

	function add_meta_boxes() {
		add_meta_box( 'sweet-post-meta-box', "Sweet Post?", array( $this, 'meta_box' ),
					  'post', 'side' );
	}

	function meta_box( $post ) { ?>
		<label>
			<input type="checkbox" value="1" name="sweet_post"
				<?php checked( get_post_meta( $post->ID, 'sweet_post', true ) ) ?> />
			This post is sweet.
		</label>

		<?php wp_nonce_field( 'sweet_post_nonce', 'sweet_post_nonce' );	
	}

	function action_save_post( $post_id ) {
		if( !isset( $_POST['sweet_post_nonce'] ) || 
			!wp_verify_nonce( $_POST['sweet_post_nonce'], 'sweet_post_nonce' ) )
			return;

		if( isset( $_POST['sweet_post'] ) && $_POST['sweet_post'] )
			update_post_meta( $post_id, 'sweet_post', true );
		else
			delete_post_meta( $post_id, 'sweet_post' );
	}
}
new My_Sweet_Meta_Box;