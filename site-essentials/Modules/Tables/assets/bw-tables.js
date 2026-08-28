/* BW Universal Table System — v3.1
   Two jobs, both mobile-only:

     .bw-labels  stamp each cell with its column heading, because CSS cannot
                 read a thead into a ::before.
     .bw-cards   rebuild the table as one card per column.

   Build once, never tear down: the media query only fires on an actual
   breakpoint crossing, so a desktop load does no DOM work at all.
*/
(function () {
	var targets = document.querySelectorAll('.bw-labels, .bw-cards');
	if (!targets.length) return;

	var bp = getComputedStyle(document.documentElement)
		.getPropertyValue('--bw-t-bp').trim();

	// The token lives on :root in bw-tables.css. If it reads empty, something
	// has rescoped it — fall back rather than building "(max-width: )", which
	// never matches and would silently disable the whole system.
	if (!bp) {
		bp = '680px';
		if (window.console && console.warn) {
			console.warn('[bw-tables] --bw-t-bp unreadable on :root, falling back to 680px');
		}
	}

	var mq = window.matchMedia('(max-width: ' + bp + ')');

	function textOf(cell) {
		return cell ? cell.textContent.trim() : '';
	}

	function headingsFor(table) {
		// Gutenberg tables with the header-row toggle off have no thead; treat
		// the first body row as the headings rather than dropping all labels.
		var row = table.querySelector('thead tr') || table.querySelector('tbody tr');
		if (!row) return null;
		return {
			cells: Array.prototype.map.call(row.children, textOf),
			fromBody: !table.querySelector('thead tr'),
			row: row
		};
	}

	function bodyRows(table, head) {
		var rows = Array.prototype.slice.call(table.querySelectorAll('tbody tr'));
		// When the headings came from the body, that row is not also data.
		return head.fromBody ? rows.filter(function (r) { return r !== head.row; }) : rows;
	}

	/* ---- .bw-labels : stamp data-bw-label on every cell ---- */
	function stampLabels(fig, table, head) {
		bodyRows(table, head).forEach(function (row) {
			Array.prototype.forEach.call(row.children, function (cell, i) {
				cell.setAttribute('data-bw-label', head.cells[i] || '');
			});
		});
	}

	/* ---- .bw-cards : one card per column ---- */
	function buildCards(fig, table, head) {
		var rows      = bodyRows(table, head);
		var useLabels = fig.classList.contains('bw-labels');
		var hideFirst = fig.classList.contains('bw-hide-first');

		// With labels, column one supplies each row's label instead of becoming
		// a card of its own. bw-hide-first drops it without using it.
		var startCol  = (useLabels || hideFirst) ? 1 : 0;
		var rowLabels = useLabels
			? rows.map(function (r) { return textOf(r.children[0]); })
			: null;

		var container = document.createElement('div');
		container.className = 'bw-cards__container';

		for (var col = startCol; col < head.cells.length; col++) {
			var card = document.createElement('div');
			card.className = 'bw-cards__card';

			var header = document.createElement('div');
			header.className = 'bw-cards__header';
			header.textContent = head.cells[col];
			card.appendChild(header);

			var body = document.createElement('div');
			body.className = 'bw-cards__body';

			for (var r = 0; r < rows.length; r++) {
				var source = rows[r].children[col];
				if (!source) continue;

				var line = document.createElement('div');
				line.className = 'bw-cards__row';

				// Clone the cell's markup so links and formatting survive, then
				// strip ids: duplicating them breaks in-page anchors and any
				// label[for] inside the cell.
				Array.prototype.forEach.call(source.childNodes, function (node) {
					line.appendChild(node.cloneNode(true));
				});
				Array.prototype.forEach.call(line.querySelectorAll('[id]'), function (el) {
					el.removeAttribute('id');
				});

				if (useLabels) {
					line.classList.add('bw-cards__row--labelled');
					line.setAttribute('data-bw-label', rowLabels[r] || '');
				}

				body.appendChild(line);
			}

			card.appendChild(body);
			container.appendChild(card);
		}

		if (!container.children.length) return false;

		table.parentNode.insertBefore(container, table.nextSibling);
		return true;
	}

	function build() {
		Array.prototype.forEach.call(targets, function (fig) {
			if (fig.classList.contains('bw-built')) return;

			var table = fig.querySelector('table');
			if (!table) return;

			// colspan/rowspan are an explicit non-goal: a scrambled card grid is
			// worse than no transform, so leave the table in scroll mode.
			if (table.querySelector('[colspan], [rowspan]')) {
				if (window.console && console.warn) {
					console.warn('[bw-tables] colspan/rowspan unsupported, leaving table in scroll mode', table);
				}
				return;
			}

			var head = headingsFor(table);
			if (!head) return;

			var isCards = fig.classList.contains('bw-cards');

			// tfoot is excluded from cards in v1 — documented, not silent.
			if (isCards && table.querySelector('tfoot') && window.console && console.warn) {
				console.warn('[bw-tables] tfoot is not carried into cards', table);
			}

			if (isCards) {
				if (!buildCards(fig, table, head)) return;
			} else {
				stampLabels(fig, table, head);
			}

			fig.classList.add('bw-built');
		});
	}

	if (mq.matches) build();

	// Fires only when the breakpoint is actually crossed, not per resize tick.
	if (mq.addEventListener) {
		mq.addEventListener('change', function (e) { if (e.matches) build(); });
	} else if (mq.addListener) {
		mq.addListener(function (e) { if (e.matches) build(); });
	}
})();
