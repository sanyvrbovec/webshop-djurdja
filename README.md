# 🛍️ ĐurđaShop — besplatna web trgovina za MojaĐurđa korisnike

[![Verzija](https://img.shields.io/badge/verzija-1.3.4-7c3aed)](#)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-777bb4)](#)
[![Licenca](https://img.shields.io/badge/licenca-besplatno%20uz%20MojaĐurđa-059669)](#-licenca-i-uvjeti-korištenja)

Profesionalna web trgovina koju instalirate na **vlastiti hosting** u nekoliko minuta.
Potpuno integrirana sa sustavom [MojaĐurđa](https://mojadjurdja.com): podaci o firmi,
artikli, cijene i zalihe povlače se iz vašeg đurđa računa, a **svaki račun je automatski
fiskaliziran** u Poreznoj upravi (Fiskalizacija 2.0).

> 💜 ĐurđaShop je **besplatan** za sve MojaĐurđa korisnike — uključujući besplatni paket.

**Verzija:** 1.3.4 · **Zadnje ažuriranje:** 15. lipnja 2026.

---

## 📑 Sadržaj

- [Mogućnosti](#-mogućnosti)
- [Preduvjeti](#-preduvjeti)
- [Instalacija korak po korak](#-instalacija-korak-po-korak)
- [Kako radi integracija](#-kako-radi-integracija)
- [E-mail postavke](#-e-mail-postavke)
- [Plaćanje karticom (Stripe)](#-plaćanje-karticom-stripe)
- [Zakonska usklađenost](#-zakonska-usklađenost)
- [Plaćeni paket — dodatne mogućnosti](#-plaćeni-paket--dodatne-mogućnosti)
- [Rješavanje problema](#-rješavanje-problema)
- [Sigurnosne napomene](#-sigurnosne-napomene)
- [Licenca i uvjeti korištenja](#-licenca-i-uvjeti-korištenja)
- [Podrška](#-podrška)

---

## ✨ Mogućnosti

- **Fiskalizacija ugrađena** — svaka plaćena narudžba dobiva JIR/ZKI kroz vaš đurđa račun; automatski ponovni pokušaj unutar zakonskog roka od 48 h
- **Artikli, cijene i zalihe iz đurđe** — jedan cjenik za blagajnu i web; vidljivost artikala i varijante (veličine, boje…) određujete u MojaĐurđi, trgovina ih automatski preuzima
- **Varijante proizvoda** — veličine, boje i slično, sa zasebnom cijenom i zalihom po varijanti
- **Računi kupaca** — registracija i prijava kupaca, pregled svih narudžbi i računa kroz korisnički profil
- **Blog** — SEO članci koji dovode kupce s Googlea (uključuje se u plaćenom paketu)
- **Plaćanja**: pouzeće i kartice (Stripe Checkout) — sa sklopovljem korak-po-korak vodičem
- **Premium dizajn** — teme, vlastite boje, fontovi, hero sekcija s fotografijom/parallaxom, logo; sve bez kodiranja
- **Automatska optimizacija slika** — svaka uploadana slika se smanjuje i dobiva thumbnail za brže učitavanje
- **Moderan SEO** — čisti URL-ovi, schema.org (Product/Article/Organization/Breadcrumb), `sitemap.xml`, Open Graph, `llms.txt` za AI tražilice
- **Sigurnost** — CSRF zaštita, rate limiting, enkriptirane tajne (AES-256-GCM), zaštita od botova, sigurnosni HTTP headeri
- **Zakonski usklađeno (RH 2026.)** — gumb za jednostrani raskid ugovora s automatskom potvrdom, predugovorne obavijesti, stranica o pravu na popravak
- **Hrvatski jezik**, cijene u EUR, PDV razrada po stopama (đurđa zna je li firma u sustavu PDV-a)

---

## 📋 Preduvjeti

| Što | Minimalno |
|---|---|
| Hosting | bilo koji PHP hosting (Apache + `mod_rewrite`) |
| PHP | **8.0+** s ekstenzijama: `pdo_mysql`, `curl`, `openssl`, `mbstring`, `gd` (za optimizaciju slika) |
| Baza | MySQL 5.7+ / MariaDB 10.3+ |
| MojaĐurđa | aktivan račun + API ključ (Postavke → **API pristup**) |

---

## 🚀 Instalacija korak po korak

### 1. Preuzmite trgovinu
Kliknite zeleni gumb **Code → Download ZIP** (ili `git clone`) i raspakirajte.

### 2. Prenesite na hosting
Prenesite sadržaj na server FTP-om ili File Managerom — u **root domene**
(`vasa-domena.hr`) ili **poddirektorij** (`vasa-domena.hr/shop`). Oboje radi.

### 3. Kreirajte API ključ u MojaĐurđi
U MojaĐurđa računu: **Postavke → API pristup → Novi ključ**.
Zapišite **Key ID** (`pk_...`) i **Secret** (`sk_...`) — Secret se prikazuje samo jednom.

### 4. Pokrenite čarobnjak za instalaciju
Otvorite `https://vasa-domena.hr/install/` i slijedite korake:

1. **Provjera servera** — automatska provjera PHP verzije i ekstenzija
2. **Baza podataka** — upišite podatke MySQL baze (trgovina je **sama kreira** ako ima ovlasti; ako ne, čarobnjak vam pokaže točne korake za cPanel)
3. **MojaĐurđa veza** — zalijepite Key ID i Secret; trgovina provjeri ključ i povuče podatke firme
4. **Administrator** — kreirajte korisničko ime i lozinku za administraciju trgovine

### 5. Obrišite `install/` direktorij
Iz sigurnosnih razloga, nakon uspješne instalacije **obrišite cijeli `install/` direktorij** sa servera.

### 6. Sinkronizirajte artikle
U administraciji otvorite **Sinkronizacija → Sinkroniziraj sada** — artikli, kategorije i zalihe stižu iz đurđe.
> 💡 Trgovina se i sama osvježava povremeno; za zajamčeno svježe stanje postavite hosting **cron** (svakih 5–15 min) na URL iz **Administracija → Postavke** (pokreće i fiskalne ponovne pokušaje).

---

## 🔌 Kako radi integracija

```
Kupac → Trgovina (vaš server) → MojaĐurđa API → Porezna uprava (CIS)
              │                        │
        lokalna baza             vaš đurđa račun
     (narudžbe, kupci,        (firma, cjenik, zalihe,
      slike, dizajn, blog)    varijante, plan, fiskalizacija)
```

- Podaci o firmi (naziv, OIB, PDV status, adresa, kontakt) **ne unose se ručno** — izvor istine je đurđa.
- Cijene, zalihe, vidljivost artikala i varijante uređujete u **MojaĐurđa → Web trgovina**; trgovina ih zrcali.
- Svaka fiskalizirana narudžba troši **1 dokument** iz mjesečne kvote vašeg đurđa paketa
  (besplatni paket: 30 dokumenata/mj — dijeli se s blagajnom). Stanje kvote vidite na nadzornoj ploči.
- Ako se kvota potroši, narudžba se **rezervira** (bez greške) i fiskalizirate je ručno kad nadogradite paket.
- **Fiskaliziraju se isključivo artikli** — dostava i naknade plaćanja nisu dio fiskalnog računa.

---

## 📧 E-mail postavke

Trgovina šalje potvrde narudžbi i fiskalizirane račune. U **Administracija → E-mail postavke**
birate način slanja:

- **PHP mail()** — radi na većini hostinga bez podešavanja (zadano)
- **SMTP** — pouzdanije; podržava cPanel mail račun, **Gmail** (s app lozinkom) i **Outlook/Office 365**

Svaki način ima vodič korak-po-korak u administraciji, plus gumb **Pošalji test** za provjeru.
Ako mailovi padaju u spam: koristite SMTP svojeg hostinga i adresu pošiljatelja **na vlastitoj domeni**.

---

## 💳 Plaćanje karticom (Stripe)

Kartično plaćanje je opcionalno (zadano je uključeno samo pouzeće). U
**Administracija → Plaćanja i dostava** nalazi se **detaljan vodič** kako:

1. otvoriti besplatan Stripe račun,
2. pronaći API ključeve (`pk_...` / `sk_...`),
3. postaviti webhook (`/api/stripe-webhook.php`) za automatsku potvrdu naplate,
4. testirati testnom karticom `4242 4242 4242 4242`,
5. prijeći na pravu naplatu.

Stripe tajne čuvaju se **enkriptirane** u bazi.

---

## ⚖️ Zakonska usklađenost

Trgovina sadrži alate koji olakšavaju usklađenost s propisima RH/EU:

- **Gumb za jednostrani raskid ugovora** (obveza od 19. 6. 2026.) — vidljiv u podnožju, s online obrascem i **automatskom e-mail potvrdom s točnim datumom i vremenom** (zakonski trajni medij)
- **Predugovorna obavijest** o pravu na raskid u e-mailu svake narudžbe
- **Stranica "Pravo na popravak"** (obveza od 31. 7. 2026.)
- **Administracija → Zakonska usklađenost** — kalendar obveza 2026. + izjava o odgovornosti

> ⚠️ **Važno:** ovi alati su pomoć, **ne pravni savjet ni jamstvo usklađenosti**.
> Isključivu odgovornost za zakonitost poslovanja (uključujući GDPR, porezne propise i
> zaštitu potrošača) snosi vlasnik trgovine. Detalji u administraciji.

---

## 🌟 Plaćeni paket — dodatne mogućnosti

Na besplatnom paketu trgovina prikazuje diskretnu poveznicu *"Pokreće MojaĐurđa"*.
Nadogradnjom paketa otključavate:

- uklanjanje MojaĐurđa potpisa (podnožje + računi) i **vlastiti logo na računima**
- **teme, vlastite boje, fontovi i vlastiti CSS**
- **blog** za SEO sadržaj
- veću mjesečnu kvotu fiskaliziranih dokumenata

> Sve postavke se **čuvaju** i kad ste na besplatnom paketu — primjenjuju se automatski čim aktivirate plaćeni paket.

---

## 🆘 Rješavanje problema

### Greška 500 nakon prijenosa na hosting
Najčešće ste prenijeli i `config/config.php` s drugog računala (npr. localhosta).
Taj config pokazuje na bazu koja na novom serveru ne postoji.
**Rješenje:** obrišite `config/config.php` (i `install/.lock` ako postoji), otvorite `/install/` i ponovite postavljanje s podacima **tog** hostinga. Čarobnjak prepoznaje ovu situaciju i sam vas vodi.

### Stranica je bijela / "Trgovina se osvježava"
Baza nije dostupna. Provjerite radi li MySQL i jesu li podaci u `config/config.php` točni (host je obično `localhost`).

### Mailovi ne stižu
Prebacite se na **SMTP** (E-mail postavke) i pošaljite test. Provjerite i spam mapu.

### Artikli se ne prikazuju
Pokrenite **Sinkronizaciju**. Ako i dalje prazno, na stranici Sinkronizacija
kliknite **🩺 Dijagnostika veze** — pokazat će točno koji korak ne radi.

---

## 🔐 Sigurnosne napomene

- `config/config.php` sadrži tajne — **nikad** ga ne dijelite niti stavljajte u git (već je u `.gitignore`).
- Nakon instalacije **obavezno obrišite `install/`**.
- Tajne (đurđa secret, Stripe ključevi, SMTP lozinka) čuvaju se **enkriptirane** u bazi (AES-256-GCM).
- Preporuka: uvijek koristite **HTTPS** (Let's Encrypt je besplatan na većini hostinga).
- JavaScript u distribuciji je minificiran; izvor istine (firma, cijene, plan, fiskalizacija) je **server-side** — ne može se zaobići uređivanjem koda na klijentu.

---

## 📜 Licenca i uvjeti korištenja

ĐurđaShop se distribuira **besplatno** za korištenje uz **aktivan MojaĐurđa račun**.

- Na **besplatnom paketu** trgovina prikazuje poveznicu *"Web trgovinu pokreće MojaĐurđa"* u podnožju — to je dio uvjeta besplatnog korištenja i ne smije se uklanjati.
- Na **plaćenim paketima** poveznica je opcionalna.
- Zabranjeno je uklanjanje ili zaobilaženje integracije s MojaĐurđa sustavom.

Programsku podršku izdaje tvrtka **Fork** (izdavatelj sustava MojaĐurđa). Softver se
pruža "kakav jest" (as-is), bez jamstava; odgovornost za zakonitost poslovanja snosi
vlasnik trgovine (vidi [Zakonska usklađenost](#-zakonska-usklađenost)).

---

## 🆘 Podrška

- **Problemi s trgovinom** (instalacija, greške): otvorite *Issue* na ovom repozitoriju
- **Račun, fiskalizacija, paketi**: [mojadjurdja.com](https://mojadjurdja.com)

---

<p align="center">Izrađeno s 💜 za hrvatske poduzetnike · <a href="https://mojadjurdja.com">MojaĐurđa</a></p>
