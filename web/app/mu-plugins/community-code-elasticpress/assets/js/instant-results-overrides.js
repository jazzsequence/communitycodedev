( function( wp ) {
	const { hooks, element: el, i18n } = wp;
	const { __ } = i18n;

	/**
	 * Override the Instant Results result component to prefer Yoast meta description
	 * for posts and episodes. Falls back to the default excerpt when not available.
	 */
	hooks.addFilter(
		'ep.InstantResults.Result',
		'community-code/instant-results-excerpt',
		( OriginalComponent ) => ( props ) => {
			const { hit, excerpt } = props;

			const meta = hit?._source?.meta || {};
			const yoastMeta = meta._yoast_wpseo_metadesc;
			const yoastDesc = Array.isArray( yoastMeta )
				? yoastMeta[0]?.raw || yoastMeta[0]?.value || yoastMeta[0]
				: yoastMeta;

			if ( yoastDesc ) {
				return el.createElement( OriginalComponent, {
					...props,
					excerpt: yoastDesc,
				} );
			}

			return el.createElement( OriginalComponent, {
				...props,
				excerpt,
			} );
		}
	);
} )( window.wp );
