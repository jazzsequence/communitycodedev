/* global window, document */

/**
 * Require the fields syndication depends on before a post can be published or scheduled.
 *
 * Two values are checked, and neither lives where you would expect:
 *
 * - The Yoast SEO meta description is rendered in a classic metabox, not the block
 *   editor's data store. Yoast's post-edit.js writes to the hidden input's .value
 *   directly, which does not touch the DOM attribute, so neither a change event nor
 *   a MutationObserver sees it -- polling the input is the only reliable read.
 * - youtube_url is registered post meta, so it is in the editor store and the field
 *   below writes to it. It can also still be edited in the core Custom Fields box,
 *   which is a metabox and so lags the store by one save; that box is read as a
 *   fallback to keep the existing workflow working.
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.data || ! wp.domReady || ! wp.element || ! wp.plugins ) {
		return;
	}

	var LOCK_KEY = 'cc-publish-requirements';
	var NOTICE_ID = 'cc-publish-requirements-notice';
	var METADESC_INPUT_ID = 'yoast_wpseo_metadesc';
	var YOUTUBE_META_KEY = 'youtube_url';
	var POLL_MS = 500;

	var __ = wp.i18n.__;
	var el = wp.element.createElement;

	// These panels moved from core/edit-post to core/editor; the old exports still
	// resolve but log a deprecation on every render.
	var PrePublishPanel = ( wp.editor && wp.editor.PluginPrePublishPanel )
		|| ( wp.editPost && wp.editPost.PluginPrePublishPanel );
	var DocumentSettingPanel = ( wp.editor && wp.editor.PluginDocumentSettingPanel )
		|| ( wp.editPost && wp.editPost.PluginDocumentSettingPanel );

	var locked = null;

	// Last polled values, keyed by requirement id, shared by the lock and the panel
	// so the two cannot disagree. null means "cannot tell yet".
	var values = {};
	var listeners = [];

	/**
	 * Read a value out of the core Custom Fields metabox.
	 *
	 * @param {string} key Meta key to look for.
	 * @return {string|null} Trimmed value, or null when the box holds no such row.
	 */
	function readCustomField( key ) {
		var keyInputs = document.querySelectorAll( '#postcustom input[id^="meta-"][id$="-key"]' );

		for ( var i = 0; i < keyInputs.length; i++ ) {
			if ( String( keyInputs[ i ].value ).trim() !== key ) {
				continue;
			}

			var valueEl = document.getElementById( keyInputs[ i ].id.replace( /-key$/, '-value' ) );

			if ( valueEl ) {
				return String( valueEl.value ).trim();
			}
		}

		// The "Add New Custom Field" row, before it has ever been saved.
		var pendingKey = '';
		var newKeyInput = document.getElementById( 'metakeyinput' );
		var newKeySelect = document.getElementById( 'metakeyselect' );

		if ( newKeyInput && newKeyInput.value ) {
			pendingKey = String( newKeyInput.value ).trim();
		} else if ( newKeySelect && newKeySelect.value && newKeySelect.value !== '#NONE#' ) {
			pendingKey = String( newKeySelect.value ).trim();
		}

		if ( pendingKey === key ) {
			var newValue = document.getElementById( 'metavalue' );

			if ( newValue ) {
				return String( newValue.value ).trim();
			}
		}

		return null;
	}

	/**
	 * Read the Yoast description the user can actually see in the metabox.
	 *
	 * @return {string|null} Trimmed value, or null when the metabox is not rendered yet.
	 */
	function readMetaDescription() {
		var input = document.getElementById( METADESC_INPUT_ID );

		return input ? String( input.value ).trim() : null;
	}

	/**
	 * Read the YouTube URL from post meta, falling back to the Custom Fields box.
	 *
	 * Strictly the meta value: a YouTube embed in the post content does not count.
	 *
	 * @return {string|null} Trimmed value, or null when the editor store is not ready.
	 */
	function readYoutubeUrl() {
		var editor = wp.data.select( 'core/editor' );

		if ( ! editor ) {
			return null;
		}

		var meta = editor.getEditedPostAttribute( 'meta' ) || {};
		var value = typeof meta[ YOUTUBE_META_KEY ] === 'string' ? meta[ YOUTUBE_META_KEY ].trim() : '';

		if ( value !== '' ) {
			return value;
		}

		var fromBox = readCustomField( YOUTUBE_META_KEY );

		return fromBox === null ? '' : fromBox;
	}

	var REQUIREMENTS = [
		{
			id: 'meta-description',
			label: __( 'SEO meta description', 'community-code' ),
			read: readMetaDescription,
			missing: __( 'Required before this post can be published or scheduled. Add one in the Yoast SEO box below the editor. It also feeds site search and the syndication that announces new episodes.', 'community-code' )
		},
		{
			id: 'youtube-url',
			label: __( 'YouTube URL', 'community-code' ),
			read: readYoutubeUrl,
			missing: __( 'Required before this post can be published or scheduled. Add it in the YouTube panel in the sidebar, or as the youtube_url custom field.', 'community-code' )
		}
	];

	/**
	 * Whether the publish/schedule sidebar is currently open.
	 *
	 * The selector moved from core/edit-post to core/editor, so check both.
	 *
	 * @return {boolean} True when the panel is open.
	 */
	function isPublishSidebarOpened() {
		var stores = [ 'core/editor', 'core/edit-post' ];

		for ( var i = 0; i < stores.length; i++ ) {
			var store = wp.data.select( stores[ i ] );

			if ( store && typeof store.isPublishSidebarOpened === 'function' ) {
				return store.isPublishSidebarOpened();
			}
		}

		return false;
	}

	/**
	 * Whether the lock should be engaged right now.
	 *
	 * lockPostSaving disables Save draft as well as Publish, so hold it back until
	 * the user actually opens Publish/Schedule. That keeps drafting unblocked. If
	 * the pre-publish panel is switched off there is no click to intercept, so the
	 * lock has to stand permanently or the gate is trivially bypassed.
	 *
	 * @return {boolean} True when saving should be locked.
	 */
	function shouldGuard() {
		var editor = wp.data.select( 'core/editor' );

		if ( ! editor ) {
			return false;
		}

		if ( typeof editor.isPublishSidebarEnabled === 'function' && ! editor.isPublishSidebarEnabled() ) {
			return true;
		}

		if ( isPublishSidebarOpened() ) {
			return true;
		}

		// Already live or already scheduled: every save from here re-publishes.
		var status = editor.getCurrentPostAttribute( 'status' );

		return status === 'publish' || status === 'future';
	}

	/**
	 * Requirements that are readable and empty.
	 *
	 * @return {Array} Requirement definitions that are not satisfied.
	 */
	function getMissing() {
		return REQUIREMENTS.filter( function ( requirement ) {
			return values[ requirement.id ] === '';
		} );
	}

	/**
	 * Engage or release the lock, only on an actual change of state.
	 *
	 * The notice renders in the editor canvas, which the publish sidebar covers, so
	 * it is really only visible when updating an already published post. The
	 * pre-publish panel carries the message at the point the user is blocked.
	 *
	 * @param {boolean} shouldLock Whether saving should be locked.
	 */
	function applyLock( shouldLock ) {
		if ( shouldLock === locked ) {
			return;
		}

		locked = shouldLock;

		var editor = wp.data.dispatch( 'core/editor' );
		var notices = wp.data.dispatch( 'core/notices' );

		if ( shouldLock ) {
			var labels = getMissing().map( function ( requirement ) {
				return requirement.label;
			} ).join( ', ' );

			editor.lockPostSaving( LOCK_KEY );
			notices.createNotice(
				'warning',
				__( 'Missing before this post can be published or scheduled:', 'community-code' ) + ' ' + labels,
				{ id: NOTICE_ID, isDismissible: false }
			);

			return;
		}

		editor.unlockPostSaving( LOCK_KEY );
		notices.removeNotice( NOTICE_ID );
	}

	/**
	 * Subscribe a component to polled changes.
	 *
	 * @return {Object} Current values keyed by requirement id.
	 */
	function useValues() {
		var state = wp.element.useState( values );
		var value = state[ 0 ];
		var setValue = state[ 1 ];

		wp.element.useEffect( function () {
			listeners.push( setValue );
			setValue( values );

			return function () {
				listeners = listeners.filter( function ( listener ) {
					return listener !== setValue;
				} );
			};
		}, [] );

		return value;
	}

	/**
	 * Sidebar field for the YouTube URL, writing to the registered post meta.
	 *
	 * @return {Object|null} Element, or null when the panel API is unavailable.
	 */
	function YoutubeUrlField() {
		var meta = wp.data.useSelect( function ( select ) {
			var editor = select( 'core/editor' );

			return ( editor && editor.getEditedPostAttribute( 'meta' ) ) || {};
		}, [] );
		var editPost = wp.data.useDispatch( 'core/editor' ).editPost;

		if ( ! DocumentSettingPanel ) {
			return null;
		}

		return el(
			DocumentSettingPanel,
			{
				name: 'cc-youtube-url',
				title: __( 'YouTube', 'community-code' ),
				className: 'cc-publish-requirements'
			},
			el( wp.components.TextControl, {
				__nextHasNoMarginBottom: true,
				label: __( 'YouTube URL', 'community-code' ),
				help: __( 'Required before publishing or scheduling. Stored as the youtube_url custom field.', 'community-code' ),
				type: 'url',
				value: typeof meta[ YOUTUBE_META_KEY ] === 'string' ? meta[ YOUTUBE_META_KEY ] : '',
				onChange: function ( value ) {
					var update = {};
					update[ YOUTUBE_META_KEY ] = value;
					editPost( { meta: update } );
				}
			} )
		);
	}

	/**
	 * Pre-publish checklist entry explaining what is missing and why.
	 *
	 * @return {Object|null} Element, or null when there is nothing to report.
	 */
	function RequirementsPanel() {
		var current = useValues();

		if ( ! PrePublishPanel ) {
			return null;
		}

		var rows = REQUIREMENTS.filter( function ( requirement ) {
			return current[ requirement.id ] !== null && current[ requirement.id ] !== undefined;
		} ).map( function ( requirement ) {
			var value = current[ requirement.id ];

			return el(
				'div',
				{ key: requirement.id, style: { marginBottom: '12px' } },
				el( 'strong', { style: { display: 'block', marginBottom: '4px' } }, requirement.label ),
				value === ''
					? el( wp.components.Notice, { status: 'warning', isDismissible: false }, requirement.missing )
					: el( 'p', { style: { margin: 0, wordBreak: 'break-word' } }, value )
			);
		} );

		if ( ! rows.length ) {
			return null;
		}

		return el(
			PrePublishPanel,
			{
				title: __( 'Publish requirements', 'community-code' ),
				initialOpen: true,
				className: 'cc-publish-requirements'
			},
			rows
		);
	}

	/**
	 * Poll every requirement, fan changes out to the panel, and set the lock.
	 */
	function check() {
		var changed = false;

		REQUIREMENTS.forEach( function ( requirement ) {
			var value = requirement.read();

			if ( values[ requirement.id ] !== value ) {
				values[ requirement.id ] = value;
				changed = true;
			}
		} );

		if ( changed ) {
			// New object identity, or useState will not re-render.
			values = Object.assign( {}, values );
			listeners.slice().forEach( function ( listener ) {
				listener( values );
			} );
		}

		applyLock( getMissing().length > 0 && shouldGuard() );
	}

	wp.plugins.registerPlugin( 'cc-publish-requirements', {
		render: function () {
			return el( wp.element.Fragment, null, el( YoutubeUrlField ), el( RequirementsPanel ) );
		}
	} );

	wp.domReady( function () {
		check();
		window.setInterval( check, POLL_MS );
	} );
}( window.wp ) );
