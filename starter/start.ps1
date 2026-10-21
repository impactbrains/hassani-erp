# Hassani ERP - one-click starter for Windows.
#
# Double-click "Start Hassani ERP.exe" (or "Start Hassani ERP.bat") in the main folder.
# This script:
#   1. finds PHP and PostgreSQL (and offers to install them with winget the first time),
#   2. switches on the PHP extensions the app needs,
#   3. creates the database and brings it up to date (safe to run every day),
#      - steps 1-3 are skipped on later starts unless something changed (see "fast path"),
#   4. starts the web server, the background worker and the scheduler,
#   5. opens the app in the browser (Chrome with silent receipt printing when available).
# Closing this window stops everything.

$ErrorActionPreference = 'Stop'
$Host.UI.RawUI.WindowTitle = 'Hassani ERP'
$Root = Split-Path -Parent $PSScriptRoot
Set-Location $Root
$Port = 8000
foreach ($line in (Get-Content (Join-Path $Root '.env') -ErrorAction SilentlyContinue)) {
    if ($line -match '^\s*SERVER_PORT\s*=\s*"?(\d+)"?\s*$') { $Port = [int]$Matches[1] }
}
$Url = "http://localhost:$Port"
$LogDir = Join-Path $Root 'storage\logs'
$PidFile = Join-Path $Root 'storage\framework\hassani-processes.txt'
New-Item -ItemType Directory -Force -Path $LogDir | Out-Null
$StampFile = Join-Path $Root 'storage\framework\hassani-setup.stamp'
$Clock = [System.Diagnostics.Stopwatch]::StartNew()

function Say($text, $color = 'Gray') { Write-Host $text -ForegroundColor $color }
function Step($text) { Write-Host ''; Write-Host ("==> $text   [{0:n1}s]" -f $Clock.Elapsed.TotalSeconds) -ForegroundColor Yellow }
function Fail($text) {
    Write-Host ''
    Write-Host "PROBLEM: $text" -ForegroundColor Red
    Write-Host 'See README.md -> "Troubleshooting". Press Enter to close.' -ForegroundColor Red
    Read-Host | Out-Null
    exit 1
}
function Refresh-Path {
    $env:Path = [Environment]::GetEnvironmentVariable('Path', 'Machine') + ';' + [Environment]::GetEnvironmentVariable('Path', 'User')
}
function Ask($question) {
    $answer = Read-Host "$question [Y/n]"
    return ($answer -eq '' -or $answer -match '^[Yy]')
}
function Read-DotEnv($path) {
    $values = @{}
    if (Test-Path $path) {
        foreach ($line in Get-Content $path) {
            if ($line -match '^\s*([A-Z0-9_]+)\s*=\s*(.*)\s*$') {
                $values[$Matches[1]] = $Matches[2].Trim('"')
            }
        }
    }
    return $values
}
function Winget-Install($id, $extra = @()) {
    if (-not (Get-Command winget -ErrorAction SilentlyContinue)) {
        Fail "winget is not available on this computer. Install $id by hand (see README.md), then start again."
    }
    $wingetArgs = @('install', '--id', $id, '-e', '--accept-package-agreements', '--accept-source-agreements') + $extra
    Say "Installing $id ... (this can take a few minutes)" 'Cyan'
    & winget @wingetArgs
    Refresh-Path
}

