/* global jQuery, inlineEditPost, scosCols */
( function ( $ ) {
	'use strict';

	console.log( '[SCOS] admin-columns.js v1.0.4 loaded' );

	// -------------------------------------------------------------------------
	// Quick Edit — pre-populate from per-row data container
	// -------------------------------------------------------------------------

	/**
	 * The "Quick Edit" button has no id attribute, so inlineEditPost.getId()
	 * returns NaN when given the button element. Instead, capture the post ID
	 * from the parent row (id="post-{ID}") on button click — before WP runs.
	 */
	var _qePostId = 0;

	$( document ).on( 'click', 'button.editinline, a.editinline', function () {
		var $row = $( this ).closest( 'tr[id^="post-"]' );
		var m    = ( $row.attr( 'id' ) || '' ).match( /post-(\d+)/ );
		_qePostId = m ? parseInt( m[1], 10 ) : 0;
		console.log( '[SCOS QE] captured postId from row:', _qePostId );
	} );

	if ( typeof inlineEditPost !== 'undefined' && typeof inlineEditPost.edit === 'function' ) {

		var $origEdit = inlineEditPost.edit;

		inlineEditPost.edit = function ( id ) {
			$origEdit.apply( this, arguments );

			// Use the ID captured on click; fall back to getId() if click wasn't detected
			var postId = _qePostId;
			if ( ! postId ) {
				postId = ( typeof id === 'object' ) ? this.getId( id ) : parseInt( id, 10 );
				console.log( '[SCOS QE] using getId fallback, postId:', postId );
			}

			console.log( '[SCOS QE] edit() fired, postId:', postId );

			if ( ! postId || isNaN( postId ) ) {
				console.warn( '[SCOS QE] could not resolve postId, skipping pre-population' );
				return;
			}

			// Find data container in the cluster column cell for this row
			var $dataEl = $( '#scos-col-data-' + postId );
			console.log( '[SCOS QE] data element found:', $dataEl.length, '  id: scos-col-data-' + postId );

			if ( ! $dataEl.length ) {
				console.warn( '[SCOS QE] data element not found — cluster column may not be rendered for this post type' );
				return;
			}

			var raw = $dataEl.attr( 'data-qe' );
			console.log( '[SCOS QE] raw data-qe attribute length:', raw ? raw.length : 0 );

			if ( ! raw ) {
				console.warn( '[SCOS QE] data-qe attribute is empty' );
				return;
			}

			var data;
			try {
				data = JSON.parse( raw );
				console.log( '[SCOS QE] parsed data:', data );
			} catch ( e ) {
				console.error( '[SCOS QE] JSON.parse failed:', e.message );
				return;
			}

			// Find the Quick Edit panel — try by ID first, then by next-sibling
			var $panel = $( '#edit-' + postId );
			if ( ! $panel.length ) {
				$panel = $( '#post-' + postId ).nextAll( 'tr.inline-edit-row' ).first();
				console.log( '[SCOS QE] fell back to nextAll sibling panel, found:', $panel.length );
			}
			if ( ! $panel.length ) {
				console.warn( '[SCOS QE] panel #edit-' + postId + ' not found' );
				return;
			}
			console.log( '[SCOS QE] panel found:', $panel.attr( 'id' ) );

			// ---- Populate select fields ----
			var selectMap = {
				'cluster':      'scos_ca_qe_cluster',
				'topic':        'scos_ca_qe_topic',
				'intent':       'scos_ca_qe_intent',
				'purpose':      'scos_ca_qe_purpose',
				'maturity':     'scos_ca_qe_maturity',
				'index-status': 'scos_ca_qe_index_status',
				'next-step':    'scos_ca_qe_next_step',
				'pillar':       'scos_ca_qe_pillar_page_id',
				'pathway':      'scos_ca_qe_service_pathway_id',
			};

			$.each( selectMap, function ( dataKey, fieldName ) {
				var val = data[ dataKey ];
				if ( val !== undefined && val !== null ) {
					var $sel = $panel.find( 'select[name="' + fieldName + '"]' );
					var before = $sel.val();
					$sel.val( String( val ) );
					var after = $sel.val();
					console.log( '[SCOS QE] field "' + fieldName + '": data=' + val + '  before=' + before + '  after=' + after );
					if ( String( val ) !== '0' && String( val ) !== '' && after !== String( val ) ) {
						console.warn( '[SCOS QE] .val() set to "' + val + '" but select shows "' + after + '" — option may be missing' );
					}
				}
			} );

			// ---- Populate progress checkboxes ----
			var progress = Array.isArray( data.progress ) ? data.progress : [];
			$panel.find( '.scos-qe-progress-tags .scos-qe-progress-tag' ).each( function () {
				var $tag    = $( this );
				var $cb     = $tag.find( 'input[type="checkbox"]' );
				var checked = progress.indexOf( $cb.val() ) !== -1;
				$cb.prop( 'checked', checked );
				$tag.toggleClass( 'is-selected', checked );
			} );
			console.log( '[SCOS QE] progress:', progress );
		};
	} else {
		console.warn( '[SCOS QE] inlineEditPost.edit not found — Quick Edit pre-population disabled' );
	}

	// -------------------------------------------------------------------------
	// Progress tag toggle — Quick Edit + Bulk Edit panels
	// -------------------------------------------------------------------------

	$( document ).on( 'click', '.scos-qe-progress-tag', function ( e ) {
		if ( $( e.target ).is( 'input[type="checkbox"]' ) ) { return; }
		e.preventDefault();
		var $tag = $( this );
		var $cb  = $tag.find( 'input[type="checkbox"]' );
		var next = ! $cb.prop( 'checked' );
		$cb.prop( 'checked', next );
		$tag.toggleClass( 'is-selected', next );
	} );

	$( document ).on( 'change', '.scos-qe-progress-tag input[type="checkbox"]', function () {
		$( this ).closest( '.scos-qe-progress-tag' ).toggleClass( 'is-selected', $( this ).prop( 'checked' ) );
	} );

} )( jQuery );
