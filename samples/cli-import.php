<?php
/**
 * Harness for running the command line import tool.
 *
 * Author: Ben Doherty @ Oomph, Inc.
 * Author URI: http://oomphinc.com
 */

function usage( $message = false ) {
	$argv = $_SERVER[ 'argv' ];

	if( $message ) echo "$message\n\n";

	echo "Usage: $argv[0] -h HOSTNAME FILENAME\n\n";
	echo "Options:\n";
  echo "  -h HOSTNAME  -  Set HTTP host (REQUIRED)\n";
  echo "  -i J[,K]     -  Process starting at post J, optionally through post K\n";
  echo "  -I #[,#...]  -  Import only this comma-separated list of post IDs\n";
  echo "  -b BLOGID    -  Set to the blog ID of the blog to import into. Required only if this is a multi-site instance\n";
  echo "  -a USERNAME  -  Set default author name\n";
  echo "  -m MAPFILE   -  Use MAPFILE to determine author mapping. Simple comma-separated list, two entries per line.\n";
  echo "  -f           -  Import post attachments and media files\n";
  echo "  -v           -  Be verbose\n";
  echo "  -V			- Log Verbose\n";
	echo "  -A, -T, -P   -  Do only Authors, Terms, Posts (can be combined)\n";
	echo "  -N           -  Do nothing. Just show steps.\n";
	echo "  -l NUMBER    -  Save meta data to FILENAME.NUMBER for final cleanup\n";
	echo "  -L NUMBER    -  Perform final cleanup using NUMBER meta data pages\n";
  echo "  FILENAME     -  The path to the WXR file to import. Can be - for stdin\n";  
	exit(0);
}


if( !defined( 'IMPORT_DEBUG' ) ) define( 'IMPORT_DEBUG', true );

$options = getopt( 'ATPNa:b:fi:h:m:vl:L:I:V:' );

if( !isset( $options['h'] ) ) 
	usage();

$file = array_pop( $_SERVER['argv'] );


if( empty( $file ) )
	usage();

if( $file != '-' && !file_exists( $file ) ) 
	die( "Input file $file does not exist" );

$import_options = array( 'file' => $file, 'postIDs' => false );

if( isset( $options['a'] ) ) {
	if( !preg_match( '/^[a-z0-9]+$/', $options['a'] ) ) 
		die( "Invalid author name" );

	$import_options['default_author'] = $options['a'];
}
if( isset( $options['I'] ) )
	$import_options['postIDs'] = array_map( 'intval', explode( ',', $options['I'] ) );

if( isset( $options['m'] ) ) {
	$author_map_file = $options['m'];

	if( !file_exists( $author_map_file ) ) 
		die( "Author map file $author_map_file does not exist" );

	$import_options['author_map'] = array();
	
	if( !( $fp = fopen( $author_map_file, "r" ) ) )
		die("Could not open author map input file $author_map_file");

	while ( $line = fgets( $fp ) ) { 
		if( !preg_match( '/^([a-z0-9]+),([a-z0-9]+)$/', $line, $matches ) )
			continue;

		$import_options['author_map'][$matches[1]] = $matches[2];

	}
	
	fclose( $fp );
}

if( isset( $options['f'] ) ) {
	$import_options['fetch_attachments'] = true;
}

if( isset( $options['i'] ) ) {
	if( !preg_match( '/^(\d+)(?:,(\d+))?$/', $options['i'], $matches ) )
		usage( "Invalid start index (-i {$options['i']})" );

	$import_options['start_index'] = $matches[1];

	if( $matches[2] ) 
		$import_options['end_index'] = $matches[2];
}

if( isset( $options['v'] ) ) {
	$import_options['verbose'] = true;
}

if( isset( $options['l'] ) && (int) $options['l'] > 0 ) 
	$import_options['savepage'] = $options['l'];

if( isset( $options['L'] ) && (int) $options['L'] > 0 ) 
	$import_options['loadpages'] = $options['L'];
if( isset( $options['V'] ) && (int) $options['V'] > 0 && (int) $options['V'] < 4  ) 
	$GLOBALS['loglevel'] = $options['V'];
else $GLOBALS['loglevel'] = 3;

if( !isset( $options['A'] ) && !isset( $options['T'] ) && !isset( $options['P'] ) && !isset( $options['N'] ) ) {
	$import_options['do_authors'] = true;
	$import_options['do_terms'] = true;
	$import_options['do_posts'] = true;
}
else {
	$import_options['do_authors'] = isset( $options['A'] );
	$import_options['do_terms'] = isset( $options['T'] );
	$import_options['do_posts'] = isset( $options['P'] );
}

echo "Initializing Wordpress...\n";

$_SERVER['HTTP_HOST'] = $options['h'];
$_SERVER['REQUEST_METHOD'] = '';

set_time_limit(0);
ini_set( "memory_limit", "4000M" );
ini_set('mysql.connect_timeout', 600);
ini_set('default_socket_timeout', 600);

ob_start();
require_once( './wp-load.php' ); 
require_once( ABSPATH . 'wp-admin/includes/admin.php' );
require_once( ABSPATH . 'wp-admin/includes/import.php' );
require_once( ABSPATH . 'wp-admin/includes/class-wp-importer.php' );
echo ob_get_clean();

if( isset( $options['b'] ) ) {
	$new_blog_id = (int) $options['b'];

	if( !$new_blog_id ) 
		die( "Invalid blog ID (-b {$options['b']})\n" );

	$blog_address = get_blogaddress_by_id( (int) $new_blog_id );

	if ( $blog_address == 'http://' )
		die("Blog id $new_blog_id ($blog_address) is invalid.");

	if( !switch_to_blog( (int) $new_blog_id ) )	
		die( "Failed to switch to $blog_address" );

	echo "Importing into $blog_address ...\n";
}


if ( get_template_directory() !== get_stylesheet_directory() && file_exists( get_stylesheet_directory() . '/functions.php' ) ) {
	
	echo "Loading theme in ".get_stylesheet_directory()."... ";
	require_once( get_stylesheet_directory() . '/functions.php' );
}

