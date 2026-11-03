/* Panel Brassins (desktop) : filtres texte + statut, et tri par clic sur les en-tetes.
 * Les valeurs de tri sont lues dans l'attribut data-v des cellules (vide = en dernier). */
$(function () {
	var $table = $('.jeefather-sessions');
	if (!$table.length) return;
	var $tbody = $table.find('tbody');
	var $ths = $table.find('thead th');
	var total = $tbody.find('tr').length;

	function applyFilters() {
		var q = $('#jf-filter-q').val().toLowerCase();
		var st = $('#jf-filter-status').val();
		var visible = 0;
		$tbody.find('tr').each(function () {
			var $tr = $(this);
			var ok = (!q || $tr.find('td:first').text().toLowerCase().indexOf(q) !== -1)
				&& (!st || $tr.attr('data-status') === st);
			$tr.toggle(ok);
			if (ok) visible++;
		});
		$('#jf-count').text(visible + ' / ' + total);
	}

	$('#jf-filter-q').on('input', applyFilters);
	$('#jf-filter-status').on('change', applyFilters);

	var sortCol = -1, sortAsc = true;
	$ths.on('click', function () {
		var col = $ths.index($(this));
		if (sortCol === col) { sortAsc = !sortAsc; } else { sortCol = col; sortAsc = true; }
		var rows = $tbody.find('tr').get();
		rows.sort(function (a, b) {
			var va = $(a).find('td').eq(col).attr('data-v') || '';
			var vb = $(b).find('td').eq(col).attr('data-v') || '';
			var na = parseFloat(va), nb = parseFloat(vb);
			var cmp;
			if (va !== '' && vb !== '' && !isNaN(na) && !isNaN(nb)) {
				cmp = na - nb;
			} else if (va === '') { cmp = 1; } else if (vb === '') { cmp = -1; } else {
				cmp = va.localeCompare(vb);
			}
			return sortAsc ? cmp : -cmp;
		});
		$.each(rows, function (i, tr) { $tbody.append(tr); });
		$ths.removeClass('sort-asc sort-desc');
		$(this).addClass(sortAsc ? 'sort-asc' : 'sort-desc');
		applyFilters();
	});

	applyFilters();
});
