/* Plugin Jeefather - partie specifique.
 * Tout le standard (cartes, print, table_cmd, boutons add/save/remove/copy/config,
 * recherche, gotoPluginConf) est gere par core/js/plugin.template.js inclus dans la page.
 * Ici : hook printEqLogic + type device/session + listes cloud. */

/* Hook appele par plugin.template.js apres jeedom.eqLogic.print */
function printEqLogic(data) {
	loadSelects((data && data.configuration) || {});
}

$(".eqLogicAttr[data-l2key=type]").on('change', function () { toggleType($(this).val()); });

function toggleType(type) {
	$('#fg_device').toggle(type === 'device');
	$('#fg_session').toggle(type === 'session');
}

function loadSelects(cfg) {
	toggleType(cfg.type || 'device');
	refreshDevices(cfg.deviceId);
	refreshSessions(cfg.sessionId);
}

/* Parse la reponse d'un ajax Jeedom : data peut etre une string JSON ou deja un objet. */
function parseAjaxResult(data) {
	var res = data;
	if (typeof data === 'string') { try { res = JSON.parse(data); } catch (e) { return null; } }
	if (res && res.state && res.state !== 'ok') { return null; }
	var r = (res && res.result !== undefined) ? res.result : res;
	if (typeof r === 'string') { try { r = JSON.parse(r); } catch (e) { return null; } }
	return r;
}

function refreshDevices(selected) {
	$.post({
		url: 'plugins/jeefather/core/ajax/jeefather.ajax.php',
		data: { action: 'getDevices' },
		success: function (data) {
			var items = parseAjaxResult(data) || [];
			var html = '';
			items.forEach(function (d) {
				var id = d.id, name = d.name || id;
				html += '<option value="' + id + '"' + (String(id) === String(selected) ? ' selected' : '') + '>' + name + ' (' + id + ')</option>';
			});
			if (!html) { html = '<option value="">{{Aucun device cloud (vérifier le login)}}</option>'; }
			$('#sel_device').html(html);
		},
		error: function () { $('#sel_device').html('<option value="">{{Erreur appel ajax}}</option>'); }
	});
}

/* Une session est "active" si l'API dit is_active, ou si son statut est brewing/fermenting. */
function isActiveSession(s) {
	if (s.isActive === true || s.isActive === 1) return true;
	return ['brewing', 'fermenting'].indexOf(s.status) >= 0;
}

function refreshSessions(selected) {
	$.post({
		url: 'plugins/jeefather/core/ajax/jeefather.ajax.php',
		data: { action: 'getSessions' },
		success: function (data) {
			var items = parseAjaxResult(data) || [];
			// sessions actives d'abord, puis id decroissant (plus recentes d'abord)
			items.sort(function (a, b) {
				var aa = isActiveSession(a) ? 1 : 0, bb = isActiveSession(b) ? 1 : 0;
				if (aa !== bb) return bb - aa;
				return String(b.id).localeCompare(String(a.id), undefined, { numeric: true });
			});
			var html = '';
			items.forEach(function (s) {
				var id = s.id, name = s.name || id;
				var status = s.status ? ' [' + s.status + ']' : '';
				var bold = isActiveSession(s) ? ' style="font-weight:bold"' : '';
				html += '<option value="' + id + '"' + bold + (String(id) === String(selected) ? ' selected' : '') + '>' + name + status + ' (#' + id + ')</option>';
			});
			if (!html) { html = '<option value="">{{Aucune session cloud (vérifier le login)}}</option>'; }
			$('#sel_session').html(html);
		},
		error: function () { $('#sel_session').html('<option value="">{{Erreur appel ajax}}</option>'); }
	});
}

$('#bt_refreshDevices').on('click', function () { refreshDevices($('#sel_device').val()); });
$('#bt_refreshSessions').on('click', function () { refreshSessions($('#sel_session').val()); });