if ( file_exists( get_template_directory() . '/functions.php' ) ) {
	echo "Loading parent theme in ".get_template_directory()."... ";
	require_once( get_template_directory() . '/functions.php' );
}

do_action( 'init' );

echo "Done.\n";

/* Set this to stop parsing so the entire file doesn't need to be read
 */
$GLOBALS['stop_parse'] = false;

/* Simplify fetches from XML pseudo structures generated by the below class:
 * XPath-ish, but not nearly as powerful */

/* Get the concatenation of all the character data in a node. */
function xml__value_map( $n ) { return $n["value"]; }
function xml_node_value( $obj, $path ) {
	$nodes = xml_nodes( $obj, $path.'/#CDATA' );

	return implode( '', array_map( 'xml__value_map', $nodes ) );
}

/* Return a list of nodes matching $path */
function xml_nodes( $obj, $path ) {
	if( strpos( $path, '/' ) === 0 ) $path = substr( $path, 1 );

	$components = split('/', $path);

	return xml_fetch_nodes_recursively( $obj, $components );
}

function xml_fetch_nodes_recursively( $node, $components ) {
	if( count( $components ) == 0 ) return array();
	
	$name = array_shift( $components );
	
	if( preg_match( '/^(.+)\[(\d+)\]$/', $name, $matches ) ) {
		$name = $matches[1];
		$index = $matches[2];
	}
		
	if( $node['name'] != $name ) return array();

	// Simple case. End of search tree:
	if( count( $components ) == 0 ) 
		// Return node only if index is not set or is equal to 0
		return ( !isset( $index ) || $index == 0 ) ? array( $node ) : array();

	// Continue searching. Allow wildcards. $index if set refers
	// to the n'th occurance of $name
	$results = array();

	unset( $index );
	$name = $components[0];

	if( preg_match( '/^(.+)\[(\d+)\]$/', $components[0], $matches ) ) {
		$name = $matches[1];
		$index = $matches[2];
	}

	$i = 0;
	foreach( $node['children'] as $child ) {
		if( $components[0] != '*' && $child['name'] != $name ) 
			continue;

		if( isset( $index ) && $index != $i ) 
			continue;

		if( count( $components ) > 0 )
			$results = array_merge( $results, xml_fetch_nodes_recursively( $child, $components ) );
		else
			$results[] = $child;

		$i++;
	}

	return $results;
}

class CLI_WXR_Parser {
	var $tree = array();
	var $base_url = '';
	var $callback = array();
	var $data = array();
	var $capture = null; // Reference to array in which to capture
	var $capture_stack = array();

	function parse( $file ) {
		require_once( ABSPATH . 'wp-admin/includes/admin.php' );

		$parser = xml_parser_create();

		$xml = xml_parser_create( 'UTF-8' );
		xml_parser_set_option( $xml, XML_OPTION_SKIP_WHITE, 1 );

		xml_set_element_handler( $xml, array( $this, 'tag_start' ), array( $this, 'tag_end' ) );
		xml_set_character_data_handler( $xml, array( $this, 'handle_cdata' ) );
		xml_set_default_handler( $xml, array( $this, 'handle_cdata' ) );

		if( $file == '-' ) $file = 'php://stdin';

		if( !( $fp = fopen( $file, "r" ) ) )
			die("could not open XML input file $file");

		while ($data = fread($fp, 4096)) {
			$data = str_replace('&<', '<', $data);
				if (!xml_parse($xml, $data, feof($fp))) {
						printf("XML error: %s at line %d",
											xml_error_string(xml_get_error_code($xml)),
											xml_get_current_line_number($xml));
						break;
				}

				if( $GLOBALS['stop_parse'] ) 
					break;
		}

		xml_parser_free($xml);
	}

	function set_element_callback( $element, $callback ) {
		// Upper case tag name because of default case-folding behavior (tags are uppercased by parser)
		$this->callbacks[ strtolower( $element ) ] = $callback;
	}

	function tag_start( $parser, $name, $attributes ) {
		$name = strtolower( $name );

		$this->cdata = '';
		$this->tree[] = $name;
		
		if( isset( $this->callbacks[$name] ) ) {
			$node = array( 'name' => $name, 'attr' => $attributes, 'children' => array() );
			$this->data = &$node;
			$this->capture = &$node['children'];
			$this->capture_stack = array();
		}
		else if( !is_null( $this->capture ) ) {
			$node = array( 'name' => $name, 'attr' => $attributes, 'children' => array() );
			$this->capture[] = &$node;
			$this->capture = &$node['children'];
		}
			
		if( isset( $node ) ) {
			//array_push( $this->capture_stack, &$node );
			array_push( $this->capture_stack, $node );
		}
	}

	function tag_end( $parser, $name ) {
		$name = strtolower( $name );

		if( ( $popped = array_pop( $this->tree ) ) != $name ) {
			echo "[Error] End tag $name != current tag $popped\n";
			return;
		}

		if( count( $this->capture_stack ) ) 
			array_pop( $this->capture_stack );

		if( count( $this->capture_stack ) > 0 ) 
			$this->capture = &$this->capture_stack[count( $this->capture_stack ) - 1]['children'];

		if( isset( $this->callbacks[$name] ) ) {
			call_user_func( $this->callbacks[$name], $this->data );
			$this->capture = null;
			$this->data = null;
		}

	}

	function handle_cdata( $parser, $data ) {
		if( ( trim( $data ) == '' ) || ( strpos( $data, '<!--' ) === 0 ) )
			return;

		if( !is_null( $this->capture ) ) { 
			if( count( $this->capture ) && $this->capture[count( $this->capture )-1]['name'] == '#CDATA' )
				$this->capture[count( $this->capture )-1]['value'] .= $data;
			else
				$this->capture[] = array( 'name' => '#CDATA', 'value' => $data );
		}
	}

	function xpath() {
		return '/'.implode('/', $this->tree);
	}
}


class WP_Import_CLI extends WP_Importer {
	var $max_wxr_version = 1.1; // max. supported WXR version

	var $id; // WXR attachment ID

	// information to import from WXR file
	var $version;
	var $authors = array();
	var $posts = array();
	var $terms = array();
	var $categories = array();
	var $tags = array();
	var $base_url = '';

