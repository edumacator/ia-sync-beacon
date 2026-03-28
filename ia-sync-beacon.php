<?php
/**
 * Plugin Name: IA Beacon → Foundation Sync
 * Description: Nightly importer that copies selected Beacon posts into Foundation,
 *              brings over media, featured images, categories, and more.
 * Version:     1.0.0
 * Author:      Innovation Academy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load media-helper library
require_once __DIR__ . '/inc/media-import.php';

/**
 * Main Plugin Class
 */
class IA_Sync_Beacon {

	const OPT_REMOTE       = 'iaxsp_remote_site';
	const OPT_PRIMARY_TAG  = 'iaxsp_tag_slugs'; // The tag that triggers sync
	const OPT_TAG_MAPPING  = 'iaxsp_tag_to_tag_mapping'; // Mapping: "remote-tag:local-tag-slug"
	const OPT_DEFAULT_CAT  = 'iaxsp_default_category';
	const OPT_AUTHOR       = 'iaxsp_author_id';
	const OPT_SYNC_LOG     = 'iaxsp_sync_log';
	const REMOTE_ID_META   = '_iaxsp_remote_id';

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'add_settings_page' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'iaxsp_nightly_sync_hook', [ $this, 'run_sync' ] );
	}

	/* ------------------------------------------------------------------ */
	/*  Settings & Admin UI                                               */
	/* ------------------------------------------------------------------ */

	public function add_settings_page() {
		add_options_page(
			'IA Sync Beacon Settings',
			'IA Sync Beacon',
			'manage_options',
			'ia-sync-beacon',
			[ $this, 'render_settings_page' ]
		);
	}

	public function register_settings() {
		$opts = [ 
			self::OPT_REMOTE, self::OPT_PRIMARY_TAG, self::OPT_TAG_MAPPING, 
			self::OPT_DEFAULT_CAT, self::OPT_AUTHOR, self::OPT_SYNC_LOG 
		];
		foreach ( $opts as $opt ) {
			register_setting( 'iaxsp_settings_group', $opt );
		}
	}

	public function render_settings_page() {
		$primary_tag = get_option( self::OPT_PRIMARY_TAG, 'iaf' );
		$remote_site = get_option( self::OPT_REMOTE, 'https://beacon2.fcsia.com' );
		$mapping     = get_option( self::OPT_TAG_MAPPING, '' );
		$default_cat = get_option( self::OPT_DEFAULT_CAT, 'uncategorized' );
		$author_id   = get_option( self::OPT_AUTHOR, 1 );
		$last_log    = get_option( self::OPT_SYNC_LOG, 'No sync history yet.' );

		if ( isset( $_GET['run_sync'] ) && check_admin_referer( 'iaxsp_manual_sync' ) ) {
			$this->run_sync();
			wp_redirect( admin_url( 'options-general.php?page=ia-sync-beacon&sync_complete=1' ) );
			exit;
		}
		?>
		<div class="wrap">
			<h1>IA Sync Beacon Settings</h1>
			
			<?php if ( isset( $_GET['sync_complete'] ) ) : ?>
				<div class="updated"><p>Manual sync triggered successfully. Check logs below for details.</p></div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'iaxsp_settings_group' ); ?>
				
				<table class="form-table">
					<tr>
						<th scope="row">Source Domain</th>
						<td>
							<input type="url" name="<?php echo self::OPT_REMOTE; ?>" value="<?php echo esc_attr( $remote_site ); ?>" class="regular-text" placeholder="https://source-site.com">
							<p class="description">The base URL of the source WordPress site.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Primary Sync Tag Slug</th>
						<td>
							<input type="text" name="<?php echo self::OPT_PRIMARY_TAG; ?>" value="<?php echo esc_attr( $primary_tag ); ?>" class="regular-text">
							<p class="description">Only posts with this tag will be pulled (e.g., <code>iaf</code>).</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Tag Mappings</th>
						<td>
							<textarea name="<?php echo self::OPT_TAG_MAPPING; ?>" rows="5" class="large-text" placeholder="staff:staff-tag&#10;news:latest-news-tag"><?php echo esc_textarea( $mapping ); ?></textarea>
							<p class="description">One per line: <code>remote-tag-slug:local-tag-slug</code>. If a post has multiple tags, the first matching rule wins.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Default Local Category</th>
						<td>
							<input type="text" name="<?php echo self::OPT_DEFAULT_CAT; ?>" value="<?php echo esc_attr( $default_cat ); ?>" class="regular-text">
							<p class="description">Fallback category slug if no mapping matches.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Post Author ID</th>
						<td>
							<input type="number" name="<?php echo self::OPT_AUTHOR; ?>" value="<?php echo esc_attr( $author_id ); ?>" class="small-text">
							<p class="description">The local WordPress user ID that will own the imported posts.</p>
						</td>
					</tr>
				</table>
				
				<?php submit_button(); ?>
			</form>

			<hr>
			<h2>Manual Control</h2>
			<p>Click below to trigger a sync immediately. This will process the latest 10 posts from the source site.</p>
			<a href="<?php echo wp_nonce_url( admin_url( 'options-general.php?page=ia-sync-beacon&run_sync=1' ), 'iaxsp_manual_sync' ); ?>" class="button button-secondary">Run Sync Now</a>

			<hr>
			<h2>Last Sync Activity</h2>
			<pre style="background:#eee; padding:15px; border:1px solid #ccc; max-height:300px; overflow:auto;"><?php echo esc_html( $last_log ); ?></pre>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/*  Sync Engine                                                       */
	/* ------------------------------------------------------------------ */

	public function run_sync() {
		$log = "[ " . date( 'Y-m-d H:i:s' ) . " ] Starting Sync...\n";
		
		$remote_url  = untrailingslashit( get_option( self::OPT_REMOTE ) );
		$primary_tag = get_option( self::OPT_PRIMARY_TAG );
		
		if ( ! $remote_url || ! $primary_tag ) {
			$this->log( $log . "ERROR: Remote URL or Primary Tag missing.\n" );
			return;
		}

		/* 1. Find the Tag ID on the remote site */
		$tag_resp = wp_remote_get( "$remote_url/wp-json/wp/v2/tags?slug=$primary_tag" );
		if ( is_wp_error( $tag_resp ) ) {
			$this->log( $log . "ERROR fetching remote tag: " . $tag_resp->get_error_message() . "\n" );
			return;
		}
		$tags = json_decode( wp_remote_retrieve_body( $tag_resp ) );
		if ( empty( $tags ) || ! isset( $tags[0]->id ) ) {
			$this->log( $log . "ERROR: Primary tag '$primary_tag' not found on remote site.\n" );
			return;
		}
		$tag_id = $tags[0]->id;

		/* 2. Fetch posts with that tag */
		$posts_resp = wp_remote_get( "$remote_url/wp-json/wp/v2/posts?tags=$tag_id&_embed=1&per_page=10" );
		if ( is_wp_error( $posts_resp ) ) {
			$this->log( $log . "ERROR fetching remote posts: " . $posts_resp->get_error_message() . "\n" );
			return;
		}
		$remote_posts = json_decode( wp_remote_retrieve_body( $posts_resp ) );
		
		if ( empty( $remote_posts ) ) {
			$this->log( $log . "No posts found with tag '$primary_tag'.\n" );
			return;
		}

		$log .= "Found " . count( $remote_posts ) . " posts. Processing...\n";

		/* 3. Process each post */
		foreach ( $remote_posts as $rp ) {
			$log .= "  - Processing Remote ID: {$rp->id} ('{$rp->title->rendered}')...\n";
			$local_id = $this->get_local_post_by_remote_id( $rp->id );
			
			$post_data = [
				'post_title'   => $rp->title->rendered,
				'post_content' => $rp->content->rendered,
				'post_status'  => 'publish', // or based on $rp->status
				'post_author'  => get_option( self::OPT_AUTHOR, 1 ),
				'post_date'    => $rp->date,
			];

			if ( $local_id ) {
				$post_data['ID'] = $local_id;
				wp_update_post( $post_data );
				$log .= "    Updated existing post (Local ID: $local_id).\n";
			} else {
				$local_id = wp_insert_post( $post_data );
				update_post_meta( $local_id, self::REMOTE_ID_META, $rp->id );
				$log .= "    Created new post (Local ID: $local_id).\n";
			}

			// Apply Taxonomies (Category & Tags)
			$this->assign_taxonomies( $local_id, $rp );

			// Localize Media
			if ( function_exists( 'ia_beacon_localize_media_in_content' ) ) {
				$clean_content = ia_beacon_localize_media_in_content( $rp->content->rendered, $local_id );
				// Update content with localized versions (stripping Elementor-specific attributes happens in media-import if needed)
				wp_update_post( [ 'ID' => $local_id, 'post_content' => $clean_content ] );
			}

			// Featured Image
			if ( ! empty( $rp->featured_media ) && function_exists( 'ia_beacon_import_featured_image' ) ) {
				ia_beacon_import_featured_image( $rp->featured_media, $local_id );
			}
		}

		$log .= "Sync complete.\n";
		$this->log( $log );
	}

	private function get_local_post_by_remote_id( $remote_id ) {
		$query = new WP_Query( [
			'post_type'      => 'post',
			'meta_key'       => self::REMOTE_ID_META,
			'meta_value'     => $remote_id,
			'posts_per_page' => 1,
			'fields'         => 'ids',
		] );
		return $query->posts ? $query->posts[0] : null;
	}

	private function assign_taxonomies( $post_id, $remote_post ) {
		$mappings_raw = get_option( self::OPT_TAG_MAPPING, '' );
		$mappings     = [];
		foreach ( explode( "\n", str_replace( "\r", "", $mappings_raw ) ) as $line ) {
			$parts = explode( ":", trim( $line ) );
			if ( count( $parts ) === 2 ) {
				$mappings[ trim( $parts[0] ) ] = trim( $parts[1] );
			}
		}

		/* 1. Get all remote tag slugs */
		$remote_tags = [];
		if ( ! empty( $remote_post->_embedded->{'wp:term'} ) ) {
			foreach ( $remote_post->_embedded->{'wp:term'} as $term_group ) {
				foreach ( $term_group as $term ) {
					if ( $term->taxonomy === 'post_tag' ) {
						$remote_tags[] = $term->slug;
					}
				}
			}
		}

		/* 2. Resolve Local Tags based on mapping */
		$target_tags = [];
		foreach ( $mappings as $remote_tag => $local_tag ) {
			if ( in_array( $remote_tag, $remote_tags ) ) {
				$target_tags[] = $local_tag;
			}
		}

		/* 3. Ensure Local Tags exist and assign them */
		if ( ! empty( $target_tags ) ) {
			wp_set_post_tags( $post_id, $target_tags, true ); // Append tags
		}

		/* 4. Assign Default Category (as fallback or primary) */
		$target_cat_slug = get_option( self::OPT_DEFAULT_CAT, 'uncategorized' );
		$cat = get_term_by( 'slug', $target_cat_slug, 'category' );
		if ( ! $cat ) {
			$new_cat = wp_insert_term( ucwords( str_replace( "-", " ", $target_cat_slug ) ), 'category', [ 'slug' => $target_cat_slug ] );
			$cat_id  = ! is_wp_error( $new_cat ) ? $new_cat['term_id'] : 0;
		} else {
			$cat_id = $cat->term_id;
		}

		if ( $cat_id ) {
			wp_set_post_categories( $post_id, [ $cat_id ] );
		}
	}

	private function log( $msg ) {
		update_option( self::OPT_SYNC_LOG, $msg );
	}
}

// Instantiate
$GLOBALS['ia_sync_beacon'] = new IA_Sync_Beacon();

// Activation/Deactivation for CRON
register_activation_hook( __FILE__, function() {
	if ( ! wp_next_scheduled( 'iaxsp_nightly_sync_hook' ) ) {
		wp_schedule_event( time(), 'daily', 'iaxsp_nightly_sync_hook' );
	}
} );

register_deactivation_hook( __FILE__, function() {
	wp_clear_scheduled_hook( 'iaxsp_nightly_sync_hook' );
} );
