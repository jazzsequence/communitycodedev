/* global window, document */

/**
 * Require a Yoast SEO meta description before a post can be published or scheduled.
 *
 * Yoast renders the description in a classic metabox rather than the block editor's
 * data store, so the live value only exists in the metabox's hidden input. Yoast's
 * React snippet editor writes to that input's .value directly, which does not touch
 * the DOM attribute, so neither a change event nor a MutationObserver sees it --
 * polling the input is the only reliable read.
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.data || ! wp.domReady ) {
		return;
	}

	var LOCK_KEY = 'cc-require-meta-description';
	var NOTICE_ID = 'cc-require-meta-description-notice';
	var INPUT_ID = 'yoast_wpseo_metadesc';
	var POLL_MS = 500;

	var __ = wp.i18n.__;
	var locked = null;

	/**
	 * Read the description the user can actually see in the metabox.
	 *
	 * @return {string|null} Trimmed value, or null when the metabox is not rendered yet.
	 */
	function getDescription() {
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
	 * @param {boolean} shouldLock Whether saving should be locked.
	 */
	function apply( shouldLock ) {
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
				__( 'Add a Yoast SEO meta description before publishing or scheduling. It feeds site search and the syndication that announces new episodes.', 'community-code' ),
				{ id: NOTICE_ID, isDismissible: false }
			);

			return;
		}

		editor.unlockPostSaving( LOCK_KEY );
		notices.removeNotice( NOTICE_ID );
	}

	function check() {
		var description = getDescription();

		// Metabox has not rendered yet -- stay out of the way rather than locking
		// a post the editor has not finished loading.
		if ( description === null ) {
			apply( false );

			return;
		}

		apply( description === '' && shouldGuard() );
	}

	wp.domReady( function () {
		check();
		window.setInterval( check, POLL_MS );
	} );
}( window.wp ) );
