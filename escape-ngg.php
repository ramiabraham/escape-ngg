<?php
/**
 * Plugin Name: Escape NextGen Gallery
 * Description: Converts NextGen Galleries to native WordPress Galleries. Read code for instructions.
 * Author: Konstantin Kovshenin
 * License: GPLv3
 * Version: 1.0
 *
 * This plugin will scan through all your posts and pages for the [nggallery] shortcode. 
 * It will loop through all images associated with that gallery and recreate them as native 
 * WordPress attachments instead. Finally it will replace the [nggallery] shortcode with 
 * the [gallery] shortcode native to WordPress.
 *
 * Instructions: Backup! Activate the plugin and browse to yourdomain.com/wp-admin/?escape_ngg_please=1
 * When you're done you can delete the gallery dir, and the wp_ngg_* tables in your database. Keep the backups though.
 *
 * Limitations: 
 * - doesn't work with shortcodes other than [nggallery]
 * - doesn't work when more than one gallery on page
 *
 * @uses media_sideload_image to recreate your attachment posts
 */

if ( defined( 'WP_CLI' ) && WP_CLI )
	require_once( __DIR__ . '/wp-cli.php' );


/**
 * 
 * 
 * @package 
 **/
class Escape_NextGen_Gallery {

	/**
	 * The number of images converted
	 *
	 * @var int
	 **/
	public $images_count;

	/**
	 * The number of posts converted
	 *
	 * @var int
	 **/
	public $posts_count;

	/**
	 * Any warnings encountered
	 *
	 * @var array
	 **/
	public $warnings;

	/**
	 * Information about what's happened
	 *
	 * @var array
	 **/
	public $infos;

	/**
	 * Singleton stuff.
	 * 
	 * @access @static
	 * 
	 * @return Escape_NextGen_Gallery object
	 */
	static public function init() {
		static $instance = false;

		if ( ! $instance )
			$instance = new Escape_NextGen_Gallery;

		return $instance;

	}

	/**
	 * Class constructor
	 *
	 * @return null
	 */
	public function __construct() {
		add_action( 'admin_init', array( $this, 'action_admin_init' ) );

		$this->images_count = 0;
		$this->posts_count = 0;
		$this->warnings = array();
		$this->infos = array();
	}

	// HOOKS
	// =====

	/**
	 * Hooks the WP action admin_init
	 *
	 * @action admin_init
	 *
	 * @return void
	 **/
	public function action_admin_init() {
		if ( ! isset( $_GET['escape_ngg_please'] ) || ! current_user_can( 'install_plugins' ) )
			return;

		$limit = -1;

		if ( isset( $_GET[ 'escape_ngg_limit' ] ) ) {
			$limit = (int) $_GET[ 'escape_ngg_limit' ];
		}

		error_reporting( E_ALL );
		ini_set( 'display_errors', 1 );
		set_time_limit( 5000 );

		$post_ids = $this->get_post_ids( $limit );
		
		foreach ( $post_ids as $post_id ) {
			$this->process_post( $post_id );
		}

		$this->infos[] = sprintf( "Updated %d posts and %d images.", $this->posts_count, $this->images_count );

		foreach ( $this->infos as $info )
			printf( '<span style="color: #090;">%s</span><br />', esc_html( $info ) );

		foreach ( $this->warnings as $warning )
			printf( '<span style="color: #900;">%s</span><br />', esc_html( $warning ) );

		die();
	}

	// UTILITIES
	// =========

	/**
	 * 
	 *
	 *
	 * @return int A count of posts with 
	 * @author Simon Wheatley
	 **/
	public function count() {
		return count( $this->get_post_ids() );
	}