# Ask the web server for /up on 127.0.0.1, never through a system/VPN proxy (PowerShell's
# Invoke-WebRequest uses the proxy even for localhost, Chrome does not). Returns the HTTP
# status code of any answer (200, 302, 500 ...), or 0 when nothing answered.
function Get-ServerStatus {
    try {
        $req = [System.Net.HttpWebRequest]::Create("http://127.0.0.1:$Port/up")
        $req.Proxy = $null
        $req.Timeout = 5000
        $req.AllowAutoRedirect = $false
        $resp = $req.GetResponse()
        $code = [int]$resp.StatusCode
        $resp.Close()
        return $code
    } catch {
        $ex = $_.Exception
        $r = $ex.Response
        if (-not $r -and $ex.InnerException) { $r = $ex.InnerException.Response }
        if ($r) { $code = [int]$r.StatusCode; $r.Close(); return $code }
        $script:LastProbeError = $ex.Message
        return 0
    }
}
# Kill a php process AND its children (php artisan serve starts a child php process).
function Stop-Tree($id) {
    $ErrorActionPreference = 'SilentlyContinue'
    $proc = Get-Process -Id $id
    if ($proc -and $proc.ProcessName -eq 'php') { & taskkill.exe /PID $id /T /F 2>&1 | Out-Null }
}
# Stop anything this app started earlier (PID file + any orphaned web server child).
function Stop-Leftovers {
    $ErrorActionPreference = 'SilentlyContinue'
    if (Test-Path $PidFile) {
        Get-Content $PidFile | ForEach-Object { Stop-Tree $_ }
        Remove-Item $PidFile -Force
    }
    Get-CimInstance Win32_Process -Filter "Name = 'php.exe'" | Where-Object { $_.CommandLine -match 'server\.php' -and $_.CommandLine -match [regex]::Escape($Root) } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force }
}

# True when something is listening on host:port (used to see if PostgreSQL is already up).
function Test-TcpPort($hostName, $port) {
    $client = New-Object System.Net.Sockets.TcpClient
    try {
        $task = $client.ConnectAsync($hostName, [int]$port)
        return ($task.Wait(1500) -and $client.Connected)
    } catch {
        return $false
    } finally {
        $client.Dispose()
    }
}
# A cheap fingerprint of everything the one-time setup depends on: PHP, php.ini, .env, the
# composer lock, the built screens and the app's own code/config/migrations. Never looks inside vendor.
function Get-SetupStamp($phpPath) {
    $sb = New-Object System.Text.StringBuilder
    [void]$sb.Append("php|$phpPath;")
    $files = @('.env', 'composer.lock', 'public\build\manifest.json') | ForEach-Object { Join-Path $Root $_ }
    $files += $phpPath
    $files += (Join-Path (Split-Path -Parent $phpPath) 'php.ini')
    foreach ($p in $files) {
        $i = New-Object System.IO.FileInfo $p
        if ($i.Exists) { [void]$sb.Append("$p|$($i.Length)|$($i.LastWriteTimeUtc.Ticks);") }
        else { [void]$sb.Append("$p|missing;") }
    }
    foreach ($d in @('app', 'config', 'routes', 'database\migrations', 'database\seeders', 'resources\views')) {
        $dir = Join-Path $Root $d
        $count = 0; $bytes = [long]0; $newest = [long]0
        if (Test-Path $dir) {
            foreach ($f in [System.IO.Directory]::EnumerateFiles($dir, '*', [System.IO.SearchOption]::AllDirectories)) {
                $i = New-Object System.IO.FileInfo $f
                $count++; $bytes += $i.Length
                if ($i.LastWriteTimeUtc.Ticks -gt $newest) { $newest = $i.LastWriteTimeUtc.Ticks }
            }
        }
        [void]$sb.Append("$d|$count|$bytes|$newest;")
    }
    $sha = [System.Security.Cryptography.SHA1]::Create()
    return [BitConverter]::ToString($sha.ComputeHash([System.Text.Encoding]::UTF8.GetBytes($sb.ToString()))).Replace('-', '')
}
# Sets "key = value" in php.ini text: edits an active line, else un-comments one, else appends.
function Set-IniSetting([string]$text, [string]$key, [string]$value) {
    $k = [regex]::Escape($key)
    $active = [regex]"(?m)^[ \t]*$k[ \t]*=[^\r\n]*"
    $commented = [regex]"(?m)^;[ \t]*$k[ \t]*=[^\r\n]*"
    if ($active.IsMatch($text)) { return $active.Replace($text, "$key = $value", 1) }
    if ($commented.IsMatch($text)) { return $commented.Replace($text, "$key = $value", 1) }
    return $text + "`r`n$key = $value"
}

Say '  _   _                         _   ' 'DarkYellow'
Say ' | | | | __ _ ___ ___  __ _ _ __ (_)  ERP' 'DarkYellow'
Say ' | |_| |/ _` / __/ __|/ _` | ''_ \| |' 'DarkYellow'
Say ' |  _  | (_| \__ \__ \ (_| | | | | |  POS - Inventory - Reports' 'DarkYellow'
Say ' |_| |_|\__,_|___/___/\__,_|_| |_|_|' 'DarkYellow'

