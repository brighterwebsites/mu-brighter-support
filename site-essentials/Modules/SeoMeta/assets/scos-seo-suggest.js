/**
 * SCOS SEO Suggest — post editor AI suggestion UI
 *
 * Two independent modal flows:
 *
 *   SEO Meta modal (single-step, 3 sections):
 *     "Suggest with AI" button → calls scos/suggest-seo-meta → modal with
 *     Breadcrumb / Title / Description pill sections. Each section is
 *     independent — user can pick from any or skip. Pick-to-fill updates the
 *     corresponding field and dispatches an 'input' event so the existing
 *     meta-box.js char counters and progress bars update immediately.
 *
 *   TLDR modal (single-step with context notice):
 *     "Suggest TLDR" button → checks ScosSeoSuggest.intentGoalText:
 *       - set:   blue info notice "Writing TLDR to answer: [goal]"
 *       - empty: amber nudge "Set a Search Intent Goal first for a more
 *                targeted TLDR. Continuing without it."
 *     → calls scos/suggest-tldr → pill options (full sentence text per pill)
 *     → pick-to-fill sets #scos_seo_tldr
 *
 * v1.0 | 2026-06-24
 * v1.1 | 2026-07-17 — fillField() now syncs via the TinyMCE API when the target is
 *                      a wp_editor() instance (scos_seo_tldr switched from textarea).
 */

