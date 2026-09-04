/* global window, document */

/**
 * Require a Yoast SEO meta description before a post can be published or scheduled.
 *
 * Yoast renders the description in a classic metabox rather than the block editor's
 * data store, so the live value only exists in the metabox's hidden input. Yoast's
 * post-edit.js writes to that input's .value directly, which does not touch the DOM
 * attribute, so neither a change event nor a MutationObserver sees it -- polling the
 * input is the only reliable read.
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.data || ! wp.domReady || ! wp.element || ! wp.plugins ) {
		return;
	}

	var LOCK_KEY = 'cc-require-meta-description';
	var NOTICE_ID = 'cc-require-meta-description-notice';
	var INPUT_ID = 'yoast_wpseo_metadesc';
	var POLL_MS = 500;

	var __ = wp.i18n.__;
	var el = wp.element.createElement;

	// The pre-publish panel moved from core/edit-post to core/editor; the old export
	// still resolves but logs a deprecation on every render.
	var PrePublishPanel = ( wp.editor && wp.editor.PluginPrePublishPanel )
		|| ( wp.editPost && wp.editPost.PluginPrePublishPanel );

	var locked = null;

	// Last polled value, shared by the lock and the panel so both read one source.
	// null means the metabox is not in the DOM yet.
	var description = null;
	var listeners = [];

	/**
	 * Read the description the user can actually see in the metabox.
	 *
	 * @return {string|null} Trimmed value, or null when the metabox is not rendered yet.
	 */
	function readDescription() {
		var input = document.getElementById( INPUT_ID );

		return input ? String( input.value ).trim() : null;
	}

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
			editor.lockPostSaving( LOCK_KEY );
			notices.createNotice(
				'warning',
				__( 'A Yoast SEO meta description is required before publishing or scheduling. Add one in the Yoast SEO box below the editor.', 'community-code' ),
				{ id: NOTICE_ID, isDismissible: false }
			);

			return;
		}

		editor.unlockPostSaving( LOCK_KEY );
		notices.removeNotice( NOTICE_ID );
	}

	/**
	 * Subscribe a component to polled changes of the description.
	 *
	 * @return {string|null} Current description.
	 */
	function useDescription() {
		var state = wp.element.useState( description );
		var value = state[ 0 ];
		var setValue = state[ 1 ];

		wp.element.useEffect( function () {
			listeners.push( setValue );
			setValue( description );

			return function () {
				listeners = listeners.filter( function ( listener ) {
					return listener !== setValue;
				} );
			};
		}, [] );

		return value;
	}

	/**
	 * Pre-publish checklist entry explaining the requirement.
	 *
	 * @return {Object|null} Element, or null when there is nothing to report.
	 */
	function MetaDescriptionPanel() {
		var value = useDescription();

		if ( ! PrePublishPanel || value === null ) {
			return null;
		}

		var body = value === ''
			? el(
				wp.components.Notice,
				{ status: 'warning', isDismissible: false },
				__( 'Required before this post can be published or scheduled. Add one in the Yoast SEO box below the editor. It also feeds site search and the syndication that announces new episodes.', 'community-code' )
			)
			: el( 'p', { style: { margin: 0 } }, value );

		return el(
			PrePublishPanel,
			{
				title: __( 'SEO meta description', 'community-code' ),
				initialOpen: true,
				className: 'cc-require-meta-description'
			},
			body
		);
	}

	/**
	 * Poll the metabox, fan the value out to the panel, and set the lock.
	 */
	function check() {
		var value = readDescription();

		if ( value !== description ) {
			description = value;
			listeners.slice().forEach( function ( listener ) {
				listener( value );
			} );
		}

		// Metabox has not rendered yet -- stay out of the way rather than locking
		// a post the editor has not finished loading.
		if ( value === null ) {
			applyLock( false );

			return;
		}

		applyLock( value === '' && shouldGuard() );
	}

	wp.plugins.registerPlugin( 'cc-require-meta-description', { render: MetaDescriptionPanel } );

	wp.domReady( function () {
		check();
		window.setInterval( check, POLL_MS );
	} );
}( window.wp ) );