# ---------------------------------------------------------------- 0. already running?
if ((Get-ServerStatus) -eq 200) {
    Say "Hassani ERP is already running. Opening $Url" 'Green'
    Start-Process $Url
    Start-Sleep -Seconds 2
    exit 0
}

# ---------------------------------------------------------------- 1. PHP
Step 'Checking PHP'
$php = (Get-Command php -ErrorAction SilentlyContinue).Source
if (-not $php) {
    foreach ($c in @('C:\php\php.exe', "$env:LOCALAPPDATA\Microsoft\WinGet\Links\php.exe", 'C:\tools\php83\php.exe', 'C:\laragon\bin\php\php-8.3*\php.exe', 'C:\xampp\php\php.exe')) {
        $found = Get-Item $c -ErrorAction SilentlyContinue | Select-Object -First 1
        if ($found) { $php = $found.FullName; break }
    }
}
if (-not $php) {
    if (Ask 'PHP 8.3 is not installed. Install it now with winget?') {
        Winget-Install 'PHP.PHP.8.3'
        $php = (Get-Command php -ErrorAction SilentlyContinue).Source
        if (-not $php) { $php = (Get-ChildItem "$env:LOCALAPPDATA\Microsoft\WinGet\Packages" -Recurse -Filter php.exe -ErrorAction SilentlyContinue | Select-Object -First 1).FullName }
    }
    if (-not $php) { Fail 'PHP 8.3 is needed. Install it, then start again.' }
}

# Settings from .env (created from .env.example the first time).
$envFile = Join-Path $Root '.env'
if (-not (Test-Path $envFile)) { Copy-Item (Join-Path $Root '.env.example') $envFile; Say 'Created .env from .env.example' }
$cfg = Read-DotEnv $envFile
$dbHost = if ($cfg['DB_HOST']) { $cfg['DB_HOST'] } else { '127.0.0.1' }
$dbPort = if ($cfg['DB_PORT']) { $cfg['DB_PORT'] } else { '5432' }
$dbName = if ($cfg['DB_DATABASE']) { $cfg['DB_DATABASE'] } else { 'hassani_erp' }
$dbUser = if ($cfg['DB_USERNAME']) { $cfg['DB_USERNAME'] } else { 'postgres' }
$dbPass = $cfg['DB_PASSWORD']
# SERVER_HOST=0.0.0.0 in .env lets other computers on the shop network open the app.
$BindHost = if ($cfg['SERVER_HOST']) { $cfg['SERVER_HOST'] } else { '127.0.0.1' }

