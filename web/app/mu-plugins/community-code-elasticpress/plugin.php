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
	add_action( 'init', __NAMESPACE__ . '\\register_related_posts_block' );
	add_action( 'pre_get_posts', __NAMESPACE__ . '\\integrate_ep_on_archives' );
	add_action( 'rest_api_init', __NAMESPACE__ . '\\register_ep_proxy_routes' );

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
	add_filter( 'ep_autosuggest_options', __NAMESPACE__ . '\\proxy_autosuggest_through_rest_api' );
	add_filter( 'ep_instant_results_search_endpoint', __NAMESPACE__ . '\\proxy_instant_results_through_rest_api', 10, 2 );
	add_filter( 'ep_instant_results_available', '__return_true' );
}

/**
 * Redirect autosuggest requests through a WordPress REST API proxy.
 *
 * The browser cannot reach http://mtlsproxyhost:9008 directly — it is an
 * internal network alias only reachable from PHP on the server. Without this
 * filter ElasticPress injects that URL into the page HTML and the browser
 * triggers a Mixed Content block (http on an https page).
 *
 * Pointing endpointUrl at our REST proxy means the browser sends a request to
 * the site's own HTTPS origin; WordPress handles it server-side and forwards
 * the query to the internal proxy.
 *
 * @param array $options EP autosuggest JS options.
 * @return array
 */
function proxy_autosuggest_through_rest_api( array $options ) : array {
	$options['endpointUrl'] = rest_url( 'community-code/v1/ep-autosuggest' );
	return $options;
}

/**
 * Register REST API proxy routes for ElasticPress autosuggest and instant results.
 */
function register_ep_proxy_routes() : void {
	register_rest_route(
		'community-code/v1',
		'/ep-autosuggest',
		[
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => __NAMESPACE__ . '\\handle_autosuggest_proxy',
			'permission_callback' => '__return_true',
		]
	);

	register_rest_route(
		'community-code/v1',
		'/ep-search',
		[
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => __NAMESPACE__ . '\\handle_instant_results_proxy',
			'permission_callback' => '__return_true',
		]
	);
}

/**
 * Override the Instant Results search endpoint to route through our REST proxy.
 *
 * EP constructs the upstream URL as {apiHost}{apiEndpoint}. When we return an
 * absolute URL here, EP sets apiHost to '' and apiEndpoint to this URL, so
 * the browser calls our HTTPS WordPress endpoint instead of the internal proxy.
 *
 * @param string $endpoint Default endpoint path.
 * @param string $index    Elasticsearch index name.
 * @return string
 */
function proxy_instant_results_through_rest_api( string $endpoint, string $index ) : string {
	return rest_url( 'community-code/v1/ep-search' );
}

/**
 * Proxy an autosuggest request to the internal Elasticsearch endpoint.
 *
 * ElasticPress's autosuggest JavaScript sends a POST request with the full
 * Elasticsearch query in the body (the search term is already substituted
 * client-side). This handler forwards that body as-is to the internal
 * mtlsproxy — only reachable server-side — and streams the response back.
 *
 * @param \WP_REST_Request $request The incoming REST request.
 * @return \WP_REST_Response|\WP_Error
 */
