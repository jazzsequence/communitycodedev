( function( wp ) {
	const { hooks, element: el, i18n } = wp;
	const { __ } = i18n;

	/**
	 * Override the Instant Results result component to prefer Yoast meta description
	 * for episodes. Falls back to the default excerpt when not available.
	 */
	hooks.addFilter(
		'ep.InstantResults.Result',
		'community-code/instant-results-excerpt',
		( OriginalComponent ) => ( props ) => {
			const { hit, excerpt } = props;

			if (
				hit &&
				hit._source &&
				hit._source.post_type === 'episodes' &&
				hit._source.meta &&
				hit._source.meta._yoast_wpseo_metadesc &&
				Array.isArray( hit._source.meta._yoast_wpseo_metadesc ) &&
				hit._source.meta._yoast_wpseo_metadesc[0]
			) {
				return el.createElement( OriginalComponent, {
					...props,
					excerpt: hit._source.meta._yoast_wpseo_metadesc[0],
				} );
			}

			return el.createElement( OriginalComponent, {
				...props,
				excerpt,
			} );
		}
	);
} )( window.wp );
