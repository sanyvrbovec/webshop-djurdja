# 🛍️ ĐurđaShop — besplatna web trgovina za MojaĐurđa korisnike

Profesionalna web trgovina koju instalirate na **vlastiti hosting** u 5 minuta.
Potpuno integrirana sa sustavom [MojaĐurđa](https://mojadjurdja.com): podaci o firmi,
artikli i cijene povlače se iz vašeg đurđa računa, a **svaki račun je automatski
fiskaliziran** u Poreznoj upravi (Fiskalizacija 2.0).

> ĐurđaShop je **besplatan** za sve MojaĐurđa korisnike — uključujući besplatni paket.

## ✨ Mogućnosti

- **Fiskalizacija ugrađena** — svaka plaćena narudžba dobiva JIR/ZKI kroz vaš đurđa račun; automatski retry unutar zakonskog roka od 48 h
- **Artikli iz đurđe** — jedan cjenik za blagajnu i web (sinkronizacija jednim klikom); slike, opisi i SEO uređuju se u trgovini
- **Plaćanja**: pouzeće, virman (s uputama za uplatu), kartice (Stripe Checkout)
- **Premium dizajn** — 6 tema (uklj. tamnu), vlastite boje, fontovi, hero sekcija, logo; sve bez kodiranja
- **SEO spreman** — čisti URL-ovi, schema.org (Product/Organization/Breadcrumb), sitemap.xml, Open Graph
- **Sigurnost** — CSRF zaštita, rate limiting, enkriptirane tajne (AES-256-GCM), zaštita od botova, sigurnosni headeri
- **Hrvatski jezik**, cijene u EUR, PDV razrada po stopama

## 📋 Preduvjeti

| Što | Minimalno |
|---|---|
| Hosting | bilo koji PHP hosting (Apache + mod_rewrite) |
| PHP | 8.0+ s ekstenzijama: pdo_mysql, curl, openssl, mbstring (gd preporučeno) |
| Baza | MySQL 5.7+ / MariaDB 10.3+ |
| MojaĐurđa | aktivan račun + API ključ (Postavke → API pristup) |

## 🚀 Instalacija (5 minuta)

1. **Preuzmite** zadnju verziju (Code → Download ZIP) i raspakirajte na server
   (u root domene ili poddirektorij — oboje radi).
2. U pregledniku otvorite `https://vasa-domena.hr/install/`
3. Slijedite čarobnjaka: provjera servera → baza → đurđa API ključ → admin račun.
4. Nakon instalacije **obrišite `install/` direktorij**.
5. U administraciji pokrenite **Sinkronizaciju** — artikli stižu iz đurđe.
6. Postavite hosting cron (svakih 5–15 min) na URL iz admin → Postavke
   (pokreće fiskalne retry-e i dnevni sync).

## 🔌 Kako radi integracija

```
Kupac → Trgovina (vaš server) → MojaĐurđa API → Porezna uprava (CIS)
              │                        │
        lokalna baza             vaš đurđa račun
     (artikli, narudžbe,      (firma, cjenik, plan,
      slike, dizajn)           fiskalizacija, kvota)
```

- Podaci o firmi (naziv, OIB, PDV status) **ne unose se ručno** — izvor istine je đurđa.
- Svaka fiskalizirana narudžba troši **1 dokument** iz mjesečne kvote vašeg đurđa paketa
  (besplatni paket: 30 dokumenata/mj — dijeli se s blagajnom). Stanje kvote vidite na nadzornoj ploči.
- Bez valjane veze s đurđom trgovina ne zaprima nove narudžbe (izlog ostaje dostupan).

## 📜 Uvjeti korištenja

ĐurđaShop se distribuira besplatno za korištenje uz aktivan MojaĐurđa račun.
Na **besplatnom paketu** trgovina prikazuje poveznicu *"Web trgovinu pokreće MojaĐurđa"*
u podnožju — to je dio uvjeta besplatnog korištenja i ne smije se uklanjati.
Na plaćenim paketima poveznica je opcionalna. Zabranjeno je uklanjanje ili
zaobilaženje integracije s MojaĐurđa sustavom.

## 🔐 Sigurnosne napomene

- `config/config.php` sadrži tajne — nikad ga ne dijelite niti stavljajte u git.
- Nakon instalacije obavezno obrišite `install/`.
- Tajne (đurđa secret, Stripe ključevi) čuvaju se **enkriptirane** u bazi.
- Preporuka: uvijek koristite HTTPS (Let's Encrypt je besplatan na većini hostinga).

## 🆘 Podrška

- Problemi s trgovinom: otvorite Issue na ovom repozitoriju
- Problemi s računom/fiskalizacijom/paketima: [mojadjurdja.com](https://mojadjurdja.com)

---
*Izrađeno s 💜 od strane MojaĐurđa tima.*
