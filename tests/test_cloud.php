<?php
/* Test du contrat API cloud Grainfather depuis la box Jeedom (ou en local avec le core).
* Usage sur la box :  php /var/www/html/plugins/jeefather/../../tests/test_cloud.php
* ou :                 cp tests/test_cloud.php /tmp/ && php /tmp/test_cloud.php  (depuis /var/www/html)
* Lit les identifiants config::byKey('jeefather::*') en base. N'affiche jamais le mot de passe ni le token en entier.
*/
require '/var/www/html/core/php/core.inc.php';

$email = config::byKey('jeefather::email', 'jeefather');
$pass  = config::byKey('jeefather::password', 'jeefather');
echo 'identifiants en base : email=' . ($email ?: 'VIDE') . ' pwd_len=' . strlen($pass ?: '') . PHP_EOL;

// 1. structure brute de la reponse login (sans afficher le token)
$ch = curl_init('https://community.grainfather.com/api/auth/login');
curl_setopt_array($ch, [
	CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_POST => true,
	CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
	CURLOPT_POSTFIELDS => json_encode(['email' => $email, 'password' => $pass]),
]);
$r = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$d = json_decode((string) $r, true);
echo 'login brut: HTTP ' . $code . ' success=' . var_export($d['success'] ?? null, true)
	. ' cles=[' . (is_array($d) ? implode(',', array_keys($d)) : 'n/a') . ']' . PHP_EOL;
if (is_array($d) && isset($d['data']) && is_array($d['data'])) {
	echo '  data.cles=[' . implode(',', array_keys($d['data'])) . ']' . PHP_EOL;
}

// 2. login via la classe du plugin
config::save('jeefather::token', '', 'jeefather');
$ok = jeefather::login();
echo 'jeefather::login() => ' . ($ok ? 'OK' : 'ECHEC (' . jeefather::getLastLoginError() . ')') . PHP_EOL;
$token = config::byKey('jeefather::token', 'jeefather');
echo 'token cache : ' . ($token ? substr($token, 0, 10) . '... (len=' . strlen($token) . ')' : 'ABSENT') . PHP_EOL;

// 3. devices
$devs = jeefather::getDevices();
echo 'devices (' . count($devs) . '):' . PHP_EOL;
foreach (array_slice($devs, 0, 5) as $dv) {
	echo '  - id=' . $dv['id'] . ' name=' . $dv['name'] . ' temp=' . var_export($dv['temperature'], true)
		. ' grav=' . var_export($dv['gravity'], true) . ' batt=' . var_export($dv['battery'], true) . PHP_EOL;
}

// 4. sessions
$sess = jeefather::getBrewSessions();
echo 'sessions (' . count($sess) . '):' . PHP_EOL;
foreach (array_slice($sess, 0, 5) as $s) {
	echo '  - #' . $s['id'] . ' ' . $s['name'] . ' | status=' . var_export($s['status'], true)
		. ' | style=' . $s['style'] . ' | tGravity=' . var_export($s['targetGravity'], true) . PHP_EOL;
}
