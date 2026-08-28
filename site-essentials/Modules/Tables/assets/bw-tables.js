/* BW Universal Table System — label stamper.
   .bw-labels needs each cell to know its column heading; CSS cannot read
   thead into a ::before, so this stamps data-bw-label once at load.
   Falls back to the first tbody row when the table has no thead (§6.4). */
(function () {
  var figs = document.querySelectorAll('.bw-labels');
  if (!figs.length) return;

  figs.forEach(function (fig) {
    var table = fig.querySelector('table');
    if (!table || table.classList.contains('bw-built')) return;

    if (table.querySelector('[colspan], [rowspan]')) {
      if (window.console) console.warn('[bw-tables] colspan/rowspan unsupported, leaving table in scroll mode', table);
      return;
    }

    var headRow = table.querySelector('thead tr') || table.querySelector('tbody tr');
    if (!headRow) return;

    var labels = Array.prototype.map.call(headRow.children, function (c) {
      return c.textContent.trim();
    });

    table.querySelectorAll('tbody tr').forEach(function (row) {
      Array.prototype.forEach.call(row.children, function (cell, i) {
        cell.setAttribute('data-bw-label', labels[i] || '');
      });
    });

    table.classList.add('bw-built');
  });
})();
