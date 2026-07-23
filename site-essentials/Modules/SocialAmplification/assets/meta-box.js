/**
 * Social Amplification Meta Box — JavaScript
 * Handles the Postly "Create Social Post" / "Reset & Re-amplify" button (scos_sa_amplify AJAX action).
 */
/* global scosSA, jQuery */
(function ($) {
	'use strict';

	function renderSlotsTable(slots) {
		if (!Array.isArray(slots) || !slots.length) {
			return '';
		}
		var rows = slots.map(function (row) {
			return '<tr>'
				+ '<td>' + (row.slot || '-') + '</td>'
				+ '<td>' + (row.scheduled || '-') + '</td>'
				+ '<td>' + (row.status || '-') + '</td>'
				+ '<td>' + (row.postly_id || '-') + '</td>'
			+ '</tr>';
		}).join('');

		return '<table class="widefat striped scos-sa-slot-table" style="margin-top:10px;">'
			+ '<thead><tr><th>Slot</th><th>Scheduled</th><th>Status</th><th>Postly ID</th></tr></thead>'
			+ '<tbody>' + rows + '</tbody></table>';
	}

	$(document).on('click', '#scos-sa-reamp-btn', function (e) {
		e.preventDefault();
		var $btn     = $(this);
		var $msg     = $('#scos-sa-reamp-msg');
		var $results = $('#scos-sa-reamp-results');
		var $badge   = $btn.closest('.scos-sa-section--amplify').find('.scos-sa-amplify-badge');
		var postId   = $btn.data('post-id');

		if ($btn.prop('disabled')) { return; }

		$btn.prop('disabled', true).text(scosSA.i18n.amplifying || 'Running…');
		$msg.removeAttr('hidden').removeClass('is-success is-error').text(scosSA.i18n.amplifying || 'Running…');
		$results.empty();

		$.post(
			scosSA.ajaxurl,
			{
				action:  'scos_sa_amplify',
				post_id: postId,
				nonce:   scosSA.amplifyNonce
			},
			function (resp) {
				if (resp.success) {
					$msg.addClass('is-success').text('Amplification completed.');

					// Update badge and mark as amplified
					$badge.removeClass('is-no').addClass('is-yes').text('Amplified');
					$btn.data('amplified', '1')
						.attr('data-amplified', '1')
						.removeClass('button-primary').addClass('button-secondary');

					var slots = (resp.data && resp.data.result && resp.data.result.posts) ? resp.data.result.posts : [];
					$results.html(renderSlotsTable(slots));
				} else {
					var data    = resp.data || {};
					var message = data.message || 'Unknown error';
					var code    = data.code    || 'error';

					if (code === 'config_error' && scosSA.settingsUrl) {
						var linkText = scosSA.i18n.settingsLink || 'Social Amplification settings';
						var hint     = scosSA.i18n.configError  || 'AI knowledge not configured. Set up in';
						$msg.addClass('is-error').html(
							hint + ' <a href="' + scosSA.settingsUrl + '">' + linkText + '</a>.'
						);
					} else {
						$msg.addClass('is-error').text((scosSA.i18n.error + ': ') + message);
					}
				}
			}
		).fail(function () {
			$msg.addClass('is-error').text((scosSA.i18n.error || 'Error') + ': Request failed');
		}).always(function () {
			$btn.prop('disabled', false);
			// Restore correct label based on current amplified state (success may have updated it)
			var isAmplified = $btn.data('amplified') === '1' || $btn.attr('data-amplified') === '1';
			$btn.text(isAmplified ? (scosSA.i18n.reAmplify || 'Reset & Re-amplify') : (scosSA.i18n.create || 'Create Social Post'));
		});
	});

}(jQuery));
