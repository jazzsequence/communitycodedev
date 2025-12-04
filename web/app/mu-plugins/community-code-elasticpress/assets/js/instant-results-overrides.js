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
			const yoastDesc = hit?._source?.yoast_description
				|| ( Array.isArray( yoastMeta )
					? yoastMeta[0]?.raw || yoastMeta[0]?.value || yoastMeta[0]
					: yoastMeta );

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

// Expand the Tags facet by default (click its toggle if collapsed).
window.addEventListener( 'DOMContentLoaded', () => {
	setTimeout( () => {
		const buttons = Array.from( document.querySelectorAll( '.ep-search-panel__button' ) );
		const tagButton = buttons.find( ( btn ) => btn.textContent.toLowerCase().includes( 'tag' ) );
		if ( tagButton && tagButton.getAttribute( 'aria-expanded' ) === 'false' ) {
			tagButton.click();
		}
	}, 300 );

	setTimeout( () => {
		const ensureChecked = ( value ) => {
			document
				.querySelectorAll( `input[type="checkbox"][value="${ value }"]` )
				.forEach( ( input ) => {
					if ( ! input.checked ) {
						input.click();
					}
				} );
		};

		const ensureUnchecked = ( value ) => {
			document
				.querySelectorAll( `input[type="checkbox"][value="${ value }"]` )
				.forEach( ( input ) => {
					if ( input.checked ) {
						input.click();
					}
				} );
		};

		ensureChecked( 'post' );
		ensureChecked( 'episodes' );
		ensureUnchecked( 'attachment' );
		ensureUnchecked( 'media' );
	}, 500 );
} );
