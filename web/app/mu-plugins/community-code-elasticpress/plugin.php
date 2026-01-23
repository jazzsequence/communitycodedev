<?php
/**
 * Plugin Name: Community Code ElasticPress Integration
 * Description: Integrates and extends ElasticPress search capabilities into Community + Code.
 * Version: 1.0.0
 * Author: Chris Reynolds
 * Licence: MIT
 * Text Domain: community-code
 */

namespace Community_Code\ElasticPress;

/**
 * Kick things off.
 */
function init() {
	add_action( 'init', __NAMESPACE__ . '\\register_related_episodes_block' );
	add_action( 'pre_get_posts', __NAMESPACE__ . '\\integrate_ep_on_archives' );

	add_filter( 'ep_post_sync_args', __NAMESPACE__ . '\\add_yoast_description_field', 11, 2 );
	add_filter( 'ep_post_sync_args', __NAMESPACE__ . '\\include_episode_transcript_in_index', 15, 2 );
	add_filter( 'ep_post_sync_args_post_prepare_meta', __NAMESPACE__ . '\\normalize_ep_thumbnail_scheme', 20, 2 );
	add_filter( 'ep_prepare_meta_allowed_protected_keys', __NAMESPACE__ . '\\allow_yoast_meta', 10, 2 );
	add_filter( 'ep_prepare_meta_allowed_keys', __NAMESPACE__ . '\\allow_yoast_meta_public', 10, 2 );
	add_filter( 'ep_instant_results_args_schema', __NAMESPACE__ . '\\add_yoast_field_to_instant_results' );
	add_filter( 'ep_search_fields', __NAMESPACE__ . '\\add_transcript_to_search_fields' );
	add_filter( 'ep_related_posts_fields', __NAMESPACE__ . '\\add_transcript_to_related_posts_fields' );
	add_filter( 'ep_post_mapping', __NAMESPACE__ . '\\add_transcript_field_mapping' );
	add_filter( 'ep_formatted_args', __NAMESPACE__ . '\\customize_related_posts_query', 999, 3 );
}

/**
 * Enable ElasticPress integration on main archive queries for better performance.
 *
 * @param WP_Query $query The WP_Query instance.
 */
function integrate_ep_on_archives( $query ) {
	// Admin queries - only integrate if it's a main query
	if ( is_admin() ) {
		if ( ! $query->is_main_query() ) {
			return;
		}
	}

	// Front-end queries
	if ( ! is_admin() ) {
		// Blog page (posts index)
		if ( $query->is_home() && $query->is_main_query() ) {
			$query->set( 'ep_integrate', true );
		}

		// Episodes archive page
		if ( $query->is_post_type_archive( 'episodes' ) && $query->is_main_query() ) {
			$query->set( 'ep_integrate', true );
		}

		// Query Block queries (like homepage episodes list)
		// These are not main queries, so we check for Query Block context
		if ( ! $query->is_main_query() && $query->get( 'post_type' ) === 'episodes' ) {
			$query->set( 'ep_integrate', true );
		}

		// Query Block for posts
		if ( ! $query->is_main_query() && $query->get( 'post_type' ) === 'post' ) {
			$query->set( 'ep_integrate', true );
		}
	}
}

/**
 * Index episode transcripts in a custom field for search without affecting display.
 *
 * @param array $post_args Post args being sent to Elasticsearch.
 * @param int   $post_id   Post ID.
 * @return array
 */