	// Current post
	var $start_index = 0;
	var $end_index = 0;
	var $post_index = 0;

	// mappings from old information to new
	var $processed_authors = array();
	var $author_mapping = array();
	var $author_id = array();
	var $default_author = array();
	var $processed_terms = array();
	var $processed_posts = array();
	var $post_orphans = array();
	var $processed_menu_items = array();
	var $menu_item_orphans = array();
	var $missing_menu_items = array();

	// Options
	var $verbose = false;
	var $fetch_attachments = false;
	var $url_remap = array();
	var $featured_images = array();
	var $file = '';

	// Cleanup meta data
	var $loadpages = false;
	var $savepage = false;
	var $postIDs;

	function WP_Import_CLI( $options ) {
		$this->fetch_attachments = isset( $options['fetch_attachments'] );
		$this->author_mapping = isset( $options['author_map'] ) ? $options['author_map'] : array();
		$this->default_author = isset( $options['default_author'] ) ? $options['default_author'] : false;
		$this->start_index = isset( $options['start_index'] ) ? (int) $options['start_index'] : false;
		$this->end_index = isset( $options['end_index'] ) ? (int) $options['end_index'] : false;
		$this->verbose = isset( $options['verbose'] );
		$this->loadpages = isset( $options['loadpages'] ) ? (int) $options['loadpages'] : false;
		$this->savepage = isset( $options['savepage'] ) ? (int) $options['savepage'] : false;
		$this->postIDs = isset( $options['postIDs'] ) ? $options['postIDs'] : false;
		$this->file = isset( $options[ 'file' ] ) ? $options['file'] : '';
		add_filter( 'import_post_meta_key', array( $this, 'is_valid_meta_key' ) );
		add_filter( 'http_request_timeout', array( &$this, 'bump_request_timeout' ) );

		if ( ! is_file( $this->file ) ) 
			die( "No input file" );
		
		if( $this->loadpages ) {
			echo "Performing final cleanup from $this->loadpages files for $this->file...\n";
			$this->final_cleanup();
			return;
		}

		echo "Starting import from $this->file... ";
			
		if( $this->postIDs ) 
			echo "\nImporting only posts with IDs: " . implode( ', ', $this->postIDs )."\n";

		if( $this->savepage ) {
			$metafile = $this->file.'-importmeta.'.$this->savepage;
			echo "Metadata -> $metafile\n";
			if( $this->savepage > 1 )
				$this->load_metadata( $this->savepage - 1 );
		}

		$parser = $this->parser = new CLI_WXR_Parser();

		if( $options['do_authors'] ) 
			$parser->set_element_callback( 'wp:author', array( &$this, 'process_author' ) );

		if( $options['do_terms'] ) {
			$parser->set_element_callback( 'wp:term', array( &$this, 'process_term' ) );
			$parser->set_element_callback( 'wp:category', array( &$this, 'process_term' ) );
			$parser->set_element_callback( 'wp:tag', array( &$this, 'process_term' ) );
		}

		if( $options['do_posts'] )
			$parser->set_element_callback( 'item', array( &$this, 'process_post' ) );

		wp_defer_term_counting( true );
		wp_defer_comment_counting( true );
		wp_suspend_cache_invalidation( true );
		try
		{
			do_action( 'import_start' );
			$parser->parse( $this->file );
			do_action( 'import_finish' );
		}
		catch (Exception $e) {
			echo 'Caught exception: ',  $e->getMessage(), "\n";
		}

		if( isset( $metafile ) ) {
			echo "Saving meta data file to $metafile... ";
			file_put_contents( $metafile, '<?php list( $post_orphans, $missing_menu_items, $menu_item_orphans, $processed_menu_items, $url_remap, $featured_images, $processed_posts ) = '.var_export( array( $this->post_orphans, $this->missing_menu_items, $this->menu_item_orphans, $this->processed_menu_items, $this->url_remap, $this->featured_images, $this->processed_posts ) , true ).';' );
			echo "Done.\n";
		}
			
		wp_suspend_cache_invalidation( false );
		wp_cache_flush();
		foreach ( get_taxonomies() as $tax ) {
			delete_option( "{$tax}_children" );
			_get_term_hierarchy( $tax );
		}

		wp_defer_term_counting( false );
		wp_defer_comment_counting( false );

		echo "\n". __( 'All done.', 'wordpress-importer' ) . "\n" . __( 'Have fun!', 'wordpress-importer' ) . "\n";
		echo "\n". __( 'Remember to update the passwords and roles of imported users.', 'wordpress-importer' ) . "\n";

		do_action( 'import_end' );
	}

	function load_metadata( $index ) {
		$file = $this->file.'-importmeta.'.$index; 

		if( !file_exists( $file ) ) {
			echo "Import meta data file $file doesn't exist\n";
			exit();
			return;
		}

		include( $file );

		$this->post_orphans = $post_orphans;
		$this->missing_menu_items = $missing_menu_items;
		$this->menu_item_orphans = $menu_item_orphans;
		$this->processed_menu_items = $processed_menu_items;
		$this->url_remap = $url_remap;
		$this->featured_images = $featured_images;
		$this->processed_posts = $processed_posts;
	}

	// Load in all the accumulated data and make final database cleanups
	function final_cleanup( ) {
		$this->load_metadata( $this->loadpages );

		// update incorrect/missing information in the DB
		$this->backfill_parents();
		$this->backfill_attachment_urls();
		$this->remap_featured_images();
	}

