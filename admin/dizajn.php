<?php
/** Dizajn — preseti, boje, fontovi, hero, sekcije, logo, custom CSS. */
require_once __DIR__ . '/templates/init.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'theme') {
        $theme = [
            'preset'    => isset(Theme::PRESETS[$_POST['preset'] ?? '']) ? $_POST['preset'] : 'djurdja',
            'primary'   => preg_match('/^#[0-9a-f]{6}$/i', $_POST['primary'] ?? '') && !empty($_POST['use_custom']) ? $_POST['primary'] : null,
            'primary2'  => preg_match('/^#[0-9a-f]{6}$/i', $_POST['primary2'] ?? '') && !empty($_POST['use_custom']) ? $_POST['primary2'] : null,
            'accent'    => preg_match('/^#[0-9a-f]{6}$/i', $_POST['accent'] ?? '') && !empty($_POST['use_custom']) ? $_POST['accent'] : null,
            'font_pair' => isset(Theme::FONT_PAIRS[$_POST['font_pair'] ?? '']) ? $_POST['font_pair'] : 'system',
            'radius'    => in_array($_POST['radius'] ?? '', ['none', 'soft', 'round'], true) ? $_POST['radius'] : 'soft',
            // Sprema se UVIJEK (i na free planu) — primjenjuje se tek na plaćenom
            // (Theme::get() na izlogu sam forsira default dok plan nije aktivan)
            'custom_css'=> mb_substr((string) ($_POST['custom_css'] ?? ''), 0, 20000),
        ];
        Settings::setJson('theme', $theme);

        // Logo upload
        if (!empty($_FILES['logo']['name'])) {
            $v = Security::validateImageUpload($_FILES['logo'], 2097152);
            if ($v['ok']) {
                $fn = 'logo-' . Security::randomFileName($v['ext']);
                if (move_uploaded_file($_FILES['logo']['tmp_name'], SHOP_ROOT . '/uploads/theme/' . $fn)) {
                    Images::optimize(SHOP_ROOT . '/uploads/theme/' . $fn, 600, 240);
                    $old = s('logo');
                    if ($old) @unlink(SHOP_ROOT . '/uploads/theme/' . $old);
                    Settings::set('logo', $fn);
                }
            } else {
                flash('error', 'Logo: ' . $v['error']);
            }
        }
        if (!empty($_POST['remove_logo'])) {
            $old = s('logo');
            if ($old) @unlink(SHOP_ROOT . '/uploads/theme/' . $old);
            Settings::set('logo', null);
        }
        flash('success', 'Dizajn spremljen. Pogledajte trgovinu!');
    } elseif ($action === 'hero') {
        $hero = [
            'style'    => in_array($_POST['style'] ?? '', ['gradient', 'image', 'minimal'], true) ? $_POST['style'] : 'gradient',
            'eyebrow'  => mb_substr(trim((string) ($_POST['eyebrow'] ?? '')), 0, 90),
            'title'    => mb_substr(trim((string) $_POST['title']), 0, 120),
            'subtitle' => mb_substr(trim((string) $_POST['subtitle']), 0, 250),
            'cta_text' => mb_substr(trim((string) $_POST['cta_text']) ?: 'Razgledaj ponudu', 0, 50),
            'cta_link' => mb_substr(trim((string) $_POST['cta_link']) ?: url('proizvodi.php'), 0, 250),
            'image'    => Theme::hero()['image'],
            'align'    => ($_POST['align'] ?? '') === 'center' ? 'center' : 'left',
            'overlay'  => max(0, min(85, (int) ($_POST['overlay'] ?? 45))),
            'height'   => in_array($_POST['height'] ?? '', ['compact', 'normal', 'full'], true) ? $_POST['height'] : 'normal',
            'parallax' => !empty($_POST['parallax']) ? 1 : 0,
        ];
        if (!empty($_FILES['hero_image']['name'])) {
            $v = Security::validateImageUpload($_FILES['hero_image']);
            if ($v['ok']) {
                $fn = 'hero-' . Security::randomFileName($v['ext']);
                if (move_uploaded_file($_FILES['hero_image']['tmp_name'], SHOP_ROOT . '/uploads/theme/' . $fn)) {
                    Images::optimize(SHOP_ROOT . '/uploads/theme/' . $fn, 1920, 640); // hero = širi format
                    if ($hero['image']) @unlink(SHOP_ROOT . '/uploads/theme/' . $hero['image']);
                    $hero['image'] = $fn;
                }
            } else {
                flash('error', 'Hero slika: ' . $v['error']);
            }
        }
        Settings::setJson('hero', $hero);
        Settings::setJson('sections', [
            'categories' => !empty($_POST['sec_categories']),
            'featured'   => !empty($_POST['sec_featured']),
            'usp'        => !empty($_POST['sec_usp']),
            'newsletter' => !empty($_POST['sec_newsletter']),
        ]);
        flash('success', 'Naslovnica spremljena.');
    }
    redirect('admin/dizajn.php');
}