function include_episode_transcript_in_index( array $post_args, int $post_id ) : array {
	$post = get_post( $post_id );
	if ( ! $post || $post->post_type !== 'episodes' ) {
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

	// Store transcript in custom field - searchable but not displayed in excerpts
	$post_args['transcript_content'] = wp_strip_all_tags( $transcript_body );

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

	$data = \powerpress_get_enclosure_data( $post_id, 'episodes' );
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
 * Add Yoast description into the indexed document for posts/episodes.
 *
 * @param array $post_args Post args being sent to Elasticsearch.
 * @param int   $post_id   Post ID.
 * @return array
 */
function add_yoast_description_field( array $post_args, int $post_id ) : array {
	$post = get_post( $post_id );
	if ( ! $post || ! in_array( $post->post_type, [ 'post', 'episodes' ], true ) ) {
		return $post_args;
	}

	$desc = get_yoast_description_value( $post_id );
	if ( ! $desc ) {
		return $post_args;
	}

	$post_args['yoast_description'] = $desc;

	// Force the excerpt source to the Yoast description so Instant Results uses it.
	$post_args['post_content_plain'] = $desc;
	$post_args['post_excerpt'] = $desc;

	return $post_args;
}

/**
 * Fetch Yoast meta description (with fallback to og_description) for a post.
 *
 * @param int $post_id Post ID.
 * @return string
 */
function get_yoast_description_value( int $post_id ) : string {
	$desc = get_post_meta( $post_id, '_yoast_wpseo_metadesc', true );
	if ( $desc ) {
		return $desc;
	}

	$head_json = get_post_meta( $post_id, '_yoast_wpseo_head_json', true );
	if ( is_string( $head_json ) ) {
		$decoded = json_decode( $head_json, true );
		if ( is_array( $decoded ) && ! empty( $decoded['og_description'] ) ) {
			return (string) $decoded['og_description'];
		}
	} elseif ( is_array( $head_json ) && ! empty( $head_json['og_description'] ) ) {
		return (string) $head_json['og_description'];
	}

	return '';
}

/**
 * Include yoast_description in Instant Results response schema.
 *
 * @param array $schema Args schema.
 * @return array
 */
function add_yoast_field_to_instant_results( array $schema ) : array {
	$schema['fields']['yoast_description'] = [
		'type'        => 'string',
		'description' => __( 'Yoast meta description.', 'community-code' ),
		'default'     => '',
	];

	return $schema;
}

/**
 * Add transcript_content to searchable fields for regular search.
 *
 * @param array $fields Search fields.
 * @return array
 */
function add_transcript_to_search_fields( array $fields ) : array {
	$fields[] = 'transcript_content';
	return $fields;
}

/**
 * Add transcript_content to related posts fields for better episode matching.
 *
 * @param array $fields Related posts fields.
 * @return array
 */
function add_transcript_to_related_posts_fields( array $fields ) : array {
	// Ensure we have the default ElasticPress fields
	if ( empty( $fields ) ) {
		$fields = [
			'post_title',
			'post_content',
			'terms.post_tag.name',
			'terms.category.name',
		];
	}

	// Add transcript content for richer similarity matching
	$fields[] = 'transcript_content';

	return $fields;
}

/**
 * Add transcript_content field to ElasticSearch mapping for full-text search.
 *
 * @param array $mapping ElasticSearch mapping array.
 * @return array
 */
function add_transcript_field_mapping( array $mapping ) : array {
	$mapping['mappings']['properties']['transcript_content'] = [
		'type' => 'text',
		'analyzer' => 'standard',
	];

	return $mapping;
}

/**
 * Customize More Like This query parameters for better related episode matching.
 *
 * @param array    $formatted_args Formatted ES query.
 * @param array    $args           WP_Query args.
 * @param WP_Query $wp_query       The WP_Query object.
 * @return array
 */
function customize_related_posts_query( array $formatted_args, array $args, $wp_query ) : array {
	// Only modify More Like This queries (used by related posts)
	if ( empty( $formatted_args['query']['more_like_this'] ) ) {
		return $formatted_args;
	}

	// Adjust More Like This parameters for better transcript-based matching
	// Note: Currently applied to all MLT queries since the related episodes block
	// already filters to episodes-only via ep_find_related_args before this runs
	$formatted_args['query']['more_like_this']['max_query_terms'] = 50; // Increase from EP default of 12
	$formatted_args['query']['more_like_this']['minimum_should_match'] = '20%'; // Lower from ES default of 30%

	// Remove date sorting - let ElasticSearch sort by relevance score
	unset( $formatted_args['sort'] );

	// Boost transcript content field for similarity matching
	if ( isset( $formatted_args['query']['more_like_this']['fields'] ) ) {
		$fields = $formatted_args['query']['more_like_this']['fields'];

		// Add boosting to transcript_content if present
		foreach ( $fields as $key => $field ) {
			if ( $field === 'transcript_content' ) {
				$fields[ $key ] = 'transcript_content^2'; // 2x weight on transcript matches
			}
		}

		$formatted_args['query']['more_like_this']['fields'] = $fields;
	}

	return $formatted_args;
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

	// Always force HTTPS for thumbnails to prevent mixed content issues
	$target_scheme = 'https';

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
 * Allow Yoast meta description to be indexed (normally private meta is skipped).
 *
 * @param array   $keys  Allowed protected keys.
 * @param WP_Post $post  Post object.
 * @return array
 */
function allow_yoast_meta( array $keys, $post ) : array {
	$keys[] = '_yoast_wpseo_metadesc';
	return array_values( array_unique( $keys ) );
}

/**
 * Ensure Yoast meta description is treated as an allowed public key (for completeness).
 *
 * @param array   $keys  Allowed public keys.
 * @param WP_Post $post  Post object.
 * @return array
 */
function allow_yoast_meta_public( array $keys, $post ) : array {
	$keys[] = '_yoast_wpseo_metadesc';
	return array_values( array_unique( $keys ) );
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
		plugins_url( 'assets/js/related-episodes-block.js', __FILE__ ),
		[
			'wp-blocks',
			'wp-element',
			'wp-i18n',
			'wp-components',
			'wp-block-editor',
		],
		filemtime( __DIR__ . '/assets/js/related-episodes-block.js' ),
		true
	);

	register_block_type(
		'community-code/related-episodes',
		[
			'title' => __( 'Related Episodes (ElasticPress)', 'community-code' ),
			'description' => __( 'Show ElasticPress related episodes using transcript content.', 'community-code' ),
			'category' => 'widgets',
			'icon' => 'controls-repeat',
			'supports' => [
				'align' => [ 'wide', 'full' ],
				'html' => false,
			],
			'attributes' => [
				'number' => [
					'type' => 'number',
					'default' => 3,
				],
				'align' => [
					'type' => 'string',
				],
			],
			'render_callback' => __NAMESPACE__ . '\\render_related_episodes_block',
			'editor_script' => $handle,
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
				$tags = get_the_terms( $related_post->ID, 'post_tag' );
				$tag_labels = is_array( $tags ) ? wp_list_pluck( $tags, 'name' ) : [];
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
					<?php if ( ! empty( $tag_labels ) ) : ?>
						<div class="related-episode__meta">
							<span class="related-episode__tags">
								<?php _e( 'topics: ', 'community-code' ); ?>
								<?php echo esc_html( implode( ', ', $tag_labels ) ); ?>
							</span>
						</div>
					<?php else : ?>
						<div class="related-episode__meta"></div>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
	</section>
	<?php

	return ob_get_clean();
}

init();