	function process_author( $structure ) {
		$author = xml_node_value( $structure, '/wp:author/wp:author_login' );
		echo "Author: ".$author;

		if( isset( $this->author_mapping[$author] ) ) {
			$toauthor = $this->author_mapping[$author];
			echo " => $toauthor (mapped) ...";
		}
		else {
			$toauthor = $author;
			echo " => $toauthor (migrated) ...";
		}

		if( !username_exists( $toauthor ) ) {
			echo " user does not exist, creating... ";

			$userdata = array(
				'user_login' => $toauthor,
				'user_pass' => xml_node_value( $structure, '/wp:author/wp:author_password' ),
				'user_email' => xml_node_value( $structure, '/wp:author/wp:author_email' ),
				'first_name' =>  xml_node_value( $structure, '/wp:author/wp:author_first_name' ),
				'last_name' =>  xml_node_value( $structure, '/wp:author/wp:author_last_name' ),
				'display_name' =>  xml_node_value( $structure, '/wp:author/wp:author_display_name' )
			);


			$user = wp_insert_user( $userdata );

			if( is_wp_error( $user ) ) 
				echo " ERROR: ".$user->get_error_message();
			else
				echo " created.";
		}

		$user = get_user_by( 'login', $toauthor );

		if( $GLOBALS['blog_id'] ) {
			echo " Adding user to blog ".$GLOBALS['blog_id']."... ";
			add_user_to_blog( $GLOBALS['blog_id'], $user->ID, 'subscriber' );
		}

		$this->author_mapping[$author] = $toauthor;
		$this->author_ids[$toauthor] = $user->ID;

		if( $this->verbose ) 
			$this->showquery();

		echo "\n";
}

	
	function process_term( $structure ) {
		$term = array();

		if( $structure['name'] == 'wp:category' ) {
			$term['term_taxonomy'] = 'category';

			$map =  array( 
				'wp:term_id' => 'term_id',
				'wp:category_nicename' => 'slug',
				'wp:category_parent' => 'term_parent',
				'wp:cat_name' => 'term_name',
				'wp:category_description' => 'term_description'
			);
		}
		
		if( $structure['name'] == 'wp:tag' ) {
			$term['term_taxonomy'] = 'post_tag';

			$map = array( 
				'wp:term_id' => 'term_id',
				'wp:tag_slug' => 'slug',
				'wp:term_parent' => 'term_parent',
				'wp:tag_name' => 'term_name'
			);
		}

		if( $structure['name'] == 'wp:term' ) {
			$term['term_taxonomy'] = 'post_tag';

			$map = array( 
				'wp:term_id' => 'term_id',
				'wp:term_taxonomy' => 'term_taxonomy',
				'wp:term_slug' => 'slug',
				'wp:term_parent' => 'term_parent',
				'wp:term_name' => 'term_name'
			);
		}
		foreach( $map as $node => $key ) 
				$term[ $key ] = xml_node_value( $structure, $structure['name'].'/'.$node );

		echo "Processing term in ".$term['term_taxonomy']." '".$term['slug']."'... ";

		// if the term already exists in the correct taxonomy leave it alone
		$term_id = term_exists( $term['slug'], $term['term_taxonomy'] );
		if ( $term_id ) {
			if ( is_array($term_id) ) $term_id = $term_id['term_id'];
			if ( isset($term['term_id']) )
				$this->processed_terms[intval($term['term_id'])] = (int) $term_id;
			echo "exists\n";
			return;
		}

		echo "creating.\n";
		if ( empty( $term['term_parent'] ) ) {
			$parent = 0;
		} else {
			$parent = term_exists( $term['term_parent'], $term['term_taxonomy'] );
			if ( is_array( $parent ) ) $parent = $parent['term_id'];
		}
		$description = isset( $term['term_description'] ) ? $term['term_description'] : '';
		$termarr = array( 'slug' => $term['slug'], 'description' => $description, 'parent' => intval($parent) );

		$id = wp_insert_term( $term['term_name'], $term['term_taxonomy'], $termarr );
		if ( ! is_wp_error( $id ) ) {
			if ( isset($term['term_id']) )
				$this->processed_terms[intval($term['term_id'])] = $id['term_id'];
		} else {
			printf( __( 'Failed to import %s %s', 'wordpress-importer' ), $term['term_taxonomy'], $term['term_name'] );
			if ( defined('IMPORT_DEBUG') && IMPORT_DEBUG )
				echo ': ' . $id->get_error_message();
			echo "\n";
		}

		if( $this->verbose ) 
			$this->showquery();
	}