$theme = Theme::get(false); // false = prikaži SPREMLJENE vrijednosti (i na free planu se čuvaju)
$paid = Djurdja::customizationAllowed();
$themeCfg = Settings::getJson('theme');
$hero = Theme::hero();
$sections = Theme::sections();

$pageTitle = 'Dizajn trgovine';
require __DIR__ . '/templates/header.php';
?>
<form method="post" enctype="multipart/form-data">
<?= csrf_field() ?><input type="hidden" name="action" value="theme">
<div class="acard">
  <h3>🎨 Tema</h3>
  <div class="preset-grid">
    <?php foreach (Theme::PRESETS as $key => $pr): ?>
      <label class="preset <?= ($theme['preset'] ?? 'djurdja') === $key ? 'selected' : '' ?>" onclick="document.querySelectorAll('.preset').forEach(p=>p.classList.remove('selected'));this.classList.add('selected')">
        <input type="radio" name="preset" value="<?= e($key) ?>" <?= ($theme['preset'] ?? 'djurdja') === $key ? 'checked' : '' ?> style="display:none">
        <span class="sw"><span style="background:<?= e($pr['primary']) ?>"></span><span style="background:<?= e($pr['primary2']) ?>"></span><span style="background:<?= e($pr['accent']) ?>"></span><span style="background:<?= e($pr['bg']) ?>;border:1px solid #e0e0ea"></span></span>
        <span class="nm"><?= e($pr['label']) ?></span>
      </label>
    <?php endforeach; ?>
  </div>

  <label class="acheck" style="margin-top:18px"><input type="checkbox" name="use_custom" <?= !empty($themeCfg['primary']) ? 'checked' : '' ?>> Vlastite boje (override preseta)</label>
  <div class="aform-grid" style="grid-template-columns:repeat(3,1fr)">
    <div><label class="al">Primarna</label><input type="color" name="primary" value="<?= e($themeCfg['primary'] ?? $theme['primary']) ?>" style="width:100%;height:42px;border:1.5px solid #e0e0ea;border-radius:10px;cursor:pointer"></div>
    <div><label class="al">Primarna 2 (gradijent)</label><input type="color" name="primary2" value="<?= e($themeCfg['primary2'] ?? $theme['primary2']) ?>" style="width:100%;height:42px;border:1.5px solid #e0e0ea;border-radius:10px;cursor:pointer"></div>
    <div><label class="al">Akcent</label><input type="color" name="accent" value="<?= e($themeCfg['accent'] ?? $theme['accent']) ?>" style="width:100%;height:42px;border:1.5px solid #e0e0ea;border-radius:10px;cursor:pointer"></div>
  </div>

  <div class="aform-grid" style="margin-top:6px">
    <div><label class="al">Fontovi</label>
      <select class="ainput" name="font_pair">
        <?php foreach (Theme::FONT_PAIRS as $k => $fp): ?>
          <option value="<?= e($k) ?>" <?= $theme['font_pair'] === $k ? 'selected' : '' ?>><?= e($fp['label']) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div><label class="al">Zaobljenost rubova</label>
      <select class="ainput" name="radius">
        <option value="none" <?= $theme['radius'] === 'none' ? 'selected' : '' ?>>Oštri (tehno)</option>
        <option value="soft" <?= $theme['radius'] === 'soft' ? 'selected' : '' ?>>Meki (preporučeno)</option>
        <option value="round" <?= $theme['radius'] === 'round' ? 'selected' : '' ?>>Jako obli</option>
      </select></div>
    <div><label class="al">Logo (PNG/JPG/WEBP, max 2 MB)</label><input type="file" class="ainput" name="logo" accept="image/png,image/jpeg,image/webp"></div>
    <div style="align-self:end">
      <?php if (s('logo')): ?>
        <img src="<?= e(upload_url('theme/' . s('logo'))) ?>" alt="logo" style="height:40px;vertical-align:middle;margin-right:10px">
        <label class="acheck" style="display:inline-flex"><input type="checkbox" name="remove_logo" value="1"> ukloni</label>
      <?php else: ?><span class="sub">Bez loga — prikazuje se naziv trgovine.</span><?php endif; ?>
    </div>
  </div>

  <?php if (!$paid): ?>
    <div style="margin-top:16px;border-radius:14px;padding:18px 20px;color:#fff;background:linear-gradient(135deg,#6d28d9,#a855f7 60%,#ec4899);box-shadow:0 8px 24px rgba(124,58,237,.35)">
      <div style="font-size:15px;font-weight:800;letter-spacing:.2px">🔒 Teme i prilagodbe su pogodnost plaćenog paketa</div>
      <p style="font-size:13px;margin:8px 0 10px;opacity:.95;line-height:1.65">
        Slobodno već sada složite izgled iz snova — <strong>sve se postavke čuvaju</strong> i automatski
        primijene istog trena kad aktivirate paket. Do tada posjetitelji vide zadanu Đurđa temu.
        Istekne li paket, trgovina se sama vrati na zadano — a vaše podešavanje čeka spremno za povratak.
      </p>
      <ul style="font-size:12.5px;margin:0 0 12px;padding-left:18px;line-height:1.9;opacity:.95">
        <li><strong>6 premium tema</strong> + vlastite boje, fontovi i zaobljenja</li>
        <li><strong>Vlastiti CSS</strong> za potpunu kontrolu izgleda</li>
        <li><strong>Blog</strong> za SEO sadržaj koji dovodi kupce s Googlea</li>
        <li>Bez "Pokreće MojaĐurđa" potpisa + <strong>vaš logo na računima</strong></li>
      </ul>
      <a class="abtn sm" style="background:#fff;color:#6d28d9;font-weight:800" target="_blank"
         href="https://mojadjurdja.com/cjenik?utm_source=webshop&utm_medium=design&utm_campaign=themes">⚡ Otključaj sve — pogledaj pakete ↗</a>
    </div>
  <?php endif; ?>

  <label class="al" style="margin-top:14px">Napredno: vlastiti CSS <span class="badge <?= $paid ? 'violet' : 'amber' ?>"><?= $paid ? 'plaćeni plan' : '🔒 plaćeni plan — sprema se, primjenjuje nakon aktivacije' ?></span></label>
  <textarea class="ainput" name="custom_css" rows="4" placeholder=".hero h1 { text-transform: uppercase; }" <?= $paid ? '' : 'readonly style="opacity:.6"' ?>><?= e($theme['custom_css']) ?></textarea>
  <button class="abtn" style="margin-top:16px">💾 Spremi temu</button>
