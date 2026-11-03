<?php
/* This file is part of Jeedom.
*
* Jeedom is free software: you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation, either version 3 of the License, or
* (at your option) any later version.
*
* Classe PURE (aucune dependance au core Jeedom, compatible PHP 7.4) :
* parsing des reponses de l'API cloud Grainfather (community.grainfather.com/api).
* Structure validee contre l'API reelle le 2026-11-03 (voir tests/test_cloud.php).
*/

class jeefatherParser
{
	/** Codes de statut brew session (mapping officiel, cf. integration communautaire). */
	const STATUS_MAP = [
		5 => 'planning',
		10 => 'brewing',
		20 => 'fermenting',
		30 => 'conditioning',
		35 => 'serving',
		40 => 'completed',
	];

	/** Polyfill PHP7 : array_is_list() n'existe qu'en PHP 8.1+. */
	private static function isList(array $arr): bool
	{
		return $arr === [] || array_keys($arr) === range(0, count($arr) - 1);
	}

	/** Premier champ present et non vide parmi $keys. */
	public static function firstKey(array $src, array $keys)
	{
		foreach ($keys as $k) {
			if (isset($src[$k]) && $src[$k] !== '' && $src[$k] !== null) return $src[$k];
		}
		return null;
	}

	/** Extrait le token d'une reponse /auth/login (reponse: {id, ..., api_token}). */
	public static function extractToken(array $loginResponse): ?string
	{
		$token = self::firstKey($loginResponse, ['api_token', 'accessToken', 'token']);
		if ($token === null && isset($loginResponse['data']) && is_array($loginResponse['data'])) {
			$token = self::firstKey($loginResponse['data'], ['api_token', 'accessToken', 'token']);
		}
		return $token ? (string) $token : null;
	}

	/** Normalise la liste des devices : racine = tableau direct OU enveloppe {data:[...]}. */
	public static function extractDevices(array $payload): array
	{
		if (isset($payload['data']) && is_array($payload['data'])) return $payload['data'];
		if (isset($payload['devices']) && is_array($payload['devices'])) return $payload['devices'];
		return self::isList($payload) ? $payload : [];
	}

	/** Normalise la liste des brew sessions : enveloppe Laravel {data:[...], next_page_url}. */
	public static function extractSessions(array $payload): array
	{
		if (isset($payload['data']) && is_array($payload['data'])) return $payload['data'];
		if (isset($payload['brew_sessions']) && is_array($payload['brew_sessions'])) return $payload['brew_sessions'];
		return self::isList($payload) ? $payload : [];
	}

	/** Extrait le path API d'une next_page_url absolue (Laravel), ou null. */
	public static function nextPagePath($nextPageUrl): ?string
	{
		if (!$nextPageUrl) return null;
		$parts = parse_url((string) $nextPageUrl);
		if (!isset($parts['path'])) return null;
		$path = str_replace('/api', '', $parts['path']);
		if (isset($parts['query'])) $path .= '?' . $parts['query'];
		return $path !== '' ? $path : null;
	}

	/** code statut -> texte. */
	public static function statusToText($status): ?string
	{
		if ($status === null || $status === '') return null;
		if (is_numeric($status)) {
			$code = (int) $status;
			return isset(self::STATUS_MAP[$code]) ? self::STATUS_MAP[$code] : ('status_' . $code);
		}
		return (string) $status;
	}

	/**
	 * Normalise un device brut. Structure reelle:
	 * {id, name, last_temperature:"20.5", last_sg:"1.0100", last_heard, data_updated_at,
	 *  is_controller_linked, firmware_ver, brew_session_id, brew_session:{...}}
	 */
	public static function extractDevice(array $d): array
	{
		$id = self::firstKey($d, ['id', 'device_id', 'serial', 'serial_no']);
		$temp = self::firstKey($d, ['last_temperature', 'temperature', 'temp', 'current_temperature', 'beer_temp', 'value_temp']);
		$grav = self::firstKey($d, ['last_sg', 'gravity', 'sg', 'density', 'current_gravity', 'value_gravity']);
		$batt = self::firstKey($d, ['battery', 'battery_level', 'batteryLevel']);
		$upd  = self::firstKey($d, ['data_updated_at', 'last_heard', 'updated_at', 'last_update', 'lastUpdate', 'timestamp']);
		$heard = $d['last_heard'] ?? null;
		return [
			'id' => $id !== null ? (string) $id : null,
			'name' => (string) (self::firstKey($d, ['name', 'device_name', 'label']) ?? $id),
			'temperature' => ($temp !== null && $temp !== '') ? round((float) $temp, 2) : null,
			'gravity' => ($grav !== null && $grav !== '') ? round((float) $grav, 4) : null,
			'battery' => $batt !== null ? (int) $batt : null,
			'lastUpdate' => $upd !== null ? (string) $upd : null,
			// en ligne si le device a ete entendu recemment ou lie a un controleur
			'online' => ($heard !== null && $heard !== '') ? true : (bool) ($d['is_controller_linked'] ?? false),
			'sessionId' => isset($d['brew_session_id']) && $d['brew_session_id'] !== null ? (string) $d['brew_session_id'] : null,
			'firmware' => isset($d['firmware_ver']) && $d['firmware_ver'] !== null ? (string) $d['firmware_ver'] : null,
		];
	}

	/** time_unit_id -> multiplicateur en minutes (30 = minutes d'apres les donnees reelles). */
	const TIME_UNIT_MIN = [10 => 1 / 60, 20 => 60, 30 => 1, 40 => 1440];

