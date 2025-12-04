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
	add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\enqueue_instant_results_overrides' );

	add_filter( 'ep_post_sync_args', __NAMESPACE__ . '\\include_episode_transcript_in_index', 15, 2 );
	add_filter( 'ep_post_sync_args_post_prepare_meta', __NAMESPACE__ . '\\normalize_ep_thumbnail_scheme', 20, 2 );
	add_filter( 'ep_prepare_meta_allowed_protected_keys', __NAMESPACE__ . '\\allow_yoast_meta', 10, 2 );
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
 * Enqueue Instant Results overrides (front-end).
 */
function enqueue_instant_results_overrides() {
	if ( ! wp_script_is( 'elasticpress-instant-results', 'registered' ) ) {
		return;
	}

	wp_enqueue_script(
		'community-code-instant-results-overrides',
		plugins_url( 'assets/js/instant-results-overrides.js', __FILE__ ),
		[ 'elasticpress-instant-results', 'wp-hooks', 'wp-element', 'wp-i18n' ],
		filemtime( __DIR__ . '/assets/js/instant-results-overrides.js' ),
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
                    <?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
	</section>
	<?php

	return ob_get_clean();
}

init();
