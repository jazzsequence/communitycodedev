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
	add_action( 'init', __NAMESPACE__ . '\\register_related_episodes_block' );
	add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\enqueue_instant_results_overrides' );

	add_filter( 'default_content', __NAMESPACE__ . '\\set_episode_default_content', 10, 2 );
	add_filter( 'enter_title_here', __NAMESPACE__ . '\\filter_episode_title_placeholder', 10, 2 );
	add_filter( 'webpc_dir_name', __NAMESPACE__ . '\\filter_webpc_upload_path', 10, 2 );
	add_filter( 'ep_post_sync_args', __NAMESPACE__ . '\\include_episode_transcript_in_index', 15, 2 );
	add_filter( 'ep_post_sync_args_post_prepare_meta', __NAMESPACE__ . '\\normalize_ep_thumbnail_scheme', 20, 2 );

	add_filter( 'powerpress_post_types', function( $post_types ) {
		$post_types[] = 'episodes'; // Allow PowerPress fields on episodes
		return $post_types;
	});
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
<p>Episode notes</p>
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
 * Append transcript text to the indexed post content for episodes so EP related content can use it.
 *
 * @param array $post_args Post args being sent to Elasticsearch.
 * @param int   $post_id   Post ID.
 * @return array
 */
function include_episode_transcript_in_index( array $post_args, int $post_id ) : array {
	if ( get_post_type( $post_id ) !== 'episodes' ) {
		return $post_args;
	}

	$transcript_url = get_episode_transcript_url( $post_id );
	if ( ! $transcript_url ) {
		return $post_args;
	}

	$transcript_body = fetch_transcript_body( $transcript_url );
	if ( ! $transcript_body ) {
		return $post_args;
	}

	$post_args['post_content'] .= "\n\n" . wp_strip_all_tags( $transcript_body );

	return $post_args;
}

/**
 * Retrieve the transcript URL from PowerPress enclosure data.
 *
 * @param int $post_id Post ID.
 * @return string
 */
function get_episode_transcript_url( int $post_id ) : string {
	if ( ! function_exists( '\\powerpress_get_enclosure_data' ) ) {
		return '';
	}

	$data = \powerpress_get_enclosure_data( $post_id, 'podcast' );
	if ( empty( $data['pci_transcript_url'] ) ) {
		return '';
	}

	return esc_url_raw( $data['pci_transcript_url'] );
}

/**
 * Fetch the contents of a transcript file.
 *
 * @param string $url Transcript URL.
 * @return string
 */
function fetch_transcript_body( string $url ) : string {
	$response = wp_remote_get( $url, [
		'timeout' => 8,
	] );

	if ( is_wp_error( $response ) ) {
		return '';
	}

	$body = wp_remote_retrieve_body( $response );
	if ( ! $body ) {
		return '';
	}

	// Avoid indexing extremely large files.
	if ( strlen( $body ) > 300000 ) {
		$body = substr( $body, 0, 300000 );
	}

	return $body;
}

/**
 * Ensure EP thumbnails use the site's scheme (prevents http thumbnails in https UIs).
 *
 * @param array $post_args Post args being sent to Elasticsearch.
 * @param int   $post_id   Post ID.
 * @return array
 */
function normalize_ep_thumbnail_scheme( array $post_args, int $post_id ) : array {
	if ( empty( $post_args['thumbnail'] ) ) {
		return $post_args;
	}

	$target_scheme = parse_url( home_url(), PHP_URL_SCHEME );
	if ( ! $target_scheme ) {
		return $post_args;
	}

	if ( ! empty( $post_args['thumbnail']['src'] ) ) {
		$post_args['thumbnail']['src'] = set_url_scheme( $post_args['thumbnail']['src'], $target_scheme );
	}

	if ( ! empty( $post_args['thumbnail']['srcset'] ) && is_string( $post_args['thumbnail']['srcset'] ) ) {
		$post_args['thumbnail']['srcset'] = preg_replace_callback(
			'#https?://[^\\s,"]+#',
			static function ( $matches ) use ( $target_scheme ) {
				return set_url_scheme( $matches[0], $target_scheme );
			},
			$post_args['thumbnail']['srcset']
		);
	}

	return $post_args;
}

/**
 * Enqueue Instant Results overrides (front-end).
 */
