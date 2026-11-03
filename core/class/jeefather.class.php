<?php
/* This file is part of Jeedom.
*
* Jeedom is free software: you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation, either version 3 of the License, or
* (at your option) any later version.
*
* Plugin Jeefather : client de l'API cloud Grainfather
* (community.grainfather.com/api, API non officielle reverse-engineeree).
* Le parsing des reponses est delegue a jeefatherParser (classe pure, testable).
*/

require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
require_once dirname(__FILE__) . '/jeefather_parser.class.php';

class jeefather extends eqLogic
{
	/* Derniere erreur de login (bouton "Tester le login").
	 * Stockage en config : une propriete publique dans une classe eqLogic
	 * serait serialisee par le save() de Jeedom et casserait l'INSERT SQL. */
	private static function setLastLoginError(string $err): void
	{
		config::save('jeefather::lastLoginError', $err, 'jeefather');
	}
	public static function getLastLoginError(): string
	{
		return (string) config::byKey('jeefather::lastLoginError', 'jeefather', '');
	}

	/* ---------------------------------------------------------------------
	 * Client API cloud Grainfather
	 * ------------------------------------------------------------------- */

	public static function apiBase(): string
	{
		return 'https://community.grainfather.com/api';
	}

	/**
	 * Appel authentifie a l'API cloud. Gere le login et le re-login sur 401.
	 * @param string $path  ex: '/equipment/fermentation-devices'
	 * @param string $method GET|POST|PUT|DELETE
	 * @param array|null $body payload JSON
	 * @param bool $retry   autorise un re-login sur 401
	 * @return array tableau decode, ou ['error' => message, 'http' => code]
	 */
	public static function apiCall(string $path, string $method = 'GET', ?array $body = null, bool $retry = true): array
	{
		$token = self::getToken();
		if ($token === null) {
			return ['error' => 'Authentification impossible (pas de token)'];
		}

		$ch = curl_init();
		$headers = [
			'Authorization: Bearer ' . $token,
			'Accept: application/json',
			'Content-Type: application/json',
		];
		curl_setopt_array($ch, [
			CURLOPT_URL => self::apiBase() . $path,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => 20,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_CUSTOMREQUEST => strtoupper($method),
			CURLOPT_HTTPHEADER => $headers,
		]);
		if ($body !== null) {
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
		}
		$response = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curlErr = curl_error($ch);
		curl_close($ch);

		if ($response === false) {
			log::add('jeefather', 'error', 'Erreur curl sur ' . $path . ' : ' . $curlErr);
			return ['error' => $curlErr, 'http' => 0];
		}

		$data = json_decode($response, true);
		if (!is_array($data)) {
			$data = ['raw' => $response];
		}

		if ($httpCode === 401 && $retry) {
			log::add('jeefather', 'debug', 'Token expire (401), re-login...');
			config::save('jeefather::token', '', 'jeefather');
			config::save('jeefather::tokenDate', 0, 'jeefather');
			if (self::login()) {
				return self::apiCall($path, $method, $body, false);
			}
			return ['error' => 'Re-authentification echouee', 'http' => 401];
		}

		if ($httpCode >= 400 || (isset($data['success']) && $data['success'] === false)) {
			log::add('jeefather', 'warning', 'API ' . $path . ' HTTP ' . $httpCode . ' : ' . substr($response, 0, 300));
			$data['error'] = $data['error'] ?? $data['errorMsg'] ?? ('HTTP ' . $httpCode);
			$data['http'] = $httpCode;
		}
		return $data;
	}

	/**
	 * Retourne un token valide (cache en config, TTL 50 min) ou null.
	 */
	public static function getToken(): ?string
	{
		$token = config::byKey('jeefather::token', 'jeefather');
		$date = (int) config::byKey('jeefather::tokenDate', 'jeefather', 0);
		if ($token === '' || $token === null) {
			return self::login() ? config::byKey('jeefather::token', 'jeefather') : null;
		}
		if ($date > 0 && (time() - $date) > 50 * 60) {
			return self::login() ? config::byKey('jeefather::token', 'jeefather') : null;
		}
		return $token;
	}

