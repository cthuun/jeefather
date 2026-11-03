<?php
/* This file is part of Jeedom.
*
* Jeedom is free software: you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation, either version 3 of the License, or
* (at your option) any later version.
*/

require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
ajax::init();

if (init('action') === 'getDevices') {
	$devices = jeefather::getDevices();
	if (count($devices) === 0) {
		log::add('jeefather', 'warning', 'ajax getDevices: liste vide (token=' . (config::byKey('jeefather::token', 'jeefather') ? 'ok' : 'absent') . ', lastErr=' . jeefather::getLastLoginError() . ')');
	}
	ajax::success($devices);
}

if (init('action') === 'getSessions') {
	$sessions = jeefather::getBrewSessions();
	if (count($sessions) === 0) {
		log::add('jeefather', 'warning', 'ajax getSessions: liste vide (token=' . (config::byKey('jeefather::token', 'jeefather') ? 'ok' : 'absent') . ')');
	}
	ajax::success($sessions);
}

if (init('action') === 'testLogin') {
	config::save('jeefather::token', '', 'jeefather');
	config::save('jeefather::tokenDate', 0, 'jeefather');
	$ok = jeefather::login();
	ajax::success([
		'ok' => $ok,
		'token' => $ok ? (bool) config::byKey('jeefather::token', 'jeefather') : false,
		'error' => jeefather::getLastLoginError(),
	]);
}

throw new Exception('Aucune methode correspondante à : ' . init('action'));