function handle_autosuggest_proxy( \WP_REST_Request $request ) {
	$ep_host        = defined( 'EP_HOST' ) ? EP_HOST : getenv( 'PANTHEON_SEARCH_HOST' );
	$ep_endpoint_id = getenv( 'PANTHEON_SEARCH_ENDPOINT_ID' );
	$ep_credentials = defined( 'EP_CREDENTIALS' ) ? EP_CREDENTIALS : getenv( 'PANTHEON_SEARCH_CREDENTIALS' );

	if ( ! $ep_host || ! $ep_endpoint_id ) {
		return new \WP_Error( 'ep_proxy_misconfigured', 'ElasticPress proxy is not configured.', [ 'status' => 503 ] );
	}

	$upstream = trailingslashit( $ep_host ) . trailingslashit( $ep_endpoint_id ) . 'autosuggest';

	$args = [
		'method'  => 'POST',
		'timeout' => 5,
		'headers' => [ 'Content-Type' => 'application/json' ],
		'body'    => $request->get_body(),
	];

	if ( $ep_credentials ) {
		$args['headers']['Authorization'] = 'Basic ' . base64_encode( $ep_credentials ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	$response = wp_remote_post( $upstream, $args );

	if ( is_wp_error( $response ) ) {
		return new \WP_Error( 'ep_proxy_error', $response->get_error_message(), [ 'status' => 502 ] );
	}

	$body   = wp_remote_retrieve_body( $response );
	$status = wp_remote_retrieve_response_code( $response );

	return new \WP_REST_Response( json_decode( $body, true ), $status );
}

/**
 * Proxy an Instant Results search request to the internal Elasticsearch endpoint.
 *
 * Instant Results JS sends a GET request with search params as query string args.
 * We forward those to the internal EP host using the ep/v2/search REST path that
 * mtlsproxy exposes, then return the response to the browser over HTTPS.
 *
 * @param \WP_REST_Request $request The incoming REST request.
 * @return \WP_REST_Response|\WP_Error
 */
function handle_instant_results_proxy( \WP_REST_Request $request ) {
	$ep_host        = defined( 'EP_HOST' ) ? EP_HOST : getenv( 'PANTHEON_SEARCH_HOST' );
	$ep_endpoint_id = getenv( 'PANTHEON_SEARCH_ENDPOINT_ID' );
	$ep_credentials = defined( 'EP_CREDENTIALS' ) ? EP_CREDENTIALS : getenv( 'PANTHEON_SEARCH_CREDENTIALS' );

	if ( ! $ep_host || ! $ep_endpoint_id ) {
		return new \WP_Error( 'ep_proxy_misconfigured', 'ElasticPress proxy is not configured.', [ 'status' => 503 ] );
	}

	// Reconstruct the upstream URL with query params forwarded as-is.
	$upstream = trailingslashit( $ep_host ) . 'api/v1/search/posts/' . rawurlencode( $ep_endpoint_id );
	$params   = $request->get_query_params();
	unset( $params['_locale'] ); // WP REST internal param.
	if ( ! empty( $params ) ) {
		$upstream .= '?' . http_build_query( $params );
	}

	$args = [
		'method'  => 'GET',
		'timeout' => 5,
		'headers' => [],
	];

	if ( $ep_credentials ) {
		$args['headers']['Authorization'] = 'Basic ' . base64_encode( $ep_credentials ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	$response = wp_remote_get( $upstream, $args );

	if ( is_wp_error( $response ) ) {
		return new \WP_Error( 'ep_proxy_error', $response->get_error_message(), [ 'status' => 502 ] );
	}

	$body   = wp_remote_retrieve_body( $response );
	$status = wp_remote_retrieve_response_code( $response );

	return new \WP_REST_Response( json_decode( $body, true ), $status );

	// Cloudflare blocks PUT from browser User-Agents, so also register POST for the
	// features endpoint so EP's JS fallback succeeds.
	add_action( 'rest_api_init', __NAMESPACE__ . '\\register_ep_features_post_route', 20 );
}

/**
 * Register a POST alias for the EP features REST route.
 *
 * Cloudflare's bot protection blocks PUT requests from browser User-Agents,
 * causing ElasticPress's JS to fall back to POST. The upstream route only
 * registers PUT, so the POST returns "no route found". This adds a matching
 * POST handler pointing to the same callback.
 */
function register_ep_features_post_route() {
	if ( ! class_exists( '\\ElasticPress\\REST\\Features' ) ) {
		return;
	}
	$controller = new \ElasticPress\REST\Features();
	register_rest_route(
		'elasticpress/v1',
		'/features',
		[
			'methods'             => 'POST',
			'callback'            => [ $controller, 'update_settings' ],
			'permission_callback' => [ $controller, 'check_permission' ],
		]
	);
}

/**
 * Enable ElasticPress integration on main archive queries for better performance.
 *
 * @param WP_Query $query The WP_Query instance.
 */
function integrate_ep_on_archives( $query ) {
	/*
     * Never integrate ElasticPress on feed queries.
	 * This causes PowerPress podcast feeds to have blank GUIDs.
     */
	if ( $query->is_feed() ) {
		return;
	}

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
	$post_args['transcript_content'] = strip_vtt_formatting( wp_strip_all_tags( $transcript_body ) );

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
	// Try to read from disk first to avoid Cloudflare bot challenges on loopback requests.
	$upload_dir      = wp_upload_dir();
	$base_url        = trailingslashit( $upload_dir['baseurl'] );
	$base_path       = trailingslashit( $upload_dir['basedir'] );
	$url_normalized  = preg_replace( '#^https?://#', '', $url );
	$base_normalized = preg_replace( '#^https?://#', '', $base_url );

	if ( str_starts_with( $url_normalized, $base_normalized ) ) {
		$local_path = $base_path . substr( $url_normalized, strlen( $base_normalized ) );
		if ( file_exists( $local_path ) && is_readable( $local_path ) ) {
			$body = file_get_contents( $local_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( $body && strlen( $body ) > 300000 ) {
				$body = substr( $body, 0, 300000 );
			}
			return $body ?: '';
		}
	}

	// Fall back to HTTP for external transcript URLs.
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
 * Strip VTT cue timestamps and metadata, leaving only spoken content.
 *
 * Removes the WEBVTT header, timestamp lines (e.g. 00:00:01.000 --> 00:00:05.000),
 * and cue identifiers so ElasticPress only indexes meaningful words.
 *
 * @param string $vtt Raw VTT text (after wp_strip_all_tags).
 * @return string Plain spoken content.
 */
function strip_vtt_formatting( string $vtt ) : string {
	// Remove WEBVTT header and NOTE/STYLE/REGION blocks.
	$vtt = preg_replace( '/^WEBVTT.*$/m', '', $vtt );

	// Remove timestamp cue lines: 00:00:00.000 --> 00:00:00.000 (with optional position metadata).
	$vtt = preg_replace( '/^\d{2}:\d{2}:\d{2}[\.,]\d{3}\s*-->\s*\d{2}:\d{2}:\d{2}[\.,]\d{3}.*$/m', '', $vtt );

	// Remove standalone numeric cue identifiers.
	$vtt = preg_replace( '/^\d+\s*$/m', '', $vtt );

	// Collapse excess whitespace.
	$vtt = preg_replace( '/\n{2,}/', ' ', $vtt );

	return trim( $vtt );
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

	/**
     * Adjust More Like This parameters for better transcript-based matching
     *
	 * Note: Currently applied to all MLT queries since the related episodes
     * block already filters to episodes-only via ep_find_related_args before
     * this runs.
     */
	$formatted_args['query']['more_like_this']['max_query_terms'] = 50; // Increase from EP default of 12
	$formatted_args['query']['more_like_this']['minimum_should_match'] = '16%'; // Lower from ES default of 30%

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

	// Request one extra to account for filtering out current post
	add_filter( 'ep_find_related_args', $scoped_filter );
	$posts = $feature->find_related( get_the_ID(), $count + 1 );
	remove_filter( 'ep_find_related_args', $scoped_filter );

	// If find_related returns false (e.g., for previews), treat as empty array
	if ( ! is_array( $posts ) ) {
		return '';
	}

	// Exclude current episode from results (in case EP isn't working locally)
	$current_post_id = get_the_ID();
	$posts = array_filter( $posts, static function( $post ) use ( $current_post_id ) {
		return $post->ID !== $current_post_id;
	} );

	// Limit to requested count after filtering
	$posts = array_slice( $posts, 0, $count );

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

/**
 * Register the Related Posts block (server-rendered).
 */
function register_related_posts_block() {
	if ( ! function_exists( 'register_block_type' ) ) {
		return;
	}

	$handle = 'community-code-related-posts-block';

	wp_register_script(
		$handle,
		plugins_url( 'assets/js/related-posts-block.js', __FILE__ ),
		[
			'wp-blocks',
			'wp-element',
			'wp-i18n',
			'wp-components',
			'wp-block-editor',
		],
		filemtime( __DIR__ . '/assets/js/related-posts-block.js' ),
		true
	);

	register_block_type(
		'community-code/related-posts',
		[
			'title' => __( 'Related Posts (ElasticPress)', 'community-code' ),
			'description' => __( 'Show ElasticPress related posts.', 'community-code' ),
			'category' => 'widgets',
			'icon' => 'controls-repeat',
			'supports' => [
				'align' => [ 'wide', 'full' ],
				'html' => false,
			],
			'attributes' => [
				'number' => [
					'type' => 'number',
					'default' => 5,
				],
				'align' => [
					'type' => 'string',
				],
			],
			'render_callback' => __NAMESPACE__ . '\\render_related_posts_block',
			'editor_script' => $handle,
		]
	);
}

/**
 * Render the Related Posts block.
 *
 * @param array $attributes Block attributes.
 * @return string
 */
function render_related_posts_block( array $attributes ) : string {
	if ( ! is_singular( 'post' ) ) {
		return '';
	}

	if ( ! class_exists( '\\ElasticPress\\Features' ) ) {
		return '';
	}

	$feature = \ElasticPress\Features::factory()->get_registered_feature( 'related_posts' );
	if ( empty( $feature ) || ! $feature->is_active() ) {
		return '';
	}

	$count = isset( $attributes['number'] ) ? absint( $attributes['number'] ) : 5;
	$count = $count > 0 ? $count : 5;

	// Constrain related results to posts only for this block.
	$scoped_filter = static function ( $args ) {
		$args['post_type'] = [ 'post' ];
		return $args;
	};

	// Request one extra to account for filtering out current post
	add_filter( 'ep_find_related_args', $scoped_filter );
	$posts = $feature->find_related( get_the_ID(), $count + 1 );
	remove_filter( 'ep_find_related_args', $scoped_filter );

	// If find_related returns false (e.g., for previews), treat as empty array
	if ( ! is_array( $posts ) ) {
		return '';
	}

	// Exclude current post from results (in case EP isn't working locally)
	$current_post_id = get_the_ID();
	$posts = array_filter( $posts, static function( $post ) use ( $current_post_id ) {
		return $post->ID !== $current_post_id;
	} );

	// Limit to requested count after filtering
	$posts = array_slice( $posts, 0, $count );

	if ( empty( $posts ) ) {
		return '';
	}

	$classes = [ 'wp-block-community-code-related-posts' ];
	if ( ! empty( $attributes['align'] ) ) {
		$classes[] = 'align' . $attributes['align'];
	}

	ob_start();
	?>
	<section class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>">
		<ul class="alignfull wp-block-post-template is-layout-flow wp-container-core-post-template-is-layout-flow wp-block-post-template-is-layout-flow">
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
						<div class="related-post__meta">
							<span class="related-post__tags">
								<?php _e( 'topics: ', 'community-code' ); ?>
								<?php echo esc_html( implode( ', ', $tag_labels ) ); ?>
							</span>
						</div>
					<?php else : ?>
						<div class="related-post__meta"></div>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
	</section>
	<?php

	return ob_get_clean();
}

init();
