<?php
/**
 * Plugin Name: Community + Code customizations
 * Author: Chris Reynolds
 * License: MIT License
 */

namespace CommunityCode\MuPlugin;

/**
 * Kick everything off.
 */
function init() {
	// Add the customizer for CSS controls.
	add_action( 'customize_register', '__return_true' );
	add_action( 'init', function() {
		if ( current_theme_supports( 'customizer' ) ) {
			add_theme_support( 'custom-css' );
		}
	});

	add_action( 'admin_menu', __NAMESPACE__ . '\\add_accelerate_analytics_page', 100 );
	add_action( 'init', __NAMESPACE__ . '\\register_episodes' );
	add_action( 'rest_api_init', __NAMESPACE__ . '\\register_youtube_url' );
	add_action( 'rest_api_init', __NAMESPACE__ . '\\register_yoast_meta_description_to_rest' );
	add_action( 'wp_dashboard_setup', __NAMESPACE__ . '\\dashboard_setup' );

	add_filter( 'default_content', __NAMESPACE__ . '\\set_episode_default_content', 10, 2 );
	add_filter( 'enter_title_here', __NAMESPACE__ . '\\filter_episode_title_placeholder', 10, 2 );
	add_filter( 'webpc_dir_name', __NAMESPACE__ . '\\filter_webpc_upload_path', 10, 2 );

	add_filter( 'powerpress_post_types', function( $post_types ) {
		$post_types[] = 'episodes'; // Allow PowerPress fields on episodes
		return $post_types;
	});
    add_filter('the_guid', __NAMESPACE__ . '\\feed_guid_fix', 9999, 2);
}

/**
 * Re-add the Accelerate Analytics page when the dashboard takeover is disabled.
 */
function add_accelerate_analytics_page() {
	if ( ! function_exists( '\\Altis\\Accelerate\\Dashboard\\render_analytics_page' ) ) {
		return;
	}

	$hook = add_submenu_page(
		'accelerate',
		_x( 'Analytics', 'page title', 'altis' ),
		_x( 'Analytics', 'menu title', 'altis' ),
		'read',
		'altis-analytics',
		'\\Altis\\Accelerate\\Dashboard\\render_analytics_page',
		1000
	);

	add_action( 'load-' . $hook, function () {
		wp_enqueue_style(
			'altis-accelerate-tailwind',
			plugins_url( 'build/tailwind.css', \Altis\Accelerate\PLUGIN_FILE ),
			[],
			\Altis\Accelerate\VERSION
		);

		\Altis\Accelerate\Utils\register_assets( 'dashboard', [
			'dependencies' => [
				'lodash',
				'altis-accelerate-accelerate-admin',
				'altis-accelerate-audiences/data',
				'wp-api-fetch',
				'wp-components',
				'wp-core-data',
				'wp-data',
				'wp-element',
				'wp-i18n',
				'wp-url',
				'wp-html-entities',
			],
		], true );
	} );
}

/**
 * Register Episodes post type.
 */
function register_episodes() {
	// Create an episodes post type for podcast episodes. Slug will be /episodes/ and permalink structure should be /episodes/year/month/title.
	$labels = array(
		'name' => _x( 'Episodes', 'post type general name', 'community-code' ),
		'singular_name' => _x( 'Episode', 'post type singular name', 'community-code' ),
		'menu_name' => _x( 'Episodes', 'admin menu', 'community-code' ),
		'add_new_item' => __( 'Add New Episode', 'community-code' ),
		'new_item' => __( 'New Episode', 'community-code' ),
		'edit_item' => __( 'Edit Episode', 'community-code' ),
		'view_item' => __( 'View Episode', 'community-code' ),
		'all_items' => __( 'All Episodes', 'community-code' ),
		'search_items' => __( 'Search Episodes', 'community-code' ),
		'parent_item_colon' => __( 'Parent Episodes:', 'community-code' ),
		'not_found' => __( 'No episodes found.', 'community-code' ),
		'not_found_in_trash' => __( 'No episodes found in Trash.', 'community-code' ),
	);

	$args = [
		'labels' => $labels,
		'public' => true,
		'publicly_queryable' => true,
		'show_ui' => true,
		'show_in_menu' => true,
		'query_var' => true,
		'rewrite' => [
			'slug' => 'episodes',
			'with_front' => false
		],
		'capability_type' => 'post',
		'has_archive' => true,
		'hierarchical' => false,
		'menu_position' => 2,
		'supports' => [ 'title', 'editor', 'author', 'thumbnail', 'excerpt', 'comments', 'custom-fields' ],
		'taxonomies' => [ 'post_tag' ],
		'show_in_rest' => true,
		'menu_icon' => 'dashicons-microphone',
		'feeds' => true,
	];

	register_post_type( 'episodes', $args );
}

