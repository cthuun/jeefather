<?php
if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}
?>
<form class="form-horizontal">
	<fieldset>
		<legend><i class="fas fa-cog"></i> {{Configuration du compte cloud Grainfather}}</legend>
		<div class="form-group">
			<label class="col-sm-3 control-label">{{Email du compte Grainfather}}</label>
			<div class="col-sm-4">
				<input type="text" class="configKey form-control" data-l1key="jeefather::email" placeholder="email@exemple.com" />
			</div>
		</div>
		<div class="form-group">
			<label class="col-sm-3 control-label">{{Mot de passe du compte Grainfather}}</label>
			<div class="col-sm-4">
				<input type="password" class="configKey form-control" data-l1key="jeefather::password" autocomplete="new-password" />
			</div>
		</div>
		<div class="form-group">
			<label class="col-sm-3 control-label">{{Intervalle de rafraîchissement (minutes)}}</label>
			<sup><i class="fas fa-question-circle tooltips" title="{{Ajuste automatiquement le schedule du cron jeefather::pullCron (1-59 min, 60 = toutes les heures)}}"></i></sup>
			<div class="col-sm-2">
				<input type="number" class="configKey form-control" data-l1key="jeefather::pullInterval" min="1" max="60" />
			</div>
		</div>
		<div class="form-group">
			<label class="col-sm-3 control-label">{{Tester la connexion au cloud}}</label>
			<div class="col-sm-4">
				<a class="btn btn-default" id="bt_testLoginGrainfather"><i class="fas fa-plug"></i> {{Tester le login}}</a>
				<span id="span_loginResultGrainfather"></span>
				<br/><small>{{Enregistrer la configuration avant de tester.}}</small>
			</div>
		</div>
		<div class="alert alert-info col-sm-9 col-sm-offset-3">
			{{L'API cloud de Grainfather (community.grainfather.com/api) n'est pas officiellement documentée : en cas de changement côté Grainfather, mettez le plugin à jour.}}
		</div>
	</fieldset>
</form>
<script>
	$('#bt_testLoginGrainfather').on('click', function () {
		$.post({
			url: 'plugins/jeefather/core/ajax/jeefather.ajax.php',
			data: { action: 'testLogin' },
			success: function (data) {
				// data peut etre une string JSON ou deja un objet selon le core
				var res = data;
				if (typeof data === 'string') { try { res = JSON.parse(data); } catch (e) { res = {}; } }
				var result = res && res.result !== undefined ? res.result : res;
				if (typeof result === 'string') { try { result = JSON.parse(result); } catch (e) { result = {}; } }
				var ok = !!(result && result.ok);
				var msg = ok ? '<span class="label label-success">OK</span>'
					: '<span class="label label-danger">{{Échec}}</span> ' + (result && result.error ? $('<div>').text(result.error).html() : '');
				$('#span_loginResultGrainfather').html(msg);
			}
		});
	});
</script>