	function process_post( $structure ) {
		$post = array();

		$this->post_index ++;

		if( $this->post_index < $this->start_index ) {
			return;
		}

		if( $this->end_index && $this->post_index > $this->end_index )  {
			$GLOBALS['stop_parse'] = true;
			return;
		}

		// Do basic mapping
		foreach( array( 
			'wp:post_title' => 'post_title', 
			'wp:post_id' => 'post_id',
			'dc:creator' => 'post_author',
			'wp:post_date' => 'post_date',
			'wp:post_date_gmt' => 'post_date_gmt',
			'content:encoded' => 'post_content',
			'excerpt:encoded' => 'post_excerpt',
			'title' => 'post_title',
			'wp:status' => 'status',
			'wp:post_name' => 'post_name',
			'wp:ping_status' => 'ping_status',
			'wp:menu_order' => 'menu_order',
			'wp:post_type[0]' => 'post_type',
			'guid' => 'guid',
			'wp:is_sticky' => 'is_sticky',
			'wp:post_password' => 'post_password',
			'wp:post_parent' => 'post_parent',
			'wp:comment_status' => 'comment_status',
			'wp:attachment_url' => 'attachment_url' ) as $node => $key ) 
			$post[ $key ] = xml_node_value( $structure, 'item/'.$node );

		if( $this->postIDs && !in_array( $post['post_id'], $this->postIDs ) )
			return;

		$post['terms'] = array();
		foreach( xml_nodes( $structure, 'item/category' ) as $term ) {
			$post['terms'][] = array(
				'domain' => $term['attr']['DOMAIN'],
				'slug' => $term['attr']['NICENAME'],
				'name' => xml_node_value( $term, 'category' )
			);
		}	

		$post['postmeta'] = array();
		foreach( xml_nodes( $structure, 'item/wp:postmeta' ) as $postmeta ) {
			$post['postmeta'][] = array(
				'key' => xml_node_value( $postmeta, 'wp:postmeta/wp:meta_key' ),
				'value' => xml_node_value( $postmeta, 'wp:postmeta/wp:meta_value' )
			);
		}

		$post['comments'] = array();
		foreach( xml_nodes( $structure, 'item/wp:comment' ) as $comment ) {
			$item = array();

			foreach( array( 
					'wp:comment_author' => 'comment_author',
					'wp:comment_author_email' => 'comment_author_email',
					'wp:comment_author_IP' => 'comment_author_IP',
					'wp:comment_author_url' => 'comment_author_url',
					'wp:comment_date' => 'comment_date',
					'wp:comment_date_gmt' => 'comment_date_gmt',
					'wp:comment_content' => 'comment_content',
					'wp:comment_approved' => 'comment_approved',
					'wp:comment_type' => 'comment_type',
					'wp:comment_user_id' => 'comment_user_id'
				) as $node => $key ) 
				$item[$key] = xml_node_value( $comment, 'wp:comment/'.$node );

			$post['comments'][] = $item;
		}
		if($GLOBALS['loglevel'] == 3)
			echo "[$this->post_index / ".$post['post_id']."] Processing post '".$post['post_title']."' (".$post['post_date'].")... ";
		else 
			echo "[$this->post_index / ".$post['post_id']."] Processing... ";
			
		if ( ! post_type_exists( $post['post_type'] ) ) {
			printf( __( 'Failed to import "%s": Invalid post type %s', 'wordpress-importer' ),
				esc_html($post['post_title']), esc_html($post['post_type']) );
			echo "\n";
			return;
		}

		if ( isset( $this->processed_posts[$post['post_id']] ) ) {
			echo "Post has already been processed.\n";
			return;
		}

		if ( $post['status'] == 'auto-draft' )
			return;

		if ( 'nav_menu_item' == $post['post_type'] ) {
			echo " is a menu item. Processing...\n";
			$this->process_menu_item( $post );
			return;
		}

		$post_type_object = get_post_type_object( $post['post_type'] );

		$post_exists = post_exists( $post['post_title'], '', $post['post_date'] );
		if ( $post_exists ) {
			printf( __('%s already exists.', 'wordpress-importer'), $post_type_object->labels->singular_name, $post['post_title'] );
			$comment_post_ID = $post_id = $post_exists;
		} else {
			if($GLOBALS['loglevel'] == 3)
				echo " Does not exist. Creating...";
			else echo " Creating...";
			$post_parent = (int) $post['post_parent'];
			if ( $post_parent ) {
				// if we already know the parent, map it to the new local ID
				if ( isset( $this->processed_posts[$post_parent] ) ) {
					$post_parent = $this->processed_posts[$post_parent];
				// otherwise record the parent for later
				} else {
					echo " saving parent relationship (".$post['post_id']." -> ".$post_parent.")...";
					$this->post_orphans[intval($post['post_id'])] = $post_parent;
					$post_parent = 0;
				}
			}

			// map the post author
			$author = sanitize_user( $post['post_author'], true );
				
			if( !$author )
				$author = $this->default_author;

			if( !isset( $this->author_mapping[$author] ) )	
				$this->author_mapping[$author] = $author;

			$author = isset( $this->author_mapping[$author] ) ? $this->author_mapping[$author] : $this->default_author;

			if( !$author )
				$author = $this->default_author;
			if($GLOBALS['loglevel'] == 3)
				echo " author=$author... ";
			if( isset( $this->author_ids[$author] ) ) {
				$author_id = $this->author_ids[$author];
			}
			else {
				if($GLOBALS['loglevel'] == 3)
					echo " Author ID for $author unknown... looking up...";
				$user = get_user_by( 'login', $author );
				if( !$user ) {
					if($GLOBALS['loglevel'] == 3)
						echo " could not determine user ID for $author.  author will be set.";
				}

				$author_id = $this->author_ids[$author] = $user && !is_wp_error( $user ) ? $user->ID : '';
			}

			$postdata = array(
				'import_id' => $post['post_id'], 'post_author' => $author_id, 'post_date' => $post['post_date'],
				'post_date_gmt' => $post['post_date_gmt'], 'post_content' => $post['post_content'],
				'post_excerpt' => $post['post_excerpt'], 'post_title' => $post['post_title'],
				'post_status' => $post['status'], 'post_name' => $post['post_name'],
				'comment_status' => $post['comment_status'], 'ping_status' => $post['ping_status'],
				'guid' => $post['guid'], 'post_parent' => $post_parent, 'menu_order' => $post['menu_order'],
				'post_type' => $post['post_type'], 'post_password' => $post['post_password']
			);

			if ( 'attachment' == $postdata['post_type'] ) {
				$remote_url = ! empty($post['attachment_url']) ? $post['attachment_url'] : $post['guid'];
				if($GLOBALS['loglevel'] == 3)
					echo " processing attachment at $remote_url... ";
				else echo " processing: $remote_url... ";
				// try to use _wp_attached file for upload folder placement to ensure the same location as the export site
				// e.g. location is 2003/05/image.jpg but the attachment post_date is 2010/09, see media_handle_upload()
				$postdata['upload_date'] = $post['post_date'];
				if ( isset( $post['postmeta'] ) ) {
					foreach( $post['postmeta'] as $meta ) {
						if ( $meta['key'] == '_wp_attached_file' ) {
							if ( preg_match( '%^[0-9]{4}/[0-9]{2}%', $meta['value'], $matches ) )
								$postdata['upload_date'] = $matches[0];
							break;
						}
					}
				}

				$comment_post_ID = $post_id = $this->process_attachment( $postdata, $remote_url );
			} else {
				$comment_post_ID = $post_id = wp_insert_post( $postdata, true );
			}

			if ( is_wp_error( $post_id ) ) {
				printf( __( 'Failed to import %s "%s"', 'wordpress-importer' ),
					$post_type_object->labels->singular_name, esc_html($post['post_title']) );
				if ( defined('IMPORT_DEBUG') && IMPORT_DEBUG )
					echo ': ' . $post_id->get_error_message();
				echo "\n";
				return;
			}

			if ( $post['is_sticky'] == 1 )
				stick_post( $post_id );
		}

		// map pre-import ID to local ID
		$this->processed_posts[intval($post['post_id'])] = (int) $post_id;

		// add categories, tags and other terms
		if ( ! empty( $post['terms'] ) ) {
			$terms_to_set = array();
			foreach ( $post['terms'] as $term ) {
				// back compat with WXR 1.0 map 'tag' to 'post_tag'
				$taxonomy = ( 'tag' == $term['domain'] ) ? 'post_tag' : $term['domain'];
				$term_exists = term_exists( $term['slug'], $taxonomy );
				$term_id = is_array( $term_exists ) ? $term_exists['term_id'] : $term_exists;
				if ( ! $term_id ) {
					$t = wp_insert_term( $term['name'], $taxonomy, array( 'slug' => $term['slug'] ) );
					if ( ! is_wp_error( $t ) ) {
						$term_id = $t['term_id'];
					} else {
						printf( __( 'Failed to import %s "%s"', 'wordpress-importer' ), $taxonomy, $term['name'] );
						if ( defined('IMPORT_DEBUG') && IMPORT_DEBUG )
							echo ': ' . $t->get_error_message();
						echo "\n";
						return;
					}
				}
				$terms_to_set[$taxonomy][] = intval( $term_id );
			}

			foreach ( $terms_to_set as $tax => $ids ) {
				$tt_ids = wp_set_post_terms( $post_id, $ids, $tax );
			}
			unset( $post['terms'], $terms_to_set );
		}

		// add/update comments
		if ( ! empty( $post['comments'] ) ) {
			$num_comments = 0;
			$inserted_comments = array();
			foreach ( $post['comments'] as $comment ) {
				$comment_id	= $comment['comment_id'];
				$newcomments[$comment_id]['comment_post_ID']      = $comment_post_ID;
				$newcomments[$comment_id]['comment_author']       = $comment['comment_author'];
				$newcomments[$comment_id]['comment_author_email'] = $comment['comment_author_email'];
				$newcomments[$comment_id]['comment_author_IP']    = $comment['comment_author_IP'];
				$newcomments[$comment_id]['comment_author_url']   = $comment['comment_author_url'];
				$newcomments[$comment_id]['comment_date']         = $comment['comment_date'];
				$newcomments[$comment_id]['comment_date_gmt']     = $comment['comment_date_gmt'];
				$newcomments[$comment_id]['comment_content']      = $comment['comment_content'];
				$newcomments[$comment_id]['comment_approved']     = $comment['comment_approved'];
				$newcomments[$comment_id]['comment_type']         = $comment['comment_type'];
				$newcomments[$comment_id]['comment_parent'] 	  = $comment['comment_parent'];
				if ( isset( $this->processed_authors[$comment['comment_user_id']] ) )
					$newcomments[$comment_id]['user_id'] = $this->processed_authors[$comment['comment_user_id']];
			}
			ksort( $newcomments );

			foreach ( $newcomments as $key => $comment ) {
				// if this is a new post we can skip the comment_exists() check
				if ( ! $post_exists || ! comment_exists( $comment['comment_author'], $comment['comment_date'] ) ) {
					if ( isset( $inserted_comments[$comment['comment_parent']] ) )
						$comment['comment_parent'] = $inserted_comments[$comment['comment_parent']];
					$comment = wp_filter_comment( $comment );
					$inserted_comments[$key] = wp_insert_comment( $comment );
					$num_comments++;
				}
			}
			unset( $newcomments, $inserted_comments, $post['comments'] );
		}

		// add/update post meta
		if ( isset( $post['postmeta'] ) ) {
			foreach ( $post['postmeta'] as $meta ) {
				$key = apply_filters( 'import_post_meta_key', $meta['key'] );
				$value = false;

				if ( '_edit_last' == $key ) {
					if ( isset( $this->processed_authors[intval($meta['value'])] ) )
						$value = $this->processed_authors[intval($meta['value'])];
					else
						$key = false;
				}

				if ( $key ) {
					// export gets meta straight from the DB so could have a serialized string
					if ( ! $value )
						$value = maybe_unserialize( $meta['value'] );

					add_post_meta( $post_id, $key, $value );
					do_action( 'import_post_meta', $post_id, $key, $value );

					// if the post has a featured image, take note of this in case of remap
					if ( '_thumbnail_id' == $key )
						$this->featured_images[$post_id] = (int) $value;
				}
			}
		}
		echo "Done.\n";

		if( $this->verbose ) 
			$this->showquery();
	}

