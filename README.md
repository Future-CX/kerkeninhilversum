# Kerken in Hilversum

Statische website voor protestantse en evangelische kerken in en rond Hilversum.
De site helpt bezoekers om kerken, activiteiten en het interkerkelijke Zomerfeest
te ontdekken.

## Pagina's

- `index.html` - homepage met kerkengids, filters, missie/intro en agenda.
- `zomerfeest.html` - aparte landingspagina voor het interkerkelijke Zomerfeest.

## Belangrijke assets

- `assets/hero.png` - hero image voor de homepage.
- `assets/flyer-zomerfeest.jpeg` - flyer/inspiratiebeeld voor Zomerfeest.
- `assets/zomerfeest-hero.png` - aparte hero image voor de Zomerfeest-pagina.
- `assets/favicon.svg` - favicon.

## Lokaal draaien

Er is geen build step nodig. Voor alleen statische preview kun je een lokale
static server starten:

```sh
python3 -m http.server 8081
```

Open daarna:

- `http://127.0.0.1:8081/`
- `http://127.0.0.1:8081/zomerfeest.html`

Voor het Zomerfeest-aanmeldformulier is PHP met MySQL nodig. Maak op de server
een eigen database en tabel aan:

```sql
CREATE TABLE zomerfeest_signups_2026 (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  created_at DATETIME NOT NULL,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(180) NOT NULL,
  age TINYINT UNSIGNED NOT NULL,
  church_or_city VARCHAR(180) NULL,
  brings_friend TINYINT(1) NOT NULL DEFAULT 0,
  friend_name VARCHAR(120) NULL,
  UNIQUE KEY uq_zomerfeest_signups_2026_email (email),
  INDEX idx_zomerfeest_signups_2026_created_at (created_at)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Als de tabel al bestaat, voeg dan de unieke index apart toe:

```sql
ALTER TABLE zomerfeest_signups_2026
  ADD UNIQUE KEY uq_zomerfeest_signups_2026_email (email);
```

Door deze unieke index overschrijft een nieuwe aanmelding met hetzelfde
e-mailadres de bestaande aanmelding.

Kopieer daarna `api/config.example.php` naar `api/config.php` op de server en
vul de databasegegevens in. `api/config.php` staat in `.gitignore` en wordt niet
mee gecommit.

De API staat op `api/zomerfeest-aanmelding.php`. Een `GET` request geeft een
health check terug; een `POST` request slaat een aanmelding op in MySQL.

## Functionaliteit

- Kerkengids met zoekveld en filters voor `Protestants` en `Evangelisch`.
- CSS-only page transition tussen homepage en Zomerfeest via View Transitions.
- Zomerfeest-aanmeldformulier via `POST api/zomerfeest-aanmelding.php` naar een
  MySQL-database.
- Botbescherming op het aanmeldformulier via een verborgen honeypot-veld en
  minimale invultijdcontrole.
- Vrijwilligersknop via mailto in de organisatie-sectie.

## Styling

De styling staat in `styles.css`.

Hoofdkleuren:

- Navy: `#071B2F`
- Geel: `#FFC000`
- Roze accent: `#EC2F8C`
- Cyaan accent: `#159BD3`

Titels gebruiken `Montserrat` weight `800` via Google Fonts.

Wanneer `styles.css` of `script.js` wijzigt, verhoog de querystring in de
HTML-pagina's waar het bestand wordt geladen, bijvoorbeeld:

```html
styles.css?v=20260617-17
script.js?v=20260617-17
```

## Deployment

Deployment loopt via GitHub Actions:

```text
.github/workflows/deploy-www-ftp.yml
```

De workflow uploadt:

- `index.html`
- `zomerfeest.html`
- `.htaccess`
- `api/`
- `script.js`
- `styles.css`
- `assets/`

Let op: `api/config.php` wordt bewust niet vanuit git geüpload. Plaats dit
bestand eenmalig op de server of beheer het apart met veilige hostingconfiguratie.

Benodigde GitHub secrets:

- `FTP_HOST`
- `FTP_USERNAME`
- `FTP_PASSWORD`
- `FTP_REMOTE_DIR`

## Onderhoud

- Houd kerklinks en teksten actueel in `index.html`.
- Houd Zomerfeest-informatie actueel in `zomerfeest.html`.
- Controleer na visuele wijzigingen desktop en mobiel op tekstoverlap en
  horizontale overflow.
- Laat `.env` lokaal; dit bestand hoort niet in deployment.
