<?php
require_once __DIR__ . '/core/bootstrap.php';

$hero = Theme::hero();
$sections = Theme::sections();

$categories = $db->fetchAll(
    'SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id AND p.is_visible = 1 AND p.is_orphaned = 0) AS cnt
     FROM categories c WHERE c.is_visible = 1 ORDER BY c.sort_order, c.name'
);
$categories = array_values(array_filter($categories, fn($c) => (int) $c['cnt'] > 0));

$featured = $db->fetchAll(
    'SELECT p.*, c.name AS cat_name,
        (SELECT filename FROM product_images pi WHERE pi.product_id = p.id ORDER BY pi.is_primary DESC, pi.sort_order LIMIT 1) AS image
     FROM products p LEFT JOIN categories c ON c.id = p.category_id
     WHERE p.is_visible = 1 AND p.is_orphaned = 0
     ORDER BY p.is_featured DESC, p.created_at DESC LIMIT 8'
);

$pageTitle = '';
$pageDesc = s('seo_default_description', 'Web trgovina ' . shop_name() . ' — kvalitetni proizvodi, brza dostava i fiskalizirani račun za svaku kupnju.');
$pageJsonLd = [Seo::websiteJsonLd()];
$heroTitle = $hero['title'] !== '' ? $hero['title'] : ('Dobrodošli u ' . shop_name());
require __DIR__ . '/includes/header.php';
?>

<section class="hero <?= $hero['style'] === 'image' && $hero['image'] ? 'hero-image' : ($hero['style'] === 'minimal' ? 'hero-minimal' : 'hero-gradient') ?>"
  <?php if ($hero['style'] === 'image' && $hero['image']): ?>style="background-image:url('<?= e(upload_url('theme/' . $hero['image'])) ?>')"<?php endif; ?>>
  <div class="container">
    <div class="hero-inner fade-up">
      <span class="eyebrow">✓ Fiskalizirani račun uz svaku kupnju</span>
      <h1><?= e($heroTitle) ?></h1>
      <p><?= e($hero['subtitle']) ?></p>
      <a href="<?= e($hero['cta_link']) ?>" class="btn btn-lg <?= $hero['style'] === 'minimal' ? '' : 'btn-accent' ?>"><?= e($hero['cta_text']) ?> →</a>
    </div>
  </div>
</section>

<?php if ($sections['categories'] && $categories): ?>
<section class="section">
  <div class="container">
    <div class="section-head">
      <h2 class="section-title">Kategorije</h2>
      <a class="section-link" href="<?= e(url('proizvodi.php')) ?>">Svi proizvodi →</a>
    </div>
    <div class="cat-grid">
      <?php foreach ($categories as $c): ?>
        <a class="cat-card" href="<?= e(url('k/' . $c['slug'])) ?>">
          <span class="cnt"><?= (int) $c['cnt'] ?> proizvoda</span>
          <span class="nm"><?= e($c['name']) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if ($sections['featured'] && $featured): ?>
<section class="section" style="padding-top:0">
  <div class="container">
    <div class="section-head">
      <h2 class="section-title">Izdvojeno iz ponude</h2>
      <a class="section-link" href="<?= e(url('proizvodi.php')) ?>">Pogledaj sve →</a>
    </div>
    <div class="product-grid">
      <?php foreach ($featured as $p) include __DIR__ . '/includes/product-card.php'; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if ($sections['usp']): ?>
<section class="section" style="padding-top:0">
  <div class="container">
    <div class="usp-grid">
      <div class="usp">
        <div class="ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13" rx="1"/><path d="M16 8h4l3 4v4h-7z"/><circle cx="5.5" cy="18.5" r="2"/><circle cx="18.5" cy="18.5" r="2"/></svg></div>
        <div><h4>Brza dostava</h4><p><?php $fo = (float) s('shipping_free_over', 0); echo $fo > 0 ? 'Besplatna dostava za narudžbe iznad ' . fmt_price($fo) . '.' : 'Šaljemo u najkraćem mogućem roku.'; ?></p></div>
      </div>
      <div class="usp">
        <div class="ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg></div>
        <div><h4>Sigurna kupovina</h4><p>Plaćanje pouzećem, virmanom ili karticom — bez skrivenih troškova.</p></div>
      </div>
      <div class="usp">
        <div class="ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg></div>
        <div><h4>Račun za svaku kupnju</h4><p>Svaki račun je fiskaliziran u Poreznoj upravi — kupujete kod provjerenog trgovca.</p></div>
      </div>
      <div class="usp">
        <div class="ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8z"/></svg></div>
        <div><h4>Tu smo za vas</h4><p>Javite nam se s povjerenjem — odgovaramo brzo i rado pomažemo.</p></div>
      </div>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if ($sections['newsletter']): ?>
<section class="section" style="padding-top:0">
  <div class="container">
    <div class="newsletter">
      <h3>Budite prvi koji saznaju 💌</h3>
      <p>Nove kolekcije, akcije i posebne ponude — ravno u vaš inbox.</p>
      <form data-newsletter>
        <input type="email" required placeholder="Vaša e-mail adresa">
        <button class="btn" type="submit">Prijavi se</button>
      </form>
    </div>
  </div>
</section>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