	/** Date de fin estimee = fermentation_start_date + somme des durees des etapes. */
	public static function computeEndDate(array $s): ?string
	{
		$start = self::firstKey($s, ['fermentation_start_date', 'fermentationStartDate']);
		$steps = isset($s['fermentation_steps']) && is_array($s['fermentation_steps']) ? $s['fermentation_steps'] : [];
		if (!$start || !count($steps)) return null;
		$ts = strtotime((string) $start);
		if ($ts === false) return null;
		$totalMin = 0.0;
		foreach ($steps as $st) {
			$t = isset($st['time']) ? (float) $st['time'] : 0.0;
			$u = isset($st['time_unit_id']) ? (int) $st['time_unit_id'] : 30;
			$mult = isset(self::TIME_UNIT_MIN[$u]) ? self::TIME_UNIT_MIN[$u] : 1.0;
			$totalMin += $t * $mult;
		}
		return gmdate('Y-m-d H:i:s', $ts + (int) round($totalMin * 60));
	}

	/** Liste normalisee (strings) des ids devices associes a la session (fermentation_devices). */
	public static function sessionDeviceIds(array $s): array
	{
		$raw = $s['fermentation_devices'] ?? $s['devices'] ?? [];
		if (!is_array($raw)) return [];
		$out = [];
		foreach ($raw as $entry) {
			if (is_array($entry)) {
				$id = self::firstKey($entry, ['id', 'device_id']);
				if ($id !== null) $out[] = (string) $id;
			} elseif ($entry !== null && $entry !== '') {
				$out[] = (string) $entry;
			}
		}
		return $out;
	}

	/**
	 * Temperature actuelle d'une brew session : celle du device lie a la session
	 * (brew_session_id == id de session), sinon du premier device de fermentation_devices.
	 * $devices = liste normalisee via extractDevice().
	 */
	public static function sessionCurrentTemperature(array $s, array $devices): ?float
	{
		$sessionId = isset($s['id']) && $s['id'] !== null ? (string) $s['id'] : null;
		$deviceIds = self::sessionDeviceIds($s);
		foreach ($devices as $d) {
			if ($sessionId !== null && (string) ($d['sessionId'] ?? '') === $sessionId && $d['temperature'] !== null) {
				return $d['temperature'];
			}
		}
		foreach ($devices as $d) {
			if (in_array((string) ($d['id'] ?? ''), $deviceIds, true) && $d['temperature'] !== null) {
				return $d['temperature'];
			}
		}
		return null;
	}

	/**
	 * Attribue un numero de brassin (1..N) a chaque session, en triant par date de
	 * creation de la fiche cloud (created_at), repli sur l'id (ids incrementaux).
	 * Retourne les sessions re-triees avec le champ 'brewNumber' renseigne.
	 */
	public static function assignBrewNumbers(array $sessions): array
	{
		usort($sessions, function ($a, $b) {
			$ca = $a['createdAt'] ?? null;
			$cb = $b['createdAt'] ?? null;
			if ($ca && $cb && (string) $ca !== (string) $cb) {
				return strcmp((string) $ca, (string) $cb);
			}
			return (int) $a['id'] <=> (int) $b['id'];
		});
		foreach ($sessions as $i => &$s) {
			$s['brewNumber'] = $i + 1;
		}
		unset($s);
		return $sessions;
	}

	/**
	 * Normalise une brew session brute. Champs reels: id, status (code), session_name,
	 * name, batch_number, original_gravity, final_gravity, fermentation_start_date,
	 * condition_date, fermentation_steps[], fermentation_devices[], recipe{...}, is_active.
	 */
	public static function extractSession(array $s): array
	{
		$id = self::firstKey($s, ['id', 'session_id']);
		$recipe = (isset($s['recipe']) && is_array($s['recipe'])) ? $s['recipe'] : [];
		$style = null;
		if (isset($recipe['recipe_style']) && is_array($recipe['recipe_style'])) {
			$style = self::firstKey($recipe['recipe_style'], ['sub_category_name', 'name']);
		}
		if ($style === null) $style = self::firstKey($recipe, ['style_id', 'style', 'name']);

		$name = self::firstKey($s, ['session_name']);
		if ($name === null) {
			$name = trim((string) ($s['name'] ?? '') . ' ' . (isset($s['batch_number']) ? '#' . $s['batch_number'] : ''));
		}

		$steps = isset($s['fermentation_steps']) && is_array($s['fermentation_steps']) ? $s['fermentation_steps'] : null;

		return [
			'id' => $id !== null ? (string) $id : null,
			'createdAt' => self::firstKey($s, ['created_at', 'createdAt', 'created']),
			'name' => (string) $name,
			'style' => $style !== null ? (string) $style : '',
			'status' => self::statusToText(self::firstKey($s, ['status', 'state'])),
			'originalGravity' => ($og = self::firstKey($s, ['original_gravity', 'originalGravity', 'og'])) !== null ? round((float) $og, 4) : null,
			'targetGravity' => ($fg = self::firstKey($s, ['final_gravity', 'target_gravity', 'targetGravity', 'fg'])) !== null ? round((float) $fg, 4) : null,
			'fermentationStep' => $steps !== null ? json_encode($steps, JSON_UNESCAPED_UNICODE) : null,
			'stepCount' => $steps !== null ? count($steps) : null,
			'fermentationStartDate' => self::firstKey($s, ['fermentation_start_date', 'fermentationStartDate']),
			'fermentationEndDate' => self::computeEndDate($s),
			'conditionDate' => self::firstKey($s, ['condition_date', 'conditionDate']),
			'recipeImage' => self::firstKey($recipe, ['image', 'image_url', 'imageUrl']),
			'isActive' => (bool) ($s['is_active'] ?? false),
			'devices' => self::sessionDeviceIds($s),
		];
	}
}