	function showquery() {
		if( !defined( 'SAVEQUERIES' ) || !SAVEQUERIES ) 
			return;

		global $wpdb;

		$query = array_pop( $wpdb->queries );

		echo "\n>>> Query: ".$query[0]."\n";
	}

	/**
	 * Attempt to create a new menu item from import data
	 *
	 * Fails for draft, orphaned menu items and those without an associated nav_menu
	 * or an invalid nav_menu term. If the post type or term object which the menu item
	 * represents doesn't exist then the menu item will not be imported (waits until the
	 * end of the import to retry again before discarding).
	 *
	 * @param array $item Menu item details from WXR file
	 */
	function process_menu_item( $item ) {
		// skip draft, orphaned menu items
		if ( 'draft' == $item['status'] )
			return;

		$menu_slug = false;
		if ( isset($item['terms']) ) {
			// loop through terms, assume first nav_menu term is correct menu
			foreach ( $item['terms'] as $term ) {
				if ( 'nav_menu' == $term['domain'] ) {
					$menu_slug = $term['slug'];
					break;
				}
			}
		}

		// no nav_menu term associated with this menu item
		if ( ! $menu_slug ) {
			_e( 'Menu item skipped due to missing menu slug', 'wordpress-importer' );
			echo '<br />';
			return;
		}

		$menu_id = term_exists( $menu_slug, 'nav_menu' );
		if ( ! $menu_id ) {
			printf( __( 'Menu item skipped due to invalid menu slug: %s', 'wordpress-importer' ), esc_html( $menu_slug ) );
			echo "\n";
			return;
		} else {
			$menu_id = is_array( $menu_id ) ? $menu_id['term_id'] : $menu_id;
		}

		foreach ( $item['postmeta'] as $meta )
			$$meta['key'] = $meta['value'];

		if ( 'taxonomy' == $_menu_item_type && isset( $this->processed_terms[intval($_menu_item_object_id)] ) ) {
			$_menu_item_object_id = $this->processed_terms[intval($_menu_item_object_id)];
		} else if ( 'post_type' == $_menu_item_type && isset( $this->processed_posts[intval($_menu_item_object_id)] ) ) {
			$_menu_item_object_id = $this->processed_posts[intval($_menu_item_object_id)];
		} else if ( 'custom' != $_menu_item_type ) {
			// associated object is missing or not imported yet, we'll retry later
			$this->missing_menu_items[] = $item;
			return;
		}

		if ( isset( $this->processed_menu_items[intval($_menu_item_menu_item_parent)] ) ) {
			$_menu_item_menu_item_parent = $this->processed_menu_items[intval($_menu_item_menu_item_parent)];
		} else if ( $_menu_item_menu_item_parent ) {
			$this->menu_item_orphans[intval($item['post_id'])] = (int) $_menu_item_menu_item_parent;
			$_menu_item_menu_item_parent = 0;
		}

		// wp_update_nav_menu_item expects CSS classes as a space separated string
		$_menu_item_classes = maybe_unserialize( $_menu_item_classes );
		if ( is_array( $_menu_item_classes ) )
			$_menu_item_classes = implode( ' ', $_menu_item_classes );

		$args = array(
			'menu-item-object-id' => $_menu_item_object_id,
			'menu-item-object' => $_menu_item_object,
			'menu-item-parent-id' => $_menu_item_menu_item_parent,
			'menu-item-position' => intval( $item['menu_order'] ),
			'menu-item-type' => $_menu_item_type,
			'menu-item-title' => $item['post_title'],
			'menu-item-url' => $_menu_item_url,
			'menu-item-description' => $item['post_content'],
			'menu-item-attr-title' => $item['post_excerpt'],
			'menu-item-target' => $_menu_item_target,
			'menu-item-classes' => $_menu_item_classes,
			'menu-item-xfn' => $_menu_item_xfn,
			'menu-item-status' => $item['status']
		);

		$id = wp_update_nav_menu_item( $menu_id, 0, $args );
		if ( $id && ! is_wp_error( $id ) )
			$this->processed_menu_items[intval($item['post_id'])] = (int) $id;
	}