	/**
	 * Processes an actual post
	 *
	 * @param int $post_id The ID for a post
	 * @return void
	 * @author Simon Wheatley
	 **/
	public function process_post( $post_id ) {
		global $wpdb;
		$post = get_post( $post_id );
		$matches = null;
		$attachment_ids = array();

		preg_match( '#nggallery id(\s)*="?(\s)*(?P<id>\d+)#i', $post->post_content, $matches );
		if ( ! isset( $matches['id'] ) ) {
			$this->warnings[] = sprintf( "Could not match gallery id in %d", $post->ID );
			return;
		}

		$gallery_id = $matches['id'];
		$path = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}ngg_gallery WHERE gid = ". intval( $gallery_id ), ARRAY_A  );
		$images = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}ngg_pictures WHERE galleryid = ". intval( $gallery_id ) . " ORDER BY sortorder, pid ASC" );

		if ( ! $path || ! $images ) {
			$this->warnings[] = sprintf( "Could not find images for nggallery %d", $gallery_id );
			return;
		}

		foreach ( $images as $image ) {
			$url = home_url( trailingslashit( $path['path'] ) . $image->filename );
			$url = apply_filters( 'engg_image_url', $url, $path['path'], $image->filename );


			$attch_id = $this->media_sideload_image( $url, $post->ID );

			if ( is_wp_error( $attch_id ) ) {
				$this->warnings[] = sprintf( "Error loading %s: %s", $url, $attch_id->get_error_message() );
				continue;
			}

			if ( false === $attch_id ) {
				$this->warnings[] = sprintf( "Could not insert attachment for %d", $post->ID );
				continue;
			}

			// Titles should fallback to the filename.
			if ( ! trim( $image->alttext ) ) {
				$image->alttext = $image->filename;
			}

			$attachment = get_post( $attch_id );
			$attachment->post_title = $image->alttext;
			$attachment->post_content = $image->description;
			$attachment->menu_order = $image->sortorder;

			update_post_meta( $attachment->ID, '_wp_attachment_image_alt', $image->alttext );

			wp_update_post( $attachment );
			$this->images_count++;
			$attachment_ids[] = $attch_id;

			$this->infos[] = sprintf( "Added attachment for %d", $post->ID );
		}

		if ( 0 == $this->images_count ) {
			$this->warnings[] = sprintf( "Could not load images for nggallery %d", $gallery_id );
			return;
		}

		// Construct the [gallery] shortcode
		$attr = array();
		if ( $attachment_ids )
			$attr['ids'] = implode( ',', $attachment_ids );

		$gallery = '[gallery';
		foreach ( $attr as $key => $value )
			$gallery .= sprintf( ' %s="%s"', esc_attr( $key ), esc_attr( $value ) );
		$gallery .= ']';

		// Booyaga!
		$pristine_content = $post->post_content;
		$post->post_content = preg_replace( '#\[nggallery[^\]]*\]#i', $gallery, $post->post_content );
		$post->post_content = apply_filters( 'engg_post_content', $post->post_content, $pristine_content, $attr, $post, $gallery );
		wp_update_post( $post );
		$this->posts_count++;
		$this->infos[] = sprintf( "Updated post %d", $post->ID );	
	}

	public function media_sideload_image( $file, $post_id, $desc = null ) {
		if ( ! empty( $file ) ) {
			// Set variables for storage, fix file filename for query strings.
			preg_match( '/[^\?]+\.(jpe?g|jpe|gif|png)\b/i', $file, $matches );
			$file_array = array();
			$file_array['name'] = basename( $matches[0] );

			// Download file to temp location.
			$file_array['tmp_name'] = download_url( $file );

			// If error storing temporarily, return the error.
			if ( is_wp_error( $file_array['tmp_name'] ) ) {
				return $file_array['tmp_name'];
			}

			// Do the validation and storage stuff.
			$id = media_handle_sideload( $file_array, $post_id, $desc );

			// If error storing permanently, unlink.
			if ( is_wp_error( $id ) ) {
				@unlink( $file_array['tmp_name'] );
				return $id;
			}

			return $id;
		}

		return false;
	}

	/**
	 * @param int $limit How many posts to get
	 *
	 * @return array $posts Array of posts IDs.
	 **/
	public function get_post_ids( $limit = -1 ) {
		$args = array(
			's'           => '[nggallery',
			'post_type'   => array( 'post', 'page' ),
			'post_status' => 'publish',
			'fields'      => 'ids',
			'posts_per_page' => $limit,
			'no_found_rows' => true,
			'cache_results' => false
		);
		
		$args = apply_filters( 'escape_ngg_query_args', $args );
		
		$query = new WP_Query( $args );
		return $query->posts;
	}
}

// Initiate the singleton
Escape_NextGen_Gallery::init();