</div>
</form>

<form method="post" enctype="multipart/form-data">
<?= csrf_field() ?><input type="hidden" name="action" value="hero">
<div class="acard">
  <h3>🖼 Naslovnica</h3>
  <div class="aform-grid">
    <div><label class="al">Hero stil</label>
      <select class="ainput" name="style">
        <option value="gradient" <?= $hero['style'] === 'gradient' ? 'selected' : '' ?>>Gradijent (boje teme)</option>
        <option value="image" <?= $hero['style'] === 'image' ? 'selected' : '' ?>>Velika fotografija</option>
        <option value="minimal" <?= $hero['style'] === 'minimal' ? 'selected' : '' ?>>Minimalistički</option>
      </select></div>
    <div><label class="al">Hero fotografija (automatski se optimizira na 1920 px)</label><input type="file" class="ainput" name="hero_image" accept="image/jpeg,image/png,image/webp"><?php if ($hero['image']): ?><span class="sub">trenutno: <?= e($hero['image']) ?></span><?php endif; ?></div>
    <div><label class="al">Mala oznaka iznad naslova (prazno = bez oznake)</label><input class="ainput" name="eyebrow" maxlength="90" value="<?= e($hero['eyebrow']) ?>" placeholder="✓ Fiskalizirani račun uz svaku kupnju"></div>
    <div><label class="al">Poravnanje teksta</label>
      <select class="ainput" name="align">
        <option value="left" <?= $hero['align'] === 'left' ? 'selected' : '' ?>>Lijevo</option>
        <option value="center" <?= $hero['align'] === 'center' ? 'selected' : '' ?>>Sredina</option>
      </select></div>
    <div><label class="al">Visina hero sekcije</label>
      <select class="ainput" name="height">
        <option value="compact" <?= $hero['height'] === 'compact' ? 'selected' : '' ?>>Smanjeno (kompaktno)</option>
        <option value="normal" <?= $hero['height'] === 'normal' ? 'selected' : '' ?>>Normalno</option>
        <option value="full" <?= $hero['height'] === 'full' ? 'selected' : '' ?>>Preko cijelog ekrana</option>
      </select></div>
    <div><label class="al">Zatamnjenje fotografije (%) — čitljivost teksta</label><input class="ainput" type="number" min="0" max="85" name="overlay" value="<?= (int) $hero['overlay'] ?>"></div>
    <div style="display:flex;align-items:end"><label class="acheck" style="margin:0"><input type="checkbox" name="parallax" <?= $hero['parallax'] ? 'checked' : '' ?>> Parallax efekt (slika "stoji" dok se stranica miče; na mobitelu se automatski isključuje)</label></div>
    <div class="full"><label class="al">Naslov (prazno = "Dobrodošli u …")</label><input class="ainput" name="title" maxlength="120" value="<?= e($hero['title']) ?>"></div>
    <div class="full"><label class="al">Podnaslov</label><input class="ainput" name="subtitle" maxlength="250" value="<?= e($hero['subtitle']) ?>"></div>
    <div><label class="al">Tekst gumba</label><input class="ainput" name="cta_text" maxlength="50" value="<?= e($hero['cta_text']) ?>"></div>
    <div><label class="al">Link gumba</label><input class="ainput" name="cta_link" maxlength="250" value="<?= e($hero['cta_link']) ?>"></div>
  </div>
  <p class="sub" style="margin-top:14px">Sekcije naslovnice:</p>
  <div style="display:flex;gap:18px;flex-wrap:wrap">
    <label class="acheck"><input type="checkbox" name="sec_categories" <?= $sections['categories'] ? 'checked' : '' ?>> Kategorije</label>
    <label class="acheck"><input type="checkbox" name="sec_featured" <?= $sections['featured'] ? 'checked' : '' ?>> Izdvojeni proizvodi</label>
    <label class="acheck"><input type="checkbox" name="sec_usp" <?= $sections['usp'] ? 'checked' : '' ?>> Prednosti (USP)</label>
    <label class="acheck"><input type="checkbox" name="sec_newsletter" <?= $sections['newsletter'] ? 'checked' : '' ?>> Newsletter</label>
  </div>
  <button class="abtn" style="margin-top:16px">💾 Spremi naslovnicu</button>
</div>
</form>
<?php require __DIR__ . '/templates/footer.php'; ?>