( function () {
	'use strict';

	var cfg = window.ScosSeoSuggest;
	if ( ! cfg ) return;

	// -------------------------------------------------------------------------
	// Shared modal element
	// -------------------------------------------------------------------------

	var modal = document.createElement( 'div' );
	modal.id              = 'scos-seo-suggest-modal';
	modal.style.cssText   = 'display:none;position:fixed;top:0;left:0;width:100%;height:100%;'
		+ 'background:rgba(0,0,0,.45);z-index:100000;align-items:center;justify-content:center;';
	document.body.appendChild( modal );

	// -------------------------------------------------------------------------
	// Utilities
	// -------------------------------------------------------------------------

	function escHtml( str ) {
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#039;' );
	}

	function escAttr( str ) {
		return escHtml( str );
	}

	function setLoading( on ) {
		var btn = document.getElementById( 'scos-seo-suggest-btn' );
		var tldrBtn = document.getElementById( 'scos-tldr-suggest-btn' );
		if ( btn ) btn.disabled = on;
		if ( tldrBtn ) tldrBtn.disabled = on;
	}

	function closeModal() {
		modal.style.display = 'none';
		modal.innerHTML = '';
		setLoading( false );
	}

	// Close on backdrop click.
	modal.addEventListener( 'click', function ( e ) {
		if ( e.target === modal ) closeModal();
	} );

	// -------------------------------------------------------------------------
	// Fill actions — fire 'input' so meta-box.js char counters update.
	// -------------------------------------------------------------------------

	function fillField( id, value ) {
		// scos_seo_tldr is a TinyMCE (wp_editor teeny) instance — its visible content
		// lives in the TinyMCE iframe, not the underlying textarea's .value, so it
		// needs to be set (and marked dirty/saved) through the TinyMCE API instead.
		var editor = window.tinymce && window.tinymce.get ? window.tinymce.get( id ) : null;
		if ( editor && ! editor.isHidden() ) {
			editor.setContent( value );
			editor.save(); // sync back to the underlying textarea before submit
			editor.fire( 'change' );
			return;
		}

		var el = document.getElementById( id );
		if ( ! el ) return;
		el.value = value;
		el.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	}

	// -------------------------------------------------------------------------
	// API call helper
	// -------------------------------------------------------------------------

	function callAbility( endpoint, payload, onSuccess, onError ) {
		fetch( endpoint, {
			method:  'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce':   cfg.nonce,
			},
			body: JSON.stringify( { input: payload } ),
		} )
			.then( function ( r ) { return r.json(); } )
			.then( function ( data ) {
				if ( data && data.code ) {
					onError( data.message || 'An error occurred. Please try again.' );
				} else {
					onSuccess( data );
				}
			} )
			.catch( function () {
				onError( 'Network error. Please try again.' );
			} );
	}

	// -------------------------------------------------------------------------
	// SEO Meta modal — "Suggest with AI" button
	// -------------------------------------------------------------------------

	function openSeoMetaModal() {
		setLoading( true );
		modal.style.display = 'flex';
		modal.innerHTML = '<div class="scos-ca-modal-inner"><p class="scos-ca-modal-note" style="text-align:center">Generating suggestions\u2026</p></div>';

		callAbility(
			cfg.endpointSeoMeta,
			{ post_id: cfg.postId },
			renderSeoMetaModal,
			function ( msg ) {
				modal.innerHTML = '<div class="scos-ca-modal-inner">'
					+ '<p style="color:#b91c1c;">' + escHtml( msg ) + '</p>'
					+ '<button type="button" id="scos-ca-modal-close" class="button">Close</button>'
					+ '</div>';
				document.getElementById( 'scos-ca-modal-close' ).addEventListener( 'click', closeModal );
				setLoading( false );
			}
		);
	}

	function renderSeoMetaModal( data ) {
		setLoading( false );

		var html = '<div class="scos-ca-modal-inner">';
		html += '<h3 style="margin:0 0 6px;font-size:13px;font-weight:600;">Suggest SEO Meta</h3>';
		html += '<p class="scos-ca-modal-note">Click a suggestion to fill the field. Each section is independent — pick from any, skip others.</p>';

		// --- Breadcrumb ---
		html += '<div class="scos-ca-step-header">Breadcrumb Label</div>';
		html += '<div class="scos-ca-pills scos-ca-pills--seo-meta">';
		( data.breadcrumb_options || [] ).forEach( function ( item ) {
			html += '<button type="button" class="scos-ca-pill scos-ca-pill--breadcrumb"'
				+ ' data-value="' + escAttr( item.label ) + '">';
			html += escHtml( item.label );
			if ( item.char_count ) {
				html += ' <span style="opacity:.55;font-size:11px;">' + item.char_count + ' chars</span>';
			}
			html += '</button>';
		} );
		html += '</div>';

		// --- Title ---
		html += '<div class="scos-ca-step-header" style="margin-top:10px;">Meta Title</div>';
		html += '<div class="scos-ca-pills scos-ca-pills--seo-meta">';
		( data.title_options || [] ).forEach( function ( item ) {
			var cc   = item.char_count || item.title.length;
			var warn = cc < 50 || cc > 60;
			html += '<button type="button" class="scos-ca-pill scos-ca-pill--title"'
				+ ' data-value="' + escAttr( item.title ) + '">';
			html += escHtml( item.title );
			html += ' <span style="opacity:.55;font-size:11px;' + ( warn ? 'color:#b45309;' : '' ) + '">'
				+ cc + ' chars</span>';
			html += '</button>';
		} );
		html += '</div>';

		// --- Description ---
		html += '<div class="scos-ca-step-header" style="margin-top:10px;">Meta Description</div>';
		html += '<div class="scos-ca-pills scos-ca-pills--seo-meta">';
		( data.description_options || [] ).forEach( function ( item ) {
			var cc   = item.char_count || item.description.length;
			var warn = cc < 150 || cc > 160;
			html += '<button type="button" class="scos-ca-pill scos-ca-pill--description"'
				+ ' data-value="' + escAttr( item.description ) + '">';
			html += escHtml( item.description );
			html += ' <span style="opacity:.55;font-size:11px;' + ( warn ? 'color:#b45309;' : '' ) + '">'
				+ cc + ' chars</span>';
			html += '</button>';
		} );
		html += '</div>';

		html += '<div style="margin-top:12px;">';
		html += '<button type="button" id="scos-ca-modal-close" class="button">Close</button>';
		html += '</div>';
		html += '</div>';

		modal.innerHTML = html;

		modal.querySelectorAll( '.scos-ca-pill--breadcrumb' ).forEach( function ( pill ) {
			pill.addEventListener( 'click', function () {
				fillField( 'scos_seo_breadcrumb_title', this.getAttribute( 'data-value' ) );
				pill.classList.add( 'scos-ca-pill--selected' );
			} );
		} );

		modal.querySelectorAll( '.scos-ca-pill--title' ).forEach( function ( pill ) {
			pill.addEventListener( 'click', function () {
				fillField( 'scos_seo_title', this.getAttribute( 'data-value' ) );
				// Deselect siblings in this section.
				modal.querySelectorAll( '.scos-ca-pill--title' ).forEach( function ( p ) {
					p.classList.remove( 'scos-ca-pill--selected' );
				} );
				pill.classList.add( 'scos-ca-pill--selected' );
			} );
		} );

		modal.querySelectorAll( '.scos-ca-pill--description' ).forEach( function ( pill ) {
			pill.addEventListener( 'click', function () {
				fillField( 'scos_seo_description', this.getAttribute( 'data-value' ) );
				modal.querySelectorAll( '.scos-ca-pill--description' ).forEach( function ( p ) {
					p.classList.remove( 'scos-ca-pill--selected' );
				} );
				pill.classList.add( 'scos-ca-pill--selected' );
			} );
		} );

		document.getElementById( 'scos-ca-modal-close' ).addEventListener( 'click', closeModal );
	}

	// -------------------------------------------------------------------------
	// TLDR modal — "Suggest TLDR" button
	// -------------------------------------------------------------------------

	function openTldrModal() {
		setLoading( true );
		modal.style.display = 'flex';

		// Immediately show a loading state with intent goal context.
		var intentGoal = cfg.intentGoalText || '';
		var noticeHtml;
		if ( intentGoal ) {
			noticeHtml = '<div class="scos-ca-modal-note scos-ca-modal-note--info">'
				+ '<strong>Writing TLDR to answer:</strong> ' + escHtml( intentGoal )
				+ '</div>';
		} else {
			noticeHtml = '<div class="scos-ca-modal-note scos-ca-modal-note--warn">'
				+ 'Set a Search Intent Goal in Content Architecture for a more targeted TLDR. Continuing without it.'
				+ '</div>';
		}

		modal.innerHTML = '<div class="scos-ca-modal-inner">'
			+ noticeHtml
			+ '<p class="scos-ca-modal-note" style="text-align:center;margin-top:8px;">Generating suggestions\u2026</p>'
			+ '</div>';

		callAbility(
			cfg.endpointTldr,
			{ post_id: cfg.postId },
			renderTldrModal,
			function ( msg ) {
				modal.innerHTML = '<div class="scos-ca-modal-inner">'
					+ '<p style="color:#b91c1c;">' + escHtml( msg ) + '</p>'
					+ '<button type="button" id="scos-ca-modal-close" class="button">Close</button>'
					+ '</div>';
				document.getElementById( 'scos-ca-modal-close' ).addEventListener( 'click', closeModal );
				setLoading( false );
			}
		);
	}

	function renderTldrModal( data ) {
		setLoading( false );

		var intentGoal = ( data.intent_goal_used ) || cfg.intentGoalText || '';

		var html = '<div class="scos-ca-modal-inner">';
		html += '<h3 style="margin:0 0 6px;font-size:13px;font-weight:600;">Suggested TLDRs</h3>';

		if ( intentGoal ) {
			html += '<div class="scos-ca-modal-note scos-ca-modal-note--info">'
				+ '<strong>Writing to answer:</strong> ' + escHtml( intentGoal )
				+ '</div>';
		} else {
			html += '<div class="scos-ca-modal-note scos-ca-modal-note--warn">'
				+ 'No Search Intent Goal set — suggestions based on content alone.'
				+ '</div>';
		}

		html += '<p class="scos-ca-modal-note" style="margin-top:6px;">Click a suggestion to fill the TLDR field.</p>';
		html += '<div class="scos-ca-pills scos-ca-pills--tldr">';

		( data.tldr_options || [] ).forEach( function ( item ) {
			html += '<button type="button" class="scos-ca-pill scos-ca-pill--tldr"'
				+ ' data-value="' + escAttr( item.text ) + '">';
			html += escHtml( item.text );
			if ( item.sentence_count ) {
				html += ' <span style="opacity:.55;font-size:11px;">'
					+ item.sentence_count + ( item.sentence_count === 1 ? ' sentence' : ' sentences' )
					+ '</span>';
			}
			html += '</button>';
		} );

		html += '</div>';
		html += '<div style="margin-top:12px;">';
		html += '<button type="button" id="scos-ca-modal-close" class="button">Close</button>';
		html += '</div>';
		html += '</div>';

		modal.innerHTML = html;

		modal.querySelectorAll( '.scos-ca-pill--tldr' ).forEach( function ( pill ) {
			pill.addEventListener( 'click', function () {
				fillField( 'scos_seo_tldr', this.getAttribute( 'data-value' ) );
				modal.querySelectorAll( '.scos-ca-pill--tldr' ).forEach( function ( p ) {
					p.classList.remove( 'scos-ca-pill--selected' );
				} );
				pill.classList.add( 'scos-ca-pill--selected' );
				setTimeout( closeModal, 600 );
			} );
		} );

		document.getElementById( 'scos-ca-modal-close' ).addEventListener( 'click', closeModal );
	}

	// -------------------------------------------------------------------------
	// Button wiring — deferred until DOM ready
	// -------------------------------------------------------------------------

	document.addEventListener( 'DOMContentLoaded', function () {
		var seoBtn  = document.getElementById( 'scos-seo-suggest-btn' );
		var tldrBtn = document.getElementById( 'scos-tldr-suggest-btn' );

		if ( seoBtn ) {
			seoBtn.addEventListener( 'click', openSeoMetaModal );
		}
		if ( tldrBtn ) {
			tldrBtn.addEventListener( 'click', openTldrModal );
		}
	} );

} )();
