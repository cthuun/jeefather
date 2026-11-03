<?php
/* Panel desktop Jeefather : tableau de TOUTES les brew sessions du cloud Grainfather,
 * avec filtres (texte + statut) et tri par colonne (desktop/js/panel.js).
 * Declare dans plugin_info/info.json via "display": "panel".
 * Source : API cloud directement (pas seulement les equipements configures) ;
 * si l'API est KO, repli sur les equipements session configures (valeurs via execCmd = cache). */
if (!isConnect()) {
	throw new Exception('{{401 - Accès non autorisé}}');
}

$h = function ($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
$fmtDate = function ($v): string {
	if ($v === null || $v === '') return '—';
	$ts = strtotime((string) $v);
	return $ts !== false ? date('d/m/Y H:i', $ts) : (string) $v;
};
$statusMap = [
	'planning' => ['Planifié', 'label-default'],
	'brewing' => ['Brassage', 'label-warning'],
	'fermenting' => ['Fermentation', 'label-success'],
	'conditioning' => ['Conditionnement', 'label-info'],
	'serving' => ['Service', 'label-success'],
	'completed' => ['Terminé', 'label-primary'],
];
$statusLabel = function ($status) use ($statusMap): array {
	$s = (string) $status;
	return $statusMap[$s] ?? [$s, 'label-default'];
};
$activeStatus = ['brewing', 'fermenting'];

/* equipements session configures : sessionId -> eqLogic */
$eqBySession = [];
foreach (eqLogic::byType('jeefather') as $eqLogic) {
	if ($eqLogic->getConfiguration('type', 'device') !== 'session') continue;
	$sid = $eqLogic->getConfiguration('sessionId');
	if ($sid) $eqBySession[(string) $sid] = $eqLogic;
}

/* lignes depuis le cloud */
$rows = [];
$devices = jeefather::getDevices();
foreach (jeefatherParser::assignBrewNumbers(jeefather::getBrewSessions()) as $s) {
	$sid = (string) $s['id'];
	$rows[] = [
		'id' => $s['id'],
		'brewNumber' => $s['brewNumber'] ?? null,
		'name' => ($s['name'] !== '' ? $s['name'] : 'Batch') . ' (#' . $s['id'] . ')',
		'eq' => $eqBySession[$sid] ?? null,
		'status' => $s['status'],
		'temperature' => jeefatherParser::sessionCurrentTemperature($s, $devices),
		'og' => $s['originalGravity'],
		'fg' => $s['targetGravity'],
		'stepCount' => $s['stepCount'],
		'start' => $s['fermentationStartDate'],
		'end' => $s['fermentationEndDate'],
		'condition' => $s['conditionDate'],
	];
}

/* repli si l'API cloud est KO : lignes depuis les equipements (execCmd = valeur courante cache) */
if (count($rows) === 0) {
	foreach ($eqBySession as $sid => $eq) {
		$val = function (string $logicalId) use ($eq) {
			$cmd = $eq->getCmd('info', $logicalId);
			return is_object($cmd) ? $cmd->execCmd() : null;
		};
		$rows[] = [
			'id' => $sid,
			'brewNumber' => null,
			'name' => $eq->getName(),
			'eq' => $eq,
			'status' => $val('status'),
			'temperature' => $val('temperature'),
			'og' => $val('originalGravity'),
			'fg' => $val('targetGravity'),
			'stepCount' => $val('stepCount'),
			'start' => $val('fermentationStartDate'),
			'end' => $val('fermentationEndDate'),
			'condition' => $val('conditionDate'),
		];
	}
}

/* tri par defaut : sessions actives d'abord, puis id decroissant */
usort($rows, function ($a, $b) use ($activeStatus) {
	$aa = in_array((string) $a['status'], $activeStatus, true) ? 1 : 0;
	$bb = in_array((string) $b['status'], $activeStatus, true) ? 1 : 0;
	if ($aa !== $bb) return $bb - $aa;
	return (int) $b['id'] <=> (int) $a['id'];
});
?>
<div class="panel panel-primary jeefather-panel">
	<div class="panel-heading"><i class="fas fa-beer"></i> {{Brassins (brew sessions Grainfather)}} — <?php echo count($rows); ?></div>
	<div class="panel-body table-responsive">
		<?php if (count($rows) === 0): ?>
			<div class="alert alert-info" style="margin:10px;">{{Aucune brew session dans le cloud Grainfather (vérifier le login dans la configuration du plugin).}}</div>
		<?php else: ?>
		<div class="jeefather-filters form-inline">
			<input type="text" id="jf-filter-q" class="form-control input-sm" placeholder="{{Rechercher un brassin...}}">
			<select id="jf-filter-status" class="form-control input-sm">
				<option value="">{{Tous les statuts}}</option>
				<?php foreach ($statusMap as $key => $pair): ?>
					<option value="<?php echo $key; ?>"><?php echo $h($pair[0]); ?></option>
				<?php endforeach; ?>
			</select>
			<span class="text-muted" id="jf-count"></span>
			<span class="text-muted pull-right" style="font-size:0.85em;">{{Clic sur un en-tête pour trier}}</span>
		</div>
		<table class="table table-condensed table-striped jeefather-sessions">
			<thead>
				<tr>
					<th>#</th>
					<th>{{Brassin}}</th>
					<th>{{Statut}}</th>
					<th>{{Temp. actuelle}}</th>
					<th>{{OG}}</th>
					<th>{{FG cible}}</th>
					<th>{{Étapes}}</th>
					<th>{{Début fermentation}}</th>
					<th>{{Fin estimée}}</th>
					<th>{{Conditionnement}}</th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ($rows as $r):
				[$label, $class] = $statusLabel($r['status']);
				$dv = function ($v) { return $v === null ? '' : (string) $v; };
			?>
				<tr data-status="<?php echo $h($r['status']); ?>">
					<td data-v="<?php echo $dv($r['brewNumber']); ?>"><?php echo $r['brewNumber'] !== null ? '#' . (int) $r['brewNumber'] : '—'; ?></td>
					<td data-v="<?php echo $h($r['name']); ?>">
						<?php if (is_object($r['eq'])): ?>
							<a href="index.php?v=d&m=jeefather&p=jeefather&id=<?php echo $r['eq']->getId(); ?>"><?php echo $h($r['name']); ?></a>
						<?php else: ?>
							<?php echo $h($r['name']); ?> <span class="text-muted">(<?php echo '{{équipement non configuré}}'; ?>)</span>
						<?php endif; ?>
					</td>
					<td data-v="<?php echo $h($r['status']); ?>"><span class="label <?php echo $class; ?>"><?php echo $h($label); ?></span></td>
					<td data-v="<?php echo $dv($r['temperature']); ?>"><?php echo $r['temperature'] !== null && $r['temperature'] !== '' ? number_format((float) $r['temperature'], 1) . ' °C' : '—'; ?></td>
					<td data-v="<?php echo $dv($r['og']); ?>"><?php echo $r['og'] !== null && $r['og'] !== '' ? $h($r['og']) : '—'; ?></td>
					<td data-v="<?php echo $dv($r['fg']); ?>"><?php echo $r['fg'] !== null && $r['fg'] !== '' ? $h($r['fg']) : '—'; ?></td>
					<td data-v="<?php echo $dv($r['stepCount']); ?>"><?php echo $r['stepCount'] !== null && $r['stepCount'] !== '' ? (int) $r['stepCount'] : '—'; ?></td>
					<td data-v="<?php echo $dv($r['start']); ?>"><?php echo $h($fmtDate($r['start'])); ?></td>
					<td data-v="<?php echo $dv($r['end']); ?>"><?php echo $h($fmtDate($r['end'])); ?></td>
					<td data-v="<?php echo $dv($r['condition']); ?>"><?php echo $h($fmtDate($r['condition'])); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>
	</div>
</div>
<?php include_file('desktop', 'panel', 'js', 'jeefather'); ?>