function enqueue_instant_results_overrides() {
	if ( ! wp_script_is( 'elasticpress-instant-results', 'registered' ) ) {
		return;
	}

	wp_enqueue_script(
		'community-code-instant-results-overrides',
		plugins_url( 'instant-results-overrides.js', __FILE__ ),
		[ 'elasticpress-instant-results', 'wp-hooks', 'wp-element', 'wp-i18n' ],
		filemtime( __DIR__ . '/instant-results-overrides.js' ),
		true
	);
}

/**
 * Register the Related Episodes block (server-rendered).
 */
function register_related_episodes_block() {
	if ( ! function_exists( 'register_block_type' ) ) {
		return;
	}

	$handle = 'community-code-related-episodes-block';

	wp_register_script(
		$handle,
		plugins_url( 'related-episodes-block.js', __FILE__ ),
		[
			'wp-blocks',
			'wp-element',
			'wp-i18n',
			'wp-components',
			'wp-block-editor',
		],
		filemtime( __DIR__ . '/related-episodes-block.js' ),
		true
	);

	register_block_type(
		'community-code/related-episodes',
		[
			'title'           => __( 'Related Episodes (ElasticPress)', 'community-code' ),
			'description'     => __( 'Show ElasticPress related episodes using transcript content.', 'community-code' ),
			'category'        => 'widgets',
			'icon'            => 'controls-repeat',
			'supports'        => [
				'align' => [ 'wide', 'full' ],
				'html'  => false,
			],
			'attributes'      => [
				'number' => [
					'type'    => 'number',
					'default' => 3,
				],
				'align'  => [
					'type' => 'string',
				],
			],
			'render_callback' => __NAMESPACE__ . '\\render_related_episodes_block',
			'editor_script'   => $handle,
		]
	);
}

/**
 * Render the Related Episodes block.
 *
 * @param array $attributes Block attributes.
 * @return string
 */
function render_related_episodes_block( array $attributes ) : string {
	if ( ! is_singular( 'episodes' ) ) {
		return '';
	}

	if ( ! class_exists( '\\ElasticPress\\Features' ) ) {
		return '';
	}

	$feature = \ElasticPress\Features::factory()->get_registered_feature( 'related_posts' );
	if ( empty( $feature ) || ! $feature->is_active() ) {
		return '';
	}

	$count = isset( $attributes['number'] ) ? absint( $attributes['number'] ) : 3;
	$count = $count > 0 ? $count : 3;

	// Constrain related results to episodes only for this block.
	$scoped_filter = static function ( $args ) {
		$args['post_type'] = [ 'episodes' ];
		return $args;
	};

	add_filter( 'ep_find_related_args', $scoped_filter );
	$posts = $feature->find_related( get_the_ID(), $count );
	remove_filter( 'ep_find_related_args', $scoped_filter );

	if ( empty( $posts ) ) {
		return '';
	}

	$classes = [ 'wp-block-community-code-related-episodes' ];
	if ( ! empty( $attributes['align'] ) ) {
		$classes[] = 'align' . $attributes['align'];
	}

	ob_start();
	?>
	<section class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>">
		<ul class="alignfull wp-block-post-template is-layout-flow wp-container-core-post-template-is-layout-3ee800f6 wp-block-post-template-is-layout-flow">
			<?php foreach ( $posts as $related_post ) : ?>
				<?php
				$date_display = get_the_date( '', $related_post->ID );
				$tags         = get_the_terms( $related_post->ID, 'post_tag' );
				$tag_labels   = is_array( $tags ) ? wp_list_pluck( $tags, 'name' ) : [];
				?>
				<li>
					<h3 class="wp-block-post-title has-large-font-size"><a href="<?php echo esc_url( get_permalink( $related_post->ID ) ); ?>">
						<?php echo esc_html( get_the_title( $related_post->ID ) ); ?>
					</a></h3>
					<?php if ( $date_display ) : ?>
						<div class="datetime has-text-align-right wp-block-post-date">
							<time datetime="<?php echo esc_attr( get_the_date( 'c', $related_post->ID ) ); ?>">
								<a href="<?php echo esc_url( get_permalink( $related_post->ID ) ); ?>"><?php echo esc_html( $date_display ); ?></a>
							</time>
						</div>
                    <?php endif; ?>
					<div class="related-episode__meta">
						<?php if ( ! empty( $tag_labels ) ) : ?>
							<span class="related-episode__tags">
								<?php _e( 'topics: ', 'community-code' ); ?>
								<?php echo esc_html( implode( ', ', $tag_labels ) ); ?>
							</span>
						<?php endif; ?>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>
	</section>
	<?php

	return ob_get_clean();
}

init();