# Fast path: after the first successful start, skip PHP/PostgreSQL checks, migrations and caching
# until something that needs them has changed (PHP, .env, app code, migrations, built screens).
$fastStart = $false
if ((Test-Path $StampFile) -and (Test-Path (Join-Path $Root 'bootstrap\cache\config.php'))) {
    $savedStamp = "$(Get-Content $StampFile -Raw)".Trim()
    if ($savedStamp -eq (Get-SetupStamp $php) -and (Test-TcpPort $dbHost $dbPort)) { $fastStart = $true }
}
if ($fastStart) {
    Say 'Nothing changed since the last start - skipping setup.' 'Green'
} else {

$version = & $php -r 'echo PHP_VERSION;'
Say "PHP $version at $php"
if ([version]($version -replace '[^0-9.].*$', '') -lt [version]'8.2') { Fail "PHP $version is too old. PHP 8.2 or newer is needed (8.3 recommended)." }

# Switch on the extensions the app needs (only edits php.ini next to php.exe).
$phpDir = Split-Path -Parent $php
$ini = Join-Path $phpDir 'php.ini'
if (-not (Test-Path $ini)) {
    $template = @('php.ini-production', 'php.ini-development') | ForEach-Object { Join-Path $phpDir $_ } | Where-Object { Test-Path $_ } | Select-Object -First 1
    if ($template) { Copy-Item $template $ini; Say 'Created php.ini' }
}
$needed = @('pdo_pgsql', 'pgsql', 'mbstring', 'openssl', 'fileinfo', 'intl', 'curl', 'zip', 'sodium', 'gd')
$loaded = (& $php -m) -join "`n"
$missing = $needed | Where-Object { $loaded -notmatch "(?im)^$_$" }
if ($missing -and (Test-Path $ini)) {
    $text = Get-Content $ini -Raw
    $text = $text -replace '(?m)^;\s*extension_dir\s*=\s*"ext"', 'extension_dir = "ext"'
    foreach ($ext in $missing) {
        if ($text -match "(?m)^;\s*extension\s*=\s*$ext\s*$") {
            $text = $text -replace "(?m)^;\s*extension\s*=\s*$ext\s*$", "extension=$ext"
        } elseif ($text -notmatch "(?m)^extension\s*=\s*$ext\s*$") {
            $text += "`r`nextension=$ext"
        }
    }
    $text = $text -replace '(?m)^;?\s*memory_limit\s*=.*$', 'memory_limit = 512M'
    Set-Content -Path $ini -Value $text -Encoding ASCII
    $loaded = (& $php -m) -join "`n"
    $still = $needed | Where-Object { $loaded -notmatch "(?im)^$_$" }
    if ($still -contains 'pdo_pgsql') { Fail "PHP extension pdo_pgsql could not be switched on. Edit $ini and enable extension=pdo_pgsql." }
    if ($still) { Say ("Optional PHP extensions not available: " + ($still -join ', ')) 'DarkGray' }
    else { Say 'PHP extensions switched on.' }
}

# Speed: OPcache keeps the compiled app in memory, so PHP stops re-reading and re-compiling every file on every request.
$opcacheDll = Join-Path $phpDir 'ext\php_opcache.dll'
if ((Test-Path $ini) -and (Test-Path $opcacheDll)) {
    $text = Get-Content $ini -Raw
    $before = $text
    $zend = [regex]'(?m)^;?[ \t]*zend_extension[ \t]*=[ \t]*"?(php_)?opcache(\.dll)?"?[^\r\n]*'
    if ($zend.IsMatch($text)) { $text = $zend.Replace($text, 'zend_extension=opcache', 1) } else { $text += "`r`nzend_extension=opcache" }
    $text = Set-IniSetting $text 'opcache.enable' '1'
    $text = Set-IniSetting $text 'opcache.enable_cli' '1'
    $text = Set-IniSetting $text 'opcache.memory_consumption' '256'
    $text = Set-IniSetting $text 'opcache.max_accelerated_files' '20000'
    $text = Set-IniSetting $text 'realpath_cache_size' '4096k'
    $text = Set-IniSetting $text 'realpath_cache_ttl' '600'
    if ($text -ne $before) { Set-Content -Path $ini -Value $text -Encoding ASCII; Say 'PHP speed settings (OPcache) switched on.' }
}

# ---------------------------------------------------------------- 2. PostgreSQL
Step 'Checking PostgreSQL'
$psql = (Get-Command psql -ErrorAction SilentlyContinue).Source
if (-not $psql) { $psql = (Get-ChildItem 'C:\Program Files\PostgreSQL\*\bin\psql.exe' -ErrorAction SilentlyContinue | Sort-Object FullName -Descending | Select-Object -First 1).FullName }
if (-not $psql) {
    if (Ask "PostgreSQL is not installed. Install PostgreSQL 16 now with winget? (the 'postgres' password will be set to the one in .env)") {
        Winget-Install 'PostgreSQL.PostgreSQL.16' @('--override', "--mode unattended --unattendedmodeui minimal --superpassword $dbPass --serverport $dbPort")
        $psql = (Get-ChildItem 'C:\Program Files\PostgreSQL\*\bin\psql.exe' -ErrorAction SilentlyContinue | Sort-Object FullName -Descending | Select-Object -First 1).FullName
    }
    if (-not $psql) { Fail 'PostgreSQL is needed. Install PostgreSQL 16 (password for user postgres = DB_PASSWORD in .env), then start again.' }
}
Say "psql at $psql"

# Start the PostgreSQL Windows service if it is stopped.
$svc = Get-Service -Name 'postgresql*' -ErrorAction SilentlyContinue | Select-Object -First 1
if ($svc -and $svc.Status -ne 'Running') {
    Say "Starting service $($svc.Name) ..."
    try { Start-Service $svc.Name } catch { Say 'Could not start the service (try running as administrator once).' 'DarkYellow' }
    Start-Sleep -Seconds 3
}

$env:PGPASSWORD = $dbPass
$exists = & $psql -h $dbHost -p $dbPort -U $dbUser -d postgres -tAc "SELECT 1 FROM pg_database WHERE datname = '$dbName'" 2>&1
if ($LASTEXITCODE -ne 0) { Fail "Cannot connect to PostgreSQL as '$dbUser' on ${dbHost}:${dbPort}. Check DB_USERNAME / DB_PASSWORD in .env. ($exists)" }
if ("$exists".Trim() -ne '1') {
    Say "Creating database $dbName ..."
    & $psql -h $dbHost -p $dbPort -U $dbUser -d postgres -c "CREATE DATABASE `"$dbName`" ENCODING 'UTF8' TEMPLATE template0" | Out-Null
    if ($LASTEXITCODE -ne 0) { Fail "Could not create database $dbName." }
}
Say "Database $dbName is ready."

# ---------------------------------------------------------------- 3. App files
Step 'Preparing the app'
if (-not (Test-Path (Join-Path $Root 'vendor\autoload.php'))) {
    $composer = (Get-Command composer -ErrorAction SilentlyContinue).Source
    if (-not $composer) { Fail 'The vendor folder is missing and Composer is not installed. Use the full zip, or install Composer and run "composer install".' }
    & $composer install --no-dev --optimize-autoloader --no-interaction
}
if (-not (Test-Path (Join-Path $Root 'public\build\manifest.json'))) {
    $npm = (Get-Command npm -ErrorAction SilentlyContinue).Source
    if (-not $npm) { Fail 'The built screens (public\build) are missing and Node.js is not installed. Use the full zip, or install Node.js 20+ and run "npm ci" then "npm run build".' }
    & $npm ci; & $npm run build
}
if (-not $cfg['APP_KEY']) { & $php artisan key:generate --force | Out-Null; Say 'Generated APP_KEY.' }

& $php artisan migrate --force --seed 2>&1 | Tee-Object -FilePath (Join-Path $LogDir 'starter-migrate.log') | Select-Object -Last 3 | ForEach-Object { Say $_ 'DarkGray' }
if ($LASTEXITCODE -ne 0) { Fail 'Updating the database failed. Details: storage\logs\starter-migrate.log' }
& $php artisan optimize:clear | Out-Null
$eap = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
$optimizeOutput = & $php artisan optimize 2>&1
$optimizeCode = $LASTEXITCODE
$ErrorActionPreference = $eap
if ($optimizeCode -ne 0) {
    $optimizeOutput | Out-String | Set-Content (Join-Path $LogDir 'starter-optimize.log')
    Say 'Note: "php artisan optimize" reported a problem, so some caches are missing and the app will be slower. Details: storage\logs\starter-optimize.log' 'DarkYellow'
}
# Remember that setup is done, so the next start can skip all of it until something changes.
Get-SetupStamp $php | Set-Content $StampFile
}

# ---------------------------------------------------------------- 4. Start
Step 'Starting Hassani ERP'
# Stop anything left over from a previous run that was not closed cleanly.
Stop-Leftovers
function Start-Role($role) {
    $argsFor = @{
        'web'       = @('artisan', 'serve', "--host=$BindHost", "--port=$Port", '--no-reload')
        'worker'    = @('artisan', 'queue:work', '--sleep=3', '--tries=3', '--max-time=3600')
        'scheduler' = @('artisan', 'schedule:work')
    }
    $p = Start-Process -FilePath $php -ArgumentList $argsFor[$role] -WorkingDirectory $Root -WindowStyle Hidden -PassThru `
        -RedirectStandardOutput (Join-Path $LogDir "$role.log") -RedirectStandardError (Join-Path $LogDir "$role-errors.log")
    return @{ Role = $role; Process = $p }
}
$procs = @((Start-Role 'web'), (Start-Role 'worker'), (Start-Role 'scheduler'))
$procs | ForEach-Object { $_.Process.Id } | Set-Content $PidFile

# Any HTTP answer (even a redirect or an error page) proves the server is up; the browser shows the rest.
$ready = $false
$script:LastProbeError = ''
for ($i = 0; $i -lt 200; $i++) {
    if ($procs[0].Process.HasExited) { break }
    Start-Sleep -Milliseconds 300
    if ((Get-ServerStatus) -gt 0) { $ready = $true; break }
}
if (-not $ready) {
    foreach ($p in $procs) { Stop-Tree $p.Process.Id }
    Stop-Leftovers
    foreach ($f in 'web.log', 'web-errors.log') {
        $lp = Join-Path $LogDir $f
        if ((Test-Path $lp) -and ((Get-Item $lp).Length -gt 0)) {
            Say "--- $f (last lines) ---" 'DarkGray'
            Get-Content $lp -Tail 8 | ForEach-Object { Say $_ 'DarkGray' }
        }
    }
    if ($script:LastProbeError) { Say "Last check error: $script:LastProbeError" 'DarkGray' }
    Fail "The web server did not answer on 127.0.0.1:$Port. Is port $Port used by another program? Details: storage\logs\web.log and web-errors.log"
}

# Chrome with --kiosk-printing prints receipts straight to the default printer (no print dialog).
$chrome = @("$env:ProgramFiles\Google\Chrome\Application\chrome.exe", "${env:ProgramFiles(x86)}\Google\Chrome\Application\chrome.exe", "$env:LOCALAPPDATA\Google\Chrome\Application\chrome.exe") | Where-Object { Test-Path $_ } | Select-Object -First 1
$edge = @("${env:ProgramFiles(x86)}\Microsoft\Edge\Application\msedge.exe", "$env:ProgramFiles\Microsoft\Edge\Application\msedge.exe") | Where-Object { Test-Path $_ } | Select-Object -First 1
$profileDir = Join-Path $Root 'storage\framework\browser-profile'
if ($chrome) {
    Start-Process $chrome -ArgumentList @("--app=$Url", '--kiosk-printing', "--user-data-dir=$profileDir", '--start-maximized')
} elseif ($edge) {
    Start-Process $edge -ArgumentList @("--app=$Url", '--kiosk-printing', "--user-data-dir=$profileDir", '--start-maximized')
} else {
    Start-Process $Url
}

Write-Host ''
Say '================================================================' 'Green'
Say ("  Hassani ERP is running at $Url   (ready in {0:n1}s)" -f $Clock.Elapsed.TotalSeconds) 'Green'
if ($BindHost -ne '127.0.0.1') {
    # The address of the Wi-Fi/cable adapter that has a gateway (not a VPN or virtual adapter).
    $ip = (Get-NetIPConfiguration -ErrorAction SilentlyContinue | Where-Object { $_.IPv4DefaultGateway -and $_.NetAdapter.Status -eq 'Up' } | Select-Object -First 1).IPv4Address.IPAddress
    if ($ip) { Say "  Other computers in the shop: http://${ip}:$Port" 'Green' }
} else {
    Say '  Other computers on the shop network: see README -> "Using more than one computer".' 'Green'
}
Say '  Keep this window open while the shop is working.' 'Green'
Say '  To stop: close this window (or press Ctrl+C).' 'Green'
Say '================================================================' 'Green'

try {
    while ($true) {
        Start-Sleep -Seconds 5
        # Restart any background process that stopped (the worker restarts itself every hour on purpose).
        $dead = @($procs | Where-Object { $_.Process.HasExited })
        if ($dead.Count) {
            Say ("[" + (Get-Date -Format 'HH:mm') + "] restarting: " + (($dead | ForEach-Object { $_.Role }) -join ', ')) 'DarkGray'
            $procs = @($procs | ForEach-Object { if ($_.Process.HasExited) { Start-Role $_.Role } else { $_ } })
            $procs | ForEach-Object { $_.Process.Id } | Set-Content $PidFile
        }
    }
} finally {
    Say 'Stopping Hassani ERP ...' 'Yellow'
    foreach ($p in $procs) { Stop-Tree $p.Process.Id }
    # php artisan serve starts a child php process; stop it too.
    Stop-Leftovers
}