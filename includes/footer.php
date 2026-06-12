<?php
/** Storefront footer + cart drawer + skripte. */
$company = Djurdja::company();
$footerPages = $db->fetchAll('SELECT slug, title FROM pages WHERE is_visible = 1 AND in_footer = 1 ORDER BY sort_order, id');
$branding = Djurdja::brandingRequired();
$host = $_SERVER['HTTP_HOST'] ?? '';
$djLink = 'https://mojadjurdja.com/?utm_source=webshop&utm_medium=footer&utm_campaign=poweredby&ref=' . rawurlencode($host);
?>
</main>

<footer class="site-footer">
  <div class="container">
    <div class="footer-grid">
      <div class="footer-company">
        <div class="fc-name"><?= e(shop_name()) ?></div>
        <?php if (!empty($company['companyName'])): ?>
          <?= e($company['companyName']) ?><br>
          <?php if (!empty($company['address'])): ?><?= e($company['address']) ?>, <?= e($company['postalCode'] ?? '') ?> <?= e($company['city'] ?? '') ?><br><?php endif; ?>
          OIB: <?= e($company['companyOib'] ?? '—') ?><br>
          <?= !empty($company['inVatSystem']) ? 'Tvrtka je u sustavu PDV-a.' : 'Tvrtka nije u sustavu PDV-a.' ?>
        <?php endif; ?>
      </div>
      <div>
        <h4>Informacije</h4>
        <ul>
          <?php foreach ($footerPages as $fp): ?>
            <li><a href="<?= e(url('s/' . $fp['slug'])) ?>"><?= e($fp['title']) ?></a></li>
          <?php endforeach; ?>
          <li><a href="<?= e(url('kontakt.php')) ?>">Kontakt</a></li>
        </ul>
      </div>
      <div>
        <h4>Kupovina</h4>
        <ul>
          <li><a href="<?= e(url('proizvodi.php')) ?>">Svi proizvodi</a></li>
          <li><a href="<?= e(url('kosarica.php')) ?>">Košarica</a></li>
          <li>Plaćanje: pouzeće<?php if ((new PaymentManager())->getMethod('stripe')['is_active'] ?? 0): ?>, kartice<?php endif; ?></li>
          <li>Račun (fiskaliziran) uz svaku kupnju</li>
        </ul>
      </div>
      <div>
        <h4>Newsletter</h4>
        <p style="font-size:13.5px;margin:0 0 12px">Povremene novosti i posebne ponude. Bez spama.</p>
        <form data-newsletter style="display:flex;gap:8px">
          <input type="email" required placeholder="vas@email.hr" class="f-input" style="background:#fff;color:#111827">
          <button class="btn btn-sm" type="submit">Prijava</button>
        </form>
      </div>
    </div>
    <div class="footer-bottom">
      <div>© <?= date('Y') ?> <?= e(shop_name()) ?>. Sva prava pridržana.</div>
      <?php if ($branding): ?>
        <div class="powered-by"><span class="pb-logo">Đ</span> Web trgovinu pokreće
          <a href="<?= e($djLink) ?>" title="MojaĐurđa — fiskalna blagajna, računi i web trgovina">MojaĐurđa</a>
        </div>
      <?php elseif (s('show_djurdja_credit', '1') === '1'): ?>
        <div class="powered-by"><a href="<?= e($djLink) ?>" style="color:#94a3b8;font-weight:600">Pokreće MojaĐurđa</a></div>
      <?php endif; ?>
    </div>
  </div>
</footer>

<div id="drawer-backdrop" class="drawer-backdrop" data-close-cart></div>
<aside id="cart-drawer" class="cart-drawer" aria-label="Košarica">
  <div class="cd-head">
    <h3>Košarica 🛍</h3>
    <button class="icon-btn" data-close-cart aria-label="Zatvori">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
    </button>
  </div>
  <div class="cd-items"><div class="cd-empty">Vaša košarica je prazna.</div></div>
  <div class="cd-foot">
    <div class="sum"><span>Međuzbroj</span><span data-cart-subtotal>0,00 €</span></div>
    <a href="<?= e(url('narudzba.php')) ?>" class="btn" style="width:100%">Na blagajnu →</a>
    <a href="<?= e(url('kosarica.php')) ?>" class="btn btn-ghost btn-sm" style="width:100%;margin-top:8px">Pregled košarice</a>
  </div>
</aside>

<div id="toast-wrap"></div>
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
