# ============================================================
# ĐurđaShop — priprema release ZIP-a za GitHub
#
# Što radi:
#   1. minificira assets/js/app.js (npx terser, treba Node) — manje čitljiv
#      JS u javnom repou + brži load. NAPOMENA: prava zaštita od "odvajanja
#      od đurđe" je SERVER-SIDE (katalog, firma, fiskalizacija i plan dolaze
#      isključivo s mojadjurdja.com API-ja) — obfuskacija je samo dodatna
#      prepreka, kao i kod đurđa frontenda (javascript-obfuscator postbuild).
#   2. složi čisti ZIP bez configa, logova, uploada i dev smeća.
#
# Pokretanje:  powershell -ExecutionPolicy Bypass -File tools\release.ps1
# ============================================================

$ErrorActionPreference = 'Stop'
$root = Split-Path $PSScriptRoot -Parent
$ver = (Select-String -Path "$root\core\bootstrap.php" -Pattern "SHOP_VERSION', '([^']+)'").Matches[0].Groups[1].Value
$out = "$root\..\djurdjashop-v$ver.zip"

Write-Host "ĐurđaShop release v$ver" -ForegroundColor Cyan

# 1. Minifikacija JS (preskače se ako nema Node-a)
$js = "$root\assets\js\app.js"
$bak = "$root\assets\js\app.dev.js"
$hasNode = Get-Command npx -ErrorAction SilentlyContinue
if ($hasNode) {
    Copy-Item $js $bak -Force
    & npx --yes terser $js --compress --mangle --output $js
    if ($LASTEXITCODE -ne 0) { Copy-Item $bak $js -Force; Write-Warning "terser nije uspio — ostaje neminificirani JS." }
    else { Write-Host "app.js minificiran ($([math]::Round((Get-Item $js).Length/1KB,1)) KB)" }
} else {
    Write-Warning "Node/npx nije pronađen — preskačem minifikaciju."
}

# 2. ZIP bez tajni i lokalnog sadržaja
$staging = "$env:TEMP\djshop-release"
Remove-Item $staging -Recurse -Force -ErrorAction SilentlyContinue
New-Item -ItemType Directory -Path $staging | Out-Null
robocopy $root $staging /E /XD .git logs node_modules tools /XF config.php app.dev.js *.zip *.log | Out-Null
# uploads: zadrži strukturu + .htaccess + index.html, izbaci sadržaj
Get-ChildItem "$staging\uploads" -Recurse -File | Where-Object { $_.Name -notin @('.htaccess','index.html') } | Remove-Item -Force
Remove-Item $out -Force -ErrorAction SilentlyContinue
Compress-Archive -Path "$staging\*" -DestinationPath $out

# vrati dev JS u radnu kopiju
if (Test-Path $bak) { Copy-Item $bak $js -Force; Remove-Item $bak -Force }

Write-Host "Gotovo: $out" -ForegroundColor Green