	/**
	 * If fetching attachments is enabled then attempt to create a new attachment
	 *
	 * @param array $post Attachment post details from WXR
	 * @param string $url URL to fetch attachment from
	 * @return int|WP_Error Post ID on success, WP_Error otherwise
	 */
	function process_attachment( $post, $url ) {
		if ( ! $this->fetch_attachments )
			return new WP_Error( 'attachment_processing_error',
				__( 'Fetching attachments is not enabled', 'wordpress-importer' ) );

		// if the URL is absolute, but does not contain address, then upload it assuming base_site_url
		if ( preg_match( '|^/[\w\W]+$|', $url ) )
			$url = rtrim( $this->base_url, '/' ) . $url;

		$upload = $this->fetch_remote_file( $url, $post );
		if ( is_wp_error( $upload ) )
			return $upload;
		if ( $info = wp_check_filetype( $upload['file'] ) )
			$post['post_mime_type'] = $info['type'];
		else
			return new WP_Error( 'attachment_processing_error', __('Invalid file type', 'wordpress-importer') );

		$post['guid'] = $upload['url'];
		// as per wp-admin/includes/upload.php
		$post_id = wp_insert_attachment( $post, $upload['file'] );

try{
		wp_update_attachment_metadata( $post_id, wp_generate_attachment_metadata( $post_id, $upload['file'] ) );
}catch (Exception $e) {
	echo 'Caught exception: ',  $e->getMessage(), "\n";
	exit();
}

		// remap resized image URLs, works by stripping the extension and remapping the URL stub.
		if ( preg_match( '!^image/!', $info['type'] ) ) {
			$parts = pathinfo( $url );
			$name = basename( $parts['basename'], ".{$parts['extension']}" ); // PATHINFO_FILENAME in PHP 5.2

			$parts_new = pathinfo( $upload['url'] );
			$name_new = basename( $parts_new['basename'], ".{$parts_new['extension']}" );

			$this->url_remap[$parts['dirname'] . '/' . $name] = $parts_new['dirname'] . '/' . $name_new;
		}

		return $post_id;
	}

	/**
	 * Attempt to download a remote file attachment
	 *
	 * @param string $url URL of item to fetch
	 * @param array $post Attachment details
	 * @return array|WP_Error Local file location details on success, WP_Error otherwise
	 */
	function fetch_remote_file( $url, $post ) {
		// extract the file name and extension from the url
		$file_name = basename( $url );

		// Check to see if this file already exists:
		$upload = wp_upload_dir( $post['upload_date'] );

		if ( $upload['error'] !== false )
			return new WP_Error( 'upload_dir_error', $upload['error'] );

		$file = $upload['path'].'/'.$file_name;
		if($GLOBALS['loglevel'] == 3)
			echo "Checking existence of $file...";
		else echo "Checking for File...";
		if( file_exists( $file ) ) {
			if($GLOBALS['loglevel'] == 3)
				echo " using existing file at $file... ";
			else echo " using existing file.";
			return array( 'file' => $file, 'url' => $upload['url'].'/'.$file_name );
		}

		echo " not found. Downloading... ";

		// get placeholder file in the upload dir with a unique, sanitized filename
		$upload = wp_upload_bits( $file_name, 0, '', $post['upload_date'] );
		if ( $upload['error'] )
			return new WP_Error( 'upload_dir_error', $upload['error'] );

		// fetch the remote url and write it to the placeholder file
		$headers = wp_get_http( $url, $upload['file'] );

		// request failed
		if ( ! $headers ) {
			@unlink( $upload['file'] );
			return new WP_Error( 'import_file_error', __('Remote server did not respond', 'wordpress-importer') );
		}

		// make sure the fetch was successful
		if ( $headers['response'] != '200' ) {
			@unlink( $upload['file'] );
			return new WP_Error( 'import_file_error', sprintf( __('Remote server returned error response %1$d %2$s', 'wordpress-importer'), esc_html($headers['response']), get_status_header_desc($headers['response']) ) );
		}

		$filesize = filesize( $upload['file'] );

		if ( isset( $headers['content-length'] ) && $filesize != $headers['content-length'] ) {
			@unlink( $upload['file'] );
			return new WP_Error( 'import_file_error', __('Remote file is incorrect size', 'wordpress-importer') );
		}

		if ( 0 == $filesize ) {
			@unlink( $upload['file'] );
			return new WP_Error( 'import_file_error', __('Zero size file downloaded', 'wordpress-importer') );
		}

		$max_size = (int) $this->max_attachment_size();
		if ( ! empty( $max_size ) && $filesize > $max_size ) {
			@unlink( $upload['file'] );
			return new WP_Error( 'import_file_error', sprintf(__('Remote file is too large, limit is %s', 'wordpress-importer'), size_format($max_size) ) );
		}

		// keep track of the old and new urls so we can substitute them later
		$this->url_remap[$url] = $upload['url'];
		$this->url_remap[$post['guid']] = $upload['url']; // r13735, really needed?
		// keep track of the destination if the remote url is redirected somewhere else
		if ( isset($headers['x-final-location']) && $headers['x-final-location'] != $url )
			$this->url_remap[$headers['x-final-location']] = $upload['url'];

		return $upload;
	}

