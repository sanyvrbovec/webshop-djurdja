$ErrorActionPreference = 'Stop'
$root = Split-Path $PSScriptRoot -Parent
$ver = (Select-String -Path "$root\core\bootstrap.php" -Pattern "SHOP_VERSION', '([^']+)'").Matches[0].Groups[1].Value
$out = "$root\..\djurdjashop-v$ver.zip"

Write-Host "ĐurđaShop release v$ver" -ForegroundColor Cyan

# 1. Minifikacija JS (preskace se ako nema Node-a)
$js = "$root\assets\js\app.js"
$bak = "$root\assets\js\app.dev.js"
$hasNode = Get-Command npx -ErrorAction SilentlyContinue

if ($hasNode) {
    Copy-Item $js $bak -Force
    & npx --yes terser $js --compress --mangle --output $js
    
    if ($LASTEXITCODE -ne 0) { 
        Copy-Item $bak $js -Force
        Write-Warning "terser nije uspio — ostaje neminificirani JS." 
    } else { 
        # Izvučeno u zasebnu varijablu da izbjegnemo PowerShell parser bugove
        $size = [math]::Round((Get-Item $js).Length / 1KB, 1)
        Write-Host "app.js minificiran ($size KB)" 
    }
} else {
    Write-Warning "Node/npx nije pronađen — preskačem minifikaciju."
}

# 2. ZIP bez tajni i lokalnog sadrzaja
$staging = "$env:TEMP\djshop-release"
Remove-Item $staging -Recurse -Force -ErrorAction SilentlyContinue
New-Item -ItemType Directory -Path $staging | Out-Null
robocopy $root $staging /E /XD .git logs node_modules tools /XF config.php app.dev.js *.zip *.log | Out-Null

# uploads: zadrzi strukturu + .htaccess + index.html, izbaci sadrzaj
Get-ChildItem "$staging\uploads" -Recurse -File | Where-Object { $_.Name -notin @('.htaccess','index.html') } | Remove-Item -Force

Remove-Item $out -Force -ErrorAction SilentlyContinue
Compress-Archive -Path "$staging\*" -DestinationPath $out

# vrati dev JS u radnu kopiju
if (Test-Path $bak) { 
    Copy-Item $bak $js -Force
    Remove-Item $bak -Force 
}

Write-Host "Gotovo: $out" -ForegroundColor Green