/**
 * Register the YouTube URL meta field for episodes.
 */
function register_youtube_url() {
	register_rest_field( 'episodes', 'youtube_url', [
		'get_callback' => __NAMESPACE__ . '\\get_youtube_url',
		'schema' => [
			'description' => 'The YouTube URL for the episode.',
			'type' => 'string',
			'context' => [ 'view', 'edit' ],
		],
	] );
}

/**
 * Expose Yoast meta description field to REST API for episodes.
 */
function register_yoast_meta_description_to_rest() {
	register_rest_field( 'episodes', 'yoast_metadesc', [
		'get_callback' => __NAMESPACE__ . '\\get_yoast_meta_description',
		'schema' => [
			'description' => 'The Yoast SEO meta description for the episode.',
			'type' => 'string',
			'context' => [ 'view', 'edit' ],
		],
	] );
}

/**
 * Get the Yoast meta description for a post.
 *
 * @param array $prepared The prepared post data.
 * @return string The meta description.
 */
function get_yoast_meta_description( array $prepared ) {
	$post_id = isset( $prepared['id'] ) ? $prepared['id'] : 0;
	$desc = get_post_meta( $post_id, '_yoast_wpseo_metadesc', true );
	if ( $desc ) {
		return $desc;
	}

	if ( ! empty( $prepared['yoast_head_json']['og_description'] ) ) {
		return $prepared['yoast_head_json']['og_description'];
	}

	$raw = has_excerpt( $post_id ) ? get_the_excerpt( $post_id ) : wp_strip_all_tags( get_post_field( 'post_content', $post_id ) );
	$raw = trim( preg_replace( '/\s+/', ' ', $raw ) );
	return mb_substr( $raw, 0 ) > 300 ? mb_substr( $raw, 0, 297 ) . '...' : $raw;
}

/**
 * Get the YouTube URL for a post.
 *
 * @param array $prepared The prepared post data.
 * @return string The YouTube URL.
 */
function get_youtube_url( array $prepared ) {
	$post_id = isset( $prepared['id'] ) ? $prepared['id'] : 0;
	$youtube_url = get_post_meta( $post_id, 'youtube_url', true );
	if ( $youtube_url ) {
		return esc_url_raw( $youtube_url );
	}

	// Fall back to extracting from content.
	$content = get_post_field( 'post_content', $post_id );
	if ( preg_match('/src=["\']https?:\/\/(?:www\.)?youtube\.com\/embed\/([A-Za-z0-9_-]{11})/i', $content, $m ) ) {
		return 'https://www.youtube.com/watch?v=' . $m[1];
	}
	if (preg_match('/https?:\/\/(?:www\.)?youtu\.be\/([A-Za-z0-9_-]{11})/i', $content, $m)) {
		return 'https://www.youtube.com/watch?v=' . $m[1];
	}
	if (preg_match('/https?:\/\/(?:www\.)?youtube\.com\/watch\?v=([A-Za-z0-9_-]{11})/i', $content, $m)) {
		return 'https://www.youtube.com/watch?v=' . $m[1];
	}

	return '';
}

/**
 * Set up the default episode podcast content.
 *
 * @param string $content The default content.
 * @param WP_Post $post The post object.
 * @return string The modified content.
 */
function set_episode_default_content( $content, $post ) {
	// Bail if register_block_pattern function does not exist.
	if ( get_post_type( $post ) !== 'episodes' ) {
		return $content;
	}

	$content = <<<HTML
<!-- wp:embed {"providerNameSlug":"youtube"} /-->

<!-- wp:shortcode -->
[powerpress]
<!-- /wp:shortcode -->

<!-- wp:paragraph -->
<ul>
    <li>Episode notes</li>
</ul>
<!-- /wp:paragraph -->
HTML;

	return $content;
}

