<#
.SYNOPSIS
  Deploie le plugin Jeefather sur une box Jeedom (SSH/SCP).

.EXAMPLE
  .\deploy\deploy.ps1 -JeedomHost <IP_BOX> -JeedomUser root

.EXAMPLE
  .\deploy\deploy.ps1 -JeedomHost <IP_BOX> -JeedomUser root -DockerContainer jeedom

.PARAMETER JeedomHost
  IP ou hostname de la box Jeedom (SSH port 22).

.PARAMETER JeedomUser
  Utilisateur SSH (souvent root, ou un user avec droits sudo).

.PARAMETER PluginPath
  Dossier des plugins sur la box. Defaut : /var/www/html/plugins

.PARAMETER DockerContainer
  Si Jeedom tourne en Docker : nom du conteneur (ex: jeedom). Chaque commande
  distante sera prefixee par "docker exec <conteneur>".

.PARAMETER SshKey
  Chemin d'une cle SSH privée si l'auth par mot de passe est desactivee.
#>
[CmdletBinding()]
param(
	[string]$JeedomHost = '',
	[string]$JeedomUser = 'root',
	[string]$PluginPath = '/var/www/html/plugins',
	[string]$DockerContainer = '',
	[string]$SshKey = ''
)

$ErrorActionPreference = 'Stop'
$ROOT = (Resolve-Path "$PSScriptRoot\..").Path   # racine du plugin (jeefather)
# charge .env local (gitignore, jamais commite) si present : JEEDOM_HOST, etc.
if (Test-Path "$ROOT\.env") {
	Get-Content "$ROOT\.env" | ForEach-Object {
		if ($_ -match '^\s*([^#/=]+?)\s*=\s*(.*?)\s*$') { [Environment]::SetEnvironmentVariable($Matches[1], $Matches[2], 'Process') }
	}
}
$PLUGIN_ID = 'jeefather'
if (-not $JeedomHost) { $JeedomHost = $env:JEEDOM_HOST }
if (-not $JeedomHost) { throw 'JeedomHost non renseigne : passe -JeedomHost ou renseigne JEEDOM_HOST dans .env' }
$STAGE = Join-Path ([System.IO.Path]::GetTempPath()) "jeefather_deploy_$PID"
$ZIP = Join-Path ([System.IO.Path]::GetTempPath()) "jeefather.zip"

function Invoke-JeedomSsh([string]$remoteCmd) {
	# Prefixe docker si necessaire, puis execute la commande sur la box
	$cmd = $remoteCmd
	if ($DockerContainer -ne '') {
		$cmd = "docker exec $DockerContainer sh -c " + (ConvertTo-SingleQuoted $remoteCmd)
	}
	$args = @('-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=accept-new')
	if ($SshKey -ne '') { $args += @('-i', $SshKey) }
	$args += @("${JeedomUser}@${JeedomHost}", $cmd)
	& ssh @args
	if ($LASTEXITCODE -ne 0) { throw "Echec de la commande distante (exit $LASTEXITCODE) : $remoteCmd" }
}

function ConvertTo-SingleQuoted([string]$s) {
	return "'" + $s.Replace("'", "'\''") + "'"
}

