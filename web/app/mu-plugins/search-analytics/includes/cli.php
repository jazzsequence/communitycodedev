<?php
/**
 * WP-CLI command for suggested tags from search analytics.
 *
 * @package CommunityCode\SearchAnalytics
 */

namespace CommunityCode\SearchAnalytics\CLI;

use function CommunityCode\SearchAnalytics\get_tag_gaps;
use function CommunityCode\SearchAnalytics\get_posts_for_term;
use function CommunityCode\SearchAnalytics\normalize_terms_with_ai;
use function CommunityCode\SearchAnalytics\ai_is_available;

/**
 * Find and optionally act on frequently searched terms with no matching post_tag.
 *
 * ## EXAMPLES
 *
 *     # List suggested tags from the last 30 days with a minimum of 5 searches
 *     wp cc-analytics tag-gaps
 *
 *     # Use a lower threshold and wider window
 *     wp cc-analytics tag-gaps --min-count=2 --days=90
 *
 *     # Create the tags and apply them to matching posts (dry-run without --apply)
 *     wp cc-analytics tag-gaps --apply
 *
 *     # Output as JSON
 *     wp cc-analytics tag-gaps --format=json
 */
class Tag_Gaps_Command extends \WP_CLI_Command {

	/**
	 * List search terms that are frequently searched but have no matching post_tag.
	 *
	 * ## OPTIONS
	 *
	 * [--min-count=<n>]
	 * : Minimum number of searches for a term to be included.
	 * ---
	 * default: 5
	 * ---
	 *
	 * [--days=<n>]
	 * : Look-back window in days. 0 = all time.
	 * ---
	 * default: 30
	 * ---
	 *
	 * [--apply]
	 * : Create the suggested tags and apply them to EP-matched posts.
	 *   Without this flag the command runs as a dry run.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Named arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$min_count = (int) \WP_CLI\Utils\get_flag_value( $assoc_args, 'min-count', 5 );
		$days = (int) \WP_CLI\Utils\get_flag_value( $assoc_args, 'days', 30 );
		$apply = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'apply', false );
		$format = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

		$gaps = get_tag_gaps( $min_count, $days );

		if ( empty( $gaps ) ) {
			\WP_CLI::success( 'No tag gaps found for the given criteria.' );
			return;
		}

		$using_ai = ai_is_available();
		if ( $using_ai ) {
			\WP_CLI::log( 'AI plugin active — using AI-assisted term normalization.' );
		}

		$slugs = normalize_terms_with_ai( $gaps );
		$rows = [];

		foreach ( $gaps as $gap ) {
			$term = $gap['term'];
			$posts = get_posts_for_term( $term );
			$rows[] = [
				'term' => $term,
				'count' => (int) $gap['count'],
				'canonical' => $slugs[ $term ] ?? sanitize_title( $term ),
				'candidate_posts' => count( $posts ),
			];
		}

		\WP_CLI\Utils\format_items( $format, $rows, [ 'term', 'count', 'canonical', 'candidate_posts' ] );

		if ( ! $apply ) {
			\WP_CLI::log( '' );
			\WP_CLI::log( 'Dry run. Pass --apply to create tags and apply them to matching posts.' );
			return;
		}

		\WP_CLI::log( '' );
		foreach ( $gaps as $gap ) {
			$term = $gap['term'];
			$slug = $slugs[ $term ] ?? sanitize_title( $term );

			$result = wp_insert_term( $term, 'post_tag', [ 'slug' => $slug ] );
			if ( is_wp_error( $result ) ) {
				\WP_CLI::warning( "Skipped \"{$term}\": " . $result->get_error_message() );
				continue;
			}

			$tag_id = $result['term_id'];
			$posts = get_posts_for_term( $term );
			$applied = 0;
			foreach ( $posts as $post ) {
				wp_set_post_tags( $post['ID'], [ $tag_id ], true );
				$applied++;
			}

			\WP_CLI::success( "Created tag \"{$term}\" (slug: {$slug}), applied to {$applied} post(s)." );
		}
	}
}