	/**
	 * Attempt to associate posts and menu items with previously missing parents
	 *
	 * An imported post's parent may not have been imported when it was first created
	 * so try again. Similarly for child menu items and menu items which were missing
	 * the object (e.g. post) they represent in the menu
	 */
	function backfill_parents() {
		global $wpdb;

		// find parents for post orphans
		foreach ( $this->post_orphans as $child_id => $parent_id ) {
			$local_child_id = $local_parent_id = false;
			if ( isset( $this->processed_posts[$child_id] ) )
				$local_child_id = $this->processed_posts[$child_id];
			if ( isset( $this->processed_posts[$parent_id] ) )
				$local_parent_id = $this->processed_posts[$parent_id];

			if ( $local_child_id && $local_parent_id )
				$wpdb->update( $wpdb->posts, array( 'post_parent' => $local_parent_id ), array( 'ID' => $local_child_id ), '%d', '%d' );
		}

		// all other posts/terms are imported, retry menu items with missing associated object
		$missing_menu_items = $this->missing_menu_items;
		foreach ( $missing_menu_items as $item )
			$this->process_menu_item( $item );

		// find parents for menu item orphans
		foreach ( $this->menu_item_orphans as $child_id => $parent_id ) {
			$local_child_id = $local_parent_id = 0;
			if ( isset( $this->processed_menu_items[$child_id] ) )
				$local_child_id = $this->processed_menu_items[$child_id];
			if ( isset( $this->processed_menu_items[$parent_id] ) )
				$local_parent_id = $this->processed_menu_items[$parent_id];

			if ( $local_child_id && $local_parent_id )
				update_post_meta( $local_child_id, '_menu_item_menu_item_parent', (int) $local_parent_id );
		}
	}

	/**
	 * Use stored mapping information to update old attachment URLs
	 */
	function backfill_attachment_urls() {
		global $wpdb;

		echo "Back-filling ".count( $this->url_remap )." attachment URLs... ";
		// make sure we do the longest urls first, in case one is a substring of another
		uksort( $this->url_remap, array(&$this, 'cmpr_strlen') );

		foreach ( $this->url_remap as $from_url => $to_url ) {
			echo ".";
			// remap urls in post_content
			$wpdb->query( $wpdb->prepare("UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s)", $from_url, $to_url) );
			// remap enclosure urls
			$result = $wpdb->query( $wpdb->prepare("UPDATE {$wpdb->postmeta} SET meta_value = REPLACE(meta_value, %s, %s) WHERE meta_key='enclosure'", $from_url, $to_url) );
		}

		echo " Done.\n";
	}

	/**
	 * Update _thumbnail_id meta to new, imported attachment IDs
	 */
	function remap_featured_images() {
		// cycle through posts that have a featured image
		foreach ( $this->featured_images as $post_id => $value ) {
			if ( isset( $this->processed_posts[$value] ) ) {
				$new_id = $this->processed_posts[$value];
				// only update if there's a difference
				if ( $new_id != $value )
					update_post_meta( $post_id, '_thumbnail_id', $new_id );
			}
		}
	}

	/**
	 * Decide if the given meta key maps to information we will want to import
	 *
	 * @param string $key The meta key to check
	 * @return string|bool The key if we do want to import, false if not
	 */
	function is_valid_meta_key( $key ) {
		// skip attachment metadata since we'll regenerate it from scratch
		// skip _edit_lock as not relevant for import
		if ( in_array( $key, array( '_wp_attached_file', '_wp_attachment_metadata', '_edit_lock' ) ) )
			return false;
		return $key;
	}

	/**
	 * Decide whether or not the importer is allowed to create users.
	 * Default is true, can be filtered via import_allow_create_users
	 *
	 * @return bool True if creating users is allowed
	 */
	function allow_create_users() {
		return apply_filters( 'import_allow_create_users', true );
	}

	/**
	 * Decide whether or not the importer should attempt to download attachment files.
	 * Default is true, can be filtered via import_allow_fetch_attachments. The choice
	 * made at the import options screen must also be true, false here hides that checkbox.
	 *
	 * @return bool True if downloading attachments is allowed
	 */
	function allow_fetch_attachments() {
		return apply_filters( 'import_allow_fetch_attachments', true );
	}

	/**
	 * Decide what the maximum file size for downloaded attachments is.
	 * Default is 0 (unlimited), can be filtered via import_attachment_size_limit
	 *
	 * @return int Maximum attachment file size to import
	 */
	function max_attachment_size() {
		return apply_filters( 'import_attachment_size_limit', 0 );
	}

	/**
	 * Added to http_request_timeout filter to force timeout at 60 seconds during import
	 * @return int 60
	 */
	function bump_request_timeout() {
		return 60;
	}

	// return the difference in length between two strings
	function cmpr_strlen( $a, $b ) {
		return strlen($b) - strlen($a);
	}
}

set_time_limit( 0 );
new WP_Import_CLI( $import_options );
