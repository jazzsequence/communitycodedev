( function( blocks, element, i18n, components, blockEditor ) {
	const { registerBlockType } = blocks;
	const { Fragment, createElement: el } = element;
	const { __, sprintf } = i18n;
	const { Placeholder, PanelBody, RangeControl } = components;
	const { InspectorControls } = blockEditor;

	registerBlockType( 'community-code/related-posts', {
		title: __( 'Related Posts (ElasticPress)', 'community-code' ),
		description: __(
			'Show ElasticPress related posts.',
			'community-code'
		),
		icon: 'controls-repeat',
		category: 'widgets',
		supports: {
			align: [ 'wide', 'full' ],
			html: false,
		},
		attributes: {
			number: {
				type: 'number',
				default: 5,
			},
			align: {
				type: 'string',
			},
		},
		edit: ( { attributes, setAttributes } ) => {
			const number = attributes.number || 5;

			return el(
				Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Settings', 'community-code' ) },
						el( RangeControl, {
							label: __( 'Number of posts', 'community-code' ),
							min: 1,
							max: 12,
							value: number,
							onChange: ( value ) =>
								setAttributes( { number: value || 1 } ),
						} )
					)
				),
				el(
					Placeholder,
					{
						icon: 'controls-repeat',
						label: __(
							'Related Posts (ElasticPress)',
							'community-code'
						),
						instructions: __(
							'Displays related posts using ElasticPress.',
							'community-code'
						),
					},
					el(
						'p',
						null,
						sprintf(
							__(
								'Showing %d related posts on the front end.',
								'community-code'
							),
							number
						)
					)
				)
			);
		},
		save: () => null,
	} );
} )( window.wp.blocks, window.wp.element, window.wp.i18n, window.wp.components, window.wp.blockEditor );