/**
 * Set the placeholder text for the episode title.
 *
 * @param string $title The title placeholder text.
 * @param WP_Post $post The post object.
 * @return string The modified title placeholder text.
 */
function filter_episode_title_placeholder( $title, $post ) {
	if ( 'episodes' === $post->post_type ) {
		return __( 'Episode title', 'community-code' );
	}
	return $title;
}

/**
 * Filter the upload path for WebP images.
 *
 * @param string $path The upload path.
 * @param string $directory The directory name.
 * @return string The modified upload path.
 */
function filter_webpc_upload_path( $path, $directory ) {
	if ( $directory !== 'webp' ) {
		return $path;
	}

	return 'app/uploads/uploads-webpc';
}

/**
 * Set up the custom dashboard widgets.
 */
function dashboard_setup() {
	// Remove default Activity widget
	remove_meta_box( 'dashboard_activity', 'dashboard', 'normal' );

	// Add custom activity widget
	wp_add_dashboard_widget(
		'dashboard_activity_custom',
		__( 'Activity', 'community-code' ),
		__NAMESPACE__ . '\\modified_activity_widget'
	);
}

/**
 * Modify the Activity widget to show upcoming scheduled posts and episodes.
 */
function modified_activity_widget() {
	// Combined query of posts + episodes.
	$upcoming = new \WP_Query([
		'post_type' => [ 'post', 'episodes' ],
		'post_status' => 'future',
		'posts_per_page' => 10,
		'orderby' => 'post_date',
		'order' => 'ASC',
	]);

	if ( ! $upcoming->have_posts() ) {
		_e( 'No scheduled content.', 'community-code' );
	}

	$today = current_time( 'Y-m-d' );
	$tomorrow = current_datetime()->modify( '+1 day' )->format( 'Y-m-d' );
	$year = current_time( 'Y' );
	?>
	<div id="activity-widget">
		<div id="future-posts" class="activity-block">
			<h3><?php _e( 'Publishing Soon', 'community-code' ); ?></h3>
			<ul>
			<?php
			while ( $upcoming->have_posts() ) {
				$upcoming->the_post();
				$time = get_the_time( 'U' );
				$post_type = get_post_type();
				$post_link = current_user_can( 'edit_post', get_the_id() ) ? get_edit_post_link() : get_permalink();
				$draft_or_post_title = _draft_or_post_title();
				if ( gmdate( 'Y-m-d', $time ) === $today ) {
					$when = __( 'Today', 'community-code' );
				} elseif ( gmdate( 'Y-m-d', $time ) === $tomorrow ) {
					$when = __( 'Tomorrow', 'community-code' );
				} elseif ( gmdate( 'Y', $time ) == $year ) {
					$when = date_i18n( __( 'M jS Y', 'community-code' ), $time );
				} else {
					$when = date_i18n( __( 'M jS', 'community-code' ), $time );
				}
				printf(
					'<li><span>%1$s</span> <a href="%2$s" aria-label="%3$s">%4$s</a> (%5$s)</li>',
					sprintf( _x( '%1$s, %2$s', 'dashboard' ), $when, get_the_time() ),
					$post_link,
					esc_attr( sprintf( __( 'Edit &#8220;%s&#8221;', 'community-code' ), $draft_or_post_title ) ),
					$draft_or_post_title,
					ucwords( str_replace( '_', ' ', $post_type ) )
				);
			}
			?>
			</ul>
		</div>
	</div>
	<?php
	wp_reset_postdata();
}

/**
 * Fix feed GUIDs for episodes when PowerPress blanks them out.
 *
 * @param string $guid The original GUID.
 * @param int $post_id The post ID.
 * @return string The modified GUID.
 */
function feed_guid_fix( $guid, $post_id ) {
    // Only touch actual feed requests
    if ( ! is_feed() ) {
        return $guid;
    }

    $guid = is_string($guid) ? trim($guid) : '';

    // If something (PowerPress) blanked it, fall back to a stable per-episode identifier.
    if ($guid === '') {
        $permalink = get_permalink($post_id);
        if ($permalink) {
            return $permalink; // stable + already matches your <link> element
        }

        // last resort: still unique + stable
        return home_url('/?p=' . (int) $post_id);
    }

    return $guid;
}

init();
