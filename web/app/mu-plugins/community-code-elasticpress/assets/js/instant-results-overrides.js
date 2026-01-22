( function( wp ) {
	const { hooks, element: el, i18n } = wp;
	const { __ } = i18n;

	/**
	 * Override the Instant Results result component to prefer Yoast meta description
	 * for posts and episodes. Falls back to post_content when not available.
	 */
	hooks.addFilter(
		'ep.InstantResults.Result',
		'community-code/instant-results-excerpt',
		( OriginalComponent ) => ( props ) => {
			const { hit } = props;

			const yoastDesc = hit?._source?.yoast_description;
			const postContent = hit?._source?.post_content;

			return el.createElement( OriginalComponent, {
				...props,
				excerpt: yoastDesc || postContent || '',
			} );
		}
	);
} )( window.wp );
