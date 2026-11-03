<?php
/* Harnais de test du parsing de l'API cloud Grainfather (fixtures anonymisees).
* Usage : php tests/test_parser.php   (aucune dependance au core Jeedom, PHP 7.4+)
* Exit code 0 si tout passe, 1 sinon.
*/

require_once dirname(__FILE__) . '/../core/class/jeefather_parser.class.php';

$failures = 0;
function check(string $label, $expected, $actual): void
{
	global $failures;
	if ($expected === $actual) {
		echo "OK   $label\n";
	} else {
		$failures++;
		echo "FAIL $label : attendu " . var_export($expected, true) . ", obtenu " . var_export($actual, true) . "\n";
	}
}

$fix = function (string $f): array {
	return json_decode(file_get_contents(dirname(__FILE__) . '/fixtures/' . $f), true);
};

// --- login (reponse fictive: {id, first_name, ..., api_token}) ---
$login = $fix('login.json');
check('token extrait du login', 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.exemple', jeefatherParser::extractToken($login));
check('token absent si reponse vide', null, jeefatherParser::extractToken([]));

// --- devices (racine = tableau direct, last_temperature/last_sg en string) ---
$devicesPayload = $fix('fermentation-devices.json');
$devices = array_map(function ($d) { return jeefatherParser::extractDevice($d); }, jeefatherParser::extractDevices($devicesPayload));
check('nb devices', 2, count($devices));
check('device 1 id', '1001', $devices[0]['id']);
check('device 1 name', 'Controleur', $devices[0]['name']);
check('device 1 temp null', null, $devices[0]['temperature']);
check('device 1 online via is_controller_linked', true, $devices[0]['online']);
check('device 1 firmware', '2.8', $devices[0]['firmware']);
check('device 1 sessionId', '5001', $devices[0]['sessionId']);
check('device 2 temperature (last_temperature string)', 20.5, $devices[1]['temperature']);
check('device 2 gravity (last_sg string)', 1.01, $devices[1]['gravity']);
check('device 2 online via last_heard', true, $devices[1]['online']);
check('device 2 lastUpdate (data_updated_at)', '2026-11-03 11:06:50', $devices[1]['lastUpdate']);

// --- sessions (enveloppe Laravel {data, next_page_url}) ---
$page1 = $fix('brew-sessions-page1.json');
$sessions = array_map(function ($s) { return jeefatherParser::extractSession($s); }, jeefatherParser::extractSessions($page1));
check('nb sessions page 1', 2, count($sessions));
check('session 5001 statut 20 -> fermenting', 'fermenting', $sessions[0]['status']);
check('session 5001 nom (session_name)', 'Batch #1', $sessions[0]['name']);
check('session 5001 style (recipe_style)', 'IPA exemple', $sessions[0]['style']);
check('session 5001 OG', 1.04, $sessions[0]['originalGravity']);
check('session 5001 FG', 1.01, $sessions[0]['targetGravity']);
check('session 5001 stepCount', 1, $sessions[0]['stepCount']);
check('session 5001 image recette', 'https://community.grainfather.com/recipes/9001/image.jpg', $sessions[0]['recipeImage']);
// start 2026-08-09T12:15:28Z + 10080 min (time_unit_id 30 = minutes) = +7 jours
check('session 5001 endDate estimee', '2026-08-16 12:15:28', $sessions[0]['fermentationEndDate']);
check('session 5002 statut 40 -> completed', 'completed', $sessions[1]['status']);
check('next_page_url -> path', '/2/brew-sessions?page=2', jeefatherParser::nextPagePath($page1['next_page_url']));
$page2 = $fix('brew-sessions-page2.json');
check('next_page_url null -> null', null, jeefatherParser::nextPagePath($page2['next_page_url']));
$s2 = jeefatherParser::extractSession(jeefatherParser::extractSessions($page2)[0]);
check('session page2 statut 30 -> conditioning', 'conditioning', $s2['status']);

// --- statuts ---
check('status 5 -> planning', 'planning', jeefatherParser::statusToText(5));
check('status 10 -> brewing', 'brewing', jeefatherParser::statusToText(10));
check('status 35 -> serving', 'serving', jeefatherParser::statusToText(35));
check('status inconnu 99 -> status_99', 'status_99', jeefatherParser::statusToText(99));
check('status texte', 'fermenting', jeefatherParser::statusToText('fermenting'));
check('status null', null, jeefatherParser::statusToText(null));
check('code planning -> 5', 5, jeefatherParser::statusToCode('planning'));
check('code completed -> 40', 40, jeefatherParser::statusToCode('completed'));
check('code inconnu -> null', null, jeefatherParser::statusToCode('xxx'));

echo $failures === 0 ? "\nTOUS LES TESTS PASSENT\n" : "\n$failures ECHEC(S)\n";
exit($failures === 0 ? 0 : 1);