	/**
	 * POST /auth/login avec email + password du compte Grainfather.
	 */
	public static function login(): bool
	{
		$email = config::byKey('jeefather::email', 'jeefather');
		$password = config::byKey('jeefather::password', 'jeefather');
		if ($email === '' || $password === '') {
			log::add('jeefather', 'warning', 'Identifiants cloud Grainfather non configures');
			return false;
		}

		$ch = curl_init();
		curl_setopt_array($ch, [
			CURLOPT_URL => self::apiBase() . '/auth/login',
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => 20,
			CURLOPT_POST => true,
			CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json'],
			CURLOPT_POSTFIELDS => json_encode(['email' => $email, 'password' => $password]),
		]);
		$response = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($response === false) {
			self::setLastLoginError('curl: ' . $httpCode);
			log::add('jeefather', 'error', 'Login Grainfather echoue (curl)');
			return false;
		}
		$data = json_decode($response, true);
		// l'API renvoie HTTP 200 meme en cas d'echec : {"success":false,"errorMsg":"..."}
		if (is_array($data) && isset($data['success']) && $data['success'] === false) {
			self::setLastLoginError((string) ($data['errorMsg'] ?? $data['error'] ?? 'credentials'));
			log::add('jeefather', 'error', 'Login Grainfather refuse: ' . self::getLastLoginError());
			return false;
		}
		if ($httpCode !== 200) {
			self::setLastLoginError('HTTP ' . $httpCode);
			log::add('jeefather', 'error', 'Login Grainfather echoue (HTTP ' . $httpCode . ')');
			return false;
		}
		$token = is_array($data) ? jeefatherParser::extractToken($data) : null;
		if (!$token) {
			self::setLastLoginError('pas de token dans la reponse (cles: ' . (is_array($data) ? implode(',', array_keys($data)) : 'n/a') . ')');
			log::add('jeefather', 'error', 'Login OK mais aucun token dans la reponse');
			return false;
		}
		config::save('jeefather::token', $token, 'jeefather');
		config::save('jeefather::tokenDate', time(), 'jeefather');
		log::add('jeefather', 'debug', 'Login Grainfather reussi');
		return true;
	}

	/* ---------------------------------------------------------------------
	 * Endpoints metier
	 * ------------------------------------------------------------------- */

	/** Liste normalisee des devices de fermentation (contrôleur WiFi, capteurs Pill...). */
	public static function getDevices(): array
	{
		$data = self::apiCall('/equipment/fermentation-devices');
		if (isset($data['error'])) return [];
		$out = [];
		foreach (jeefatherParser::extractDevices($data) as $d) {
			$out[] = jeefatherParser::extractDevice($d);
		}
		return $out;
	}

	/** Liste normalisee (toutes pages) des brew sessions. */
	public static function getBrewSessions(): array
	{
		$sessions = [];
		$path = '/2/brew-sessions';
		$guard = 0;
		while ($path !== null && $guard++ < 20) {
			$data = self::apiCall($path);
			if (isset($data['error'])) break;
			foreach (jeefatherParser::extractSessions($data) as $s) {
				$sessions[] = jeefatherParser::extractSession($s);
			}
			$path = jeefatherParser::nextPagePath($data['next_page_url'] ?? $data['nextPageUrl'] ?? null);
		}
		return $sessions;
	}

	/** Met a jour le statut d'une brew session. Envoie le code numerique attendu par l'API. */
	public static function setSessionStatus(int $sessionId, string $status): array
	{
		$code = jeefatherParser::statusToCode($status);
		return self::apiCall('/2/brew-sessions/' . $sessionId, 'PUT', ['status' => $code !== null ? $code : $status]);
	}

	/** Definit les etapes de fermentation d'une session. */
	public static function setFermentationSteps(int $sessionId, array $steps): array
	{
		return self::apiCall('/2/brew-sessions/' . $sessionId . '/fermentation-steps', 'PUT', ['fermentation_steps' => $steps]);
	}

	/** Definit la duree d'une etape de fermentation. */
	public static function setStepDuration(int $sessionId, int $stepId, int $duration): array
	{
		return self::apiCall('/2/brew-sessions/' . $sessionId . '/fermentation-steps/' . $stepId, 'PUT', ['duration' => $duration]);
	}

	/* ---------------------------------------------------------------------
	 * Cron declare dans plugin_info/info.json (schedule toutes les 5 min) :
	 * visible et lancable manuellement dans Analyse > Moteur de taches
	 * sous le nom jeefather::pullCron
	 * ------------------------------------------------------------------- */

	public static function pullCron(): void
	{
		$interval = (int) config::byKey('jeefather::pullInterval', 'jeefather', 5);
		if ($interval < 1) $interval = 5;

		// relie l'option "intervalle" au schedule reel du cron
		self::syncCronSchedule($interval);

		$last = (int) config::byKey('jeefather::lastPull', 'jeefather', 0);
		if ($last > 0 && (time() - $last) < ($interval * 60)) return;
		config::save('jeefather::lastPull', time(), 'jeefather');
		self::pullAll();
	}