try {
	# --- 0. prerequis locaux ---
	foreach ($tool in 'ssh', 'scp') {
		if (-not (Get-Command $tool -ErrorAction SilentlyContinue)) {
			throw "'$tool' introuvable. Installe le client OpenSSH Windows (Parametres > Apps > Fonctionnalites facultatives > Client OpenSSH) ou utilise WSL et deploy.sh."
		}
	}

	# --- 1. staging : copie du plugin sans tests/deploy/.git ---
	Write-Host "==> Preparation du paquet dans $STAGE"
	New-Item -ItemType Directory -Force -Path "$STAGE\$PLUGIN_ID" | Out-Null
	Get-ChildItem -Path $ROOT -Recurse -File |
		Where-Object { $_.FullName -notmatch '\\(tests|deploy|\.git)\\' -and $_.Name -ne '.gitignore' -and $_.Name -notlike '.env*' } |
		ForEach-Object {
			$dest = Join-Path "$STAGE\$PLUGIN_ID" $_.FullName.Substring($ROOT.Length)
			New-Item -ItemType Directory -Force -Path (Split-Path $dest) | Out-Null
			Copy-Item $_.FullName $dest
		}

	# --- 2. zip avec le dossier 'jeefather/' a la racine ---
	Write-Host "==> Creation du zip $ZIP"
	if (Test-Path $ZIP) { Remove-Item $ZIP -Force }
	Add-Type -AssemblyName System.IO.Compression.FileSystem
	$zipObj = [System.IO.Compression.ZipFile]::Open($ZIP, 'Create')
	foreach ($f in Get-ChildItem -Path "$STAGE\$PLUGIN_ID" -Recurse -File) {
		$entry = $f.FullName.Substring("$STAGE\$PLUGIN_ID".Length).TrimStart('\', '/') -replace '\\', '/'
		[System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zipObj, $f.FullName, "$PLUGIN_ID/$entry") | Out-Null
	}
	$zipObj.Dispose()

	# --- 3. transfert SCP ---
	Write-Host "==> Transfert vers ${JeedomUser}@${JeedomHost}:/tmp/jeefather.zip"
	$scpArgs = @('-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=accept-new')
	if ($SshKey -ne '') { $scpArgs += @('-i', $SshKey) }
	$scpArgs += @($ZIP, "${JeedomUser}@${JeedomHost}:/tmp/jeefather.zip")
	& scp @scpArgs
	if ($LASTEXITCODE -ne 0) { throw "Echec du transfert SCP" }

	# --- 4. deploiement cote box ---
	Write-Host "==> Deploiement dans $PluginPath/$PLUGIN_ID"
	$remote = @"
set -e
cd '$PluginPath'
# backup HORS de plugins/ : un dossier .old dans plugins/ avec un info.json serait re-scanne comme un plugin
mkdir -p /var/www/html/.plugin-backups
rm -rf "/var/www/html/.plugin-backups/${PLUGIN_ID}.old"
if [ -d '$PLUGIN_ID' ]; then mv '$PLUGIN_ID' "/var/www/html/.plugin-backups/${PLUGIN_ID}.old"; fi
if command -v unzip >/dev/null 2>&1; then unzip -oq /tmp/jeefather.zip -d '$PluginPath'; else python3 -m zipfile -e /tmp/jeefather.zip '$PluginPath'; fi
chown -R www-data:www-data '$PluginPath/$PLUGIN_ID' 2>/dev/null || true
chmod -R 755 '$PluginPath/$PLUGIN_ID'
echo '--- php -l ---'
for f in '$PluginPath/$PLUGIN_ID'/core/class/*.php '$PluginPath/$PLUGIN_ID'/core/ajax/*.php '$PluginPath/$PLUGIN_ID'/desktop/php/*.php '$PluginPath/$PLUGIN_ID'/mobile/php/*.php '$PluginPath/$PLUGIN_ID'/mobile/html/*.html; do php -l "`$f`"; done
# PAS de vidage du cache Jeedom ici : /tmp/jeedom/cache contient les valeurs courantes
# des commandes (value/valueDate/collectDate) ; le vider rend tous les widgets vides.
rm -f /tmp/jeefather.zip
echo '--- contenu deploye ---'
ls -la '$PluginPath/$PLUGIN_ID'
"@
	Invoke-JeedomSsh $remote

	Write-Host ""
	Write-Host "==> OK. Plugin deploye dans $PluginPath/$PLUGIN_ID (sauvegarde dans ${PLUGIN_ID}.old si existant)."
	Write-Host "    Etapes suivantes dans Jeedom :"
	Write-Host "    1. Plugins > Gestion des plugins : le plugin 'Jeefather' apparait, cliquer dessus."
	Write-Host "    2. Renseigner email + mot de passe du compte Grainfather, tester le login."
	Write-Host "    3. Creer un equipement (device ou brew session), verifier le dashboard et les logs 'jeefather'."
}
finally {
	if (Test-Path $STAGE) { Remove-Item $STAGE -Recurse -Force -ErrorAction SilentlyContinue }
	if (Test-Path $ZIP) { Remove-Item $ZIP -Force -ErrorAction SilentlyContinue }
}
