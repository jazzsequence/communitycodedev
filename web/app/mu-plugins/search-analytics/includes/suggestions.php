<?php
/**
 * Suggested tags: surface frequently searched terms with no matching post_tag.
 *
 * @package CommunityCode\SearchAnalytics
 */

namespace CommunityCode\SearchAnalytics;

/**
 * Return frequently searched terms that have no matching post_tag.
 *
 * Queries the analytics table for terms meeting the minimum search count, then
 * filters out any that already exist as a post_tag (case-insensitive). These are
 * candidates for new tags that reflect real visitor intent.
 *
 * @since 1.2.0
 *
 * @param int $min_count Minimum number of searches for a term to be included. Default 5.
 * @param int $days      Look-back window in days. 0 = all time. Default 30.
 * @return array[] Rows with keys: term (string), count (int). Sorted by count desc.
 */
function get_tag_gaps( int $min_count = 5, int $days = 30 ): array {
	global $wpdb;
	$table = get_table_name();

	if ( $days > 0 ) {
		$since = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
		$where_sql = $wpdb->prepare( 'WHERE searched_at >= %s', $since );
	} else {
		$where_sql = '';
	}

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT term, COUNT(*) AS count FROM {$table} {$where_sql} GROUP BY term HAVING COUNT(*) >= %d ORDER BY count DESC",
			$min_count
		),
		ARRAY_A
	) ?: [];
	// phpcs:enable

	return array_values( array_filter( $rows, function ( array $row ): bool {
		return ! get_term_by( 'name', $row['term'], 'post_tag' );
	} ) );
}

/**
 * Find published posts and episodes whose content matches a search term via ElasticPress.
 *
 * Runs separate queries for posts and episodes so both post types get their own
 * relevance-ranked candidates regardless of which scores higher globally. This
 * ensures that if a blog post is a candidate for a tag, the related episode is
 * always considered too. Results are merged and deduplicated.
 *
 * @since 1.2.0
 *
 * @param string $term  The search term to match against.
 * @param int    $limit Maximum results per post type. Default 5.
 * @return array[] Rows with keys: ID (int), title (string), edit_url (string).
 */
function get_posts_for_term( string $term, int $limit = 5 ): array {
	$base_args = [
		's' => $term,
		'ep_integrate' => true,
		'posts_per_page' => $limit,
		'post_status' => 'publish',
		'no_found_rows' => true,
	];

	$seen = [];
	$results = [];

	foreach ( [ 'post', 'episodes' ] as $type ) {
		$query = new \WP_Query( array_merge( $base_args, [ 'post_type' => $type ] ) );
		foreach ( $query->posts as $post ) {
			if ( isset( $seen[ $post->ID ] ) ) {
				continue;
			}
			$seen[ $post->ID ] = true;
			$results[] = [
				'ID' => $post->ID,
				'title' => $post->post_title,
				'edit_url' => get_edit_post_link( $post->ID, 'raw' ),
			];
		}
	}

	return $results;
}

/**
 * Determine whether the WordPress AI plugin is installed and has valid credentials.
 *
 * @since 1.2.0
 *
 * @return bool
 */
function ai_is_available(): bool {
	return function_exists( '\WordPress\AI\has_valid_ai_credentials' )
		&& \WordPress\AI\has_valid_ai_credentials();
}

/**
 * Suggest a canonical tag slug for each gap term, optionally using the AI plugin.
 *
 * Without AI: returns each term slugified via sanitize_title().
 * With AI: sends the full term list to the AI plugin and asks it to group
 * synonyms and suggest canonical slugs. Falls back to the non-AI path if the
 * AI request fails or returns unparseable output.
 *
 * @since 1.2.0
 *
 * @param array[] $gaps Rows from get_tag_gaps() with keys: term, count.
 * @return array<string, string> Map of raw term → suggested slug.
 */
function normalize_terms_with_ai( array $gaps ): array {
	$fallback = [];
	foreach ( $gaps as $row ) {
		$fallback[ $row['term'] ] = sanitize_title( $row['term'] );
	}

	if ( ! ai_is_available() || empty( $gaps ) ) {
		return $fallback;
	}

	$term_list = implode( ', ', array_column( $gaps, 'term' ) );
	$prompt = sprintf(
		'The following terms were searched on a podcast website about WordPress, open source, and tech communities. ' .
		'For each term, suggest a canonical WordPress tag slug (lowercase, hyphenated). ' .
		'Group obvious synonyms or variants under a single slug. ' .
		'Return valid JSON only — an object mapping each raw term to its suggested slug. ' .
		'Example: {"open source": "open-source", "OSS": "open-source"}. ' .
		'Terms: %s',
		$term_list
	);

	try {
		$service = \WordPress\AI\get_ai_service();
		$text = $service->create_textgen_prompt( $prompt, [
			'temperature' => 0.2,
			'max_tokens'  => 500,
		] )->generate_text();

		// Strip markdown code fences if the model wraps the JSON.
		$text = preg_replace( '/^```(?:json)?\s*|\s*```$/s', '', trim( $text ) );
		$decoded = json_decode( $text, true );

		if ( ! is_array( $decoded ) ) {
			return $fallback;
		}

		// Merge AI suggestions over the fallback so any missing terms get the slugified default.
		foreach ( $decoded as $term => $slug ) {
			$decoded[ $term ] = sanitize_title( $slug );
		}
		return array_merge( $fallback, $decoded );

	} catch ( \Throwable $e ) {
		return $fallback;
	}
}