	/** Cree ou met a jour le cron jeefather::pullCron avec un schedule aligne sur l'intervalle (minutes). */
	private static function syncCronSchedule(int $interval): void
	{
		$schedule = ($interval >= 60) ? '0 * * * *' : ('*/' . $interval . ' * * * *');
		$cron = cron::byClassAndFunction('jeefather', 'pullCron');
		if (!is_object($cron)) {
			$cron = new cron();
			$cron->setClass('jeefather');
			$cron->setFunction('pullCron');
			$cron->setEnable(1);
			if (method_exists($cron, 'setName')) { $cron->setName('Pull cloud Grainfather'); }
		}
		if ($cron->getSchedule() !== $schedule) {
			$cron->setSchedule($schedule);
			$cron->save();
			log::add('jeefather', 'debug', 'Schedule du cron pullCron mis a jour : ' . $schedule);
		}
	}

	public static function pullAll(): void
	{
		foreach (self::byType('jeefather') as $eq) {
			if (!$eq->getIsEnable()) continue;
			try {
				$eq->pull();
			} catch (Throwable $e) {
				log::add('jeefather', 'error', 'Pull ' . $eq->getName() . ' : ' . $e->getMessage());
			}
		}
	}

	/**
	 * Recupere les donnees cloud et met a jour les commandes de l'equipement.
	 */
	public function pull(): void
	{
		$type = $this->getConfiguration('type', 'device');
		if ($type === 'device') {
			$this->pullDevice();
		} else {
			$this->pullSession();
		}
	}

	private function pullDevice(): void
	{
		$deviceId = $this->getConfiguration('deviceId');
		if (!$deviceId) return;
		foreach (jeefather::getDevices() as $d) {
			if ((string) $d['id'] !== (string) $deviceId) continue;
			if ($d['temperature'] !== null) $this->checkAndUpdateCmd('temperature', $d['temperature']);
			if ($d['gravity'] !== null)     $this->checkAndUpdateCmd('gravity', $d['gravity']);
			if ($d['battery'] !== null)     $this->checkAndUpdateCmd('battery', $d['battery']);
			if ($d['lastUpdate'] !== null)  $this->checkAndUpdateCmd('lastUpdate', $d['lastUpdate']);
			if ($d['sessionId'] !== null)   $this->checkAndUpdateCmd('linkedSession', $d['sessionId']);
			if ($d['firmware'] !== null)    $this->checkAndUpdateCmd('firmware', $d['firmware']);
			$this->checkAndUpdateCmd('online', $d['online'] ? 1 : 0);
			$this->refreshWidget();
			return;
		}
		$this->checkAndUpdateCmd('online', 0);
		log::add('jeefather', 'debug', 'Device ' . $deviceId . ' non trouve dans la liste cloud');
	}

	private function pullSession(): void
	{
		$sessionId = $this->getConfiguration('sessionId');
		if (!$sessionId) return;
		foreach (jeefather::getBrewSessions() as $s) {
			if ((string) $s['id'] !== (string) $sessionId) continue;
			$map = [
				'status', 'name' => 'sessionName', 'style', 'originalGravity', 'targetGravity',
				'fermentationStep', 'stepCount', 'fermentationStartDate', 'fermentationEndDate', 'conditionDate', 'recipeImage',
			];
			foreach ($map as $key => $cmdId) {
				// entree simple: key=int, value=logicalId ; paire: key=champ API, value=logicalId
				$field = is_int($key) ? $cmdId : $key;
				if (isset($s[$field]) && $s[$field] !== null && $s[$field] !== '') {
					$this->checkAndUpdateCmd($cmdId, $s[$field]);
				}
			}
			$this->checkAndUpdateCmd('online', 1);
			$this->refreshWidget();
			return;
		}
		$this->checkAndUpdateCmd('online', 0);
		log::add('jeefather', 'debug', 'Session ' . $sessionId . ' non trouvee dans la liste cloud');
	}

	/* ---------------------------------------------------------------------
	 * Cycle de vie eqLogic : creation des commandes selon le type
	 * ------------------------------------------------------------------- */

	public function postSave(): void
	{
		$this->createCmds();
	}

	private function createCmds(): void
	{
		$type = $this->getConfiguration('type', 'device');

		// supprime les commandes orphelines si le type a change (device <-> session)
		$keep = $type === 'device'
			? ['temperature', 'gravity', 'battery', 'online', 'lastUpdate', 'linkedSession', 'firmware']
			: ['status', 'sessionName', 'style', 'originalGravity', 'targetGravity', 'fermentationStep', 'stepCount', 'fermentationStartDate', 'fermentationEndDate', 'conditionDate', 'recipeImage', 'online', 'refresh', 'setStatus'];
		foreach ($this->getCmd() as $cmd) {
			if (!in_array($cmd->getLogicalId(), $keep, true)) {
				$cmd->remove();
			}
		}

		if ($type === 'device') {
			$this->addCmd('temperature', 'Température', 'numeric', '°C', ['minValue' => -5, 'maxValue' => 60, 'historized' => true]);
			$this->addCmd('gravity', 'Gravité (SG)', 'numeric', '', ['minValue' => 0.990, 'maxValue' => 1.120, 'historized' => true]);
			$this->addCmd('battery', 'Batterie', 'numeric', '%', ['minValue' => 0, 'maxValue' => 100]);
			$this->addCmd('online', 'En ligne', 'binary');
			$this->addCmd('lastUpdate', 'Dernière mesure', 'string');
			$this->addCmd('linkedSession', 'Session liée (id)', 'string');
			$this->addCmd('firmware', 'Firmware', 'string');
		} else {
			$this->addCmd('status', 'Statut', 'string');
			$this->addCmd('sessionName', 'Nom du brassin', 'string');
			$this->addCmd('style', 'Style', 'string');
			$this->addCmd('originalGravity', 'Densité initiale (OG)', 'numeric', '', ['minValue' => 0.990, 'maxValue' => 1.120, 'historized' => true]);
			$this->addCmd('targetGravity', 'Densité finale (FG)', 'numeric', '', ['minValue' => 0.990, 'maxValue' => 1.120, 'historized' => true]);
			$this->addCmd('fermentationStep', 'Étapes de fermentation (JSON)', 'string');
			$this->addCmd('stepCount', 'Nombre d’étapes', 'numeric', '');
			$this->addCmd('fermentationStartDate', 'Début fermentation', 'string');
			$this->addCmd('fermentationEndDate', 'Fin estimée (début + étapes)', 'string');
			$this->addCmd('conditionDate', 'Date conditionnement', 'string');
			$this->addCmd('recipeImage', 'Image recette', 'string');
			$this->addCmd('online', 'En ligne', 'binary');

			$this->addCmd('refresh', 'Rafraîchir', 'action', '', ['subtype' => 'other']);
			$status = $this->addCmd('setStatus', 'Changer le statut', 'message', '', []);
			if (is_object($status)) {
				$status->setConfiguration('listValue', 'planning|planning;brewing|brewing;fermenting|fermenting;conditioning|conditioning;serving|serving;completed|completed');
				$status->save();
			}
		}
	}

	private function addCmd(string $logicalId, string $name, string $type, string $unite = '', array $opts = []): ?jeefatherCmd
	{
		$cmd = $this->getCmd(null, $logicalId);
		if (!is_object($cmd)) {
			$cmd = new jeefatherCmd();
			$cmd->setLogicalId($logicalId);
			$cmd->setEqLogic_id($this->getId());
		}
		$cmd->setName($name);
		if ($type === 'binary') {
			$cmd->setType('info');
			$cmd->setSubType('binary');
		} elseif ($type === 'action') {
			$cmd->setType('action');
			$cmd->setSubType($opts['subtype'] ?? 'other');
		} elseif ($type === 'message') {
			$cmd->setType('action');
			$cmd->setSubType('message');
		} else {
			$cmd->setType('info');
			$cmd->setSubType($type === 'numeric' ? 'numeric' : 'string');
		}
		if ($unite !== '') $cmd->setUnite($unite);
		if (isset($opts['minValue'])) $cmd->setConfiguration('minValue', $opts['minValue']);
		if (isset($opts['maxValue'])) $cmd->setConfiguration('maxValue', $opts['maxValue']);
		if (!empty($opts['historized'])) $cmd->setIsHistorized(1);
		$cmd->save();
		return $cmd;
	}

	/* Pas de toHtml() custom : on laisse le core rendre la tuile dashboard standard
	 * (titre = nom de l'equipement + widgets de commandes avec leurs titres). */
}

/* Convention Jeedom : la classe commande vit dans le MEME fichier que la classe eqLogic */
class jeefatherCmd extends cmd
{
	public function execute($_options = []): void
	{
		$eq = $this->getEqLogic();
		if (!is_object($eq)) return;
		$logicalId = $this->getLogicalId();

		switch ($logicalId) {
			case 'refresh':
				$eq->pull();
				break;

			case 'setStatus':
				$sessionId = (int) $eq->getConfiguration('sessionId');
				$status = $_options['message'] ?? $_options['slider'] ?? '';
				if ($sessionId && $status !== '') {
					jeefather::setSessionStatus($sessionId, $status);
					$eq->pull();
				}
				break;
		}
	}
}
