# Kerken in Hilversum

Statische website voor protestantse en evangelische kerken in en rond Hilversum.
De site helpt bezoekers om kerken, activiteiten en het interkerkelijke Zomerfeest
te ontdekken.

## Pagina's

- `index.html` - homepage met kerkengids, filters, missie/intro en agenda.
- `zomerfeest.html` - aparte landingspagina voor het interkerkelijke Zomerfeest.
- `zomerfeest-programma.html` - mobiele dagpagina met programma, liederen en
  bijbelteksten.
- `emails/zomerfeest-aangemeld.html` - HTML-mail voor aangemelde deelnemers.

## Belangrijke assets

- `assets/hero.png` - hero image voor de homepage.
- `assets/flyer-zomerfeest.jpeg` - flyer/inspiratiebeeld voor Zomerfeest.
- `assets/zomerfeest-hero.png` - aparte hero image voor de Zomerfeest-pagina.
- `assets/favicon.svg` - favicon.

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
- Google Analytics meet nieuwe aanmeldingen als `zomerfeest_aanmelding_nieuw`
  en bijgewerkte aanmeldingen als `zomerfeest_aanmelding_bijwerken`.
- Vrijwilligersknop via mailto in de organisatie-sectie.
- CLI-script voor een eenmalige HTML-mail naar aangemelde deelnemers, met
  dry-run, testmodus en bescherming tegen dubbel verzenden.

## E-mail naar deelnemers versturen

Het script `scripts/send-zomerfeest-email.php` leest geldige, unieke adressen
uit `zomerfeest_signups_2026` en verstuurt per deelnemer één afzonderlijke
HTML-mail. Het kan op de hostingserver of op een lokale machine worden gestart.
Gebruik lokaal bij voorkeur SMTP. Voeg hiervoor deze instellingen toe aan de
array in het lokale, genegeerde `api/config.php`:

```php
'mail_transport' => 'smtp',
'mail_from' => 'zomerfeest@kerkeninhilversum.nl',
'mail_from_name' => 'Zomerfeest',
'mail_reply_to' => 'website@kerkeninhilversum.nl',
'mail_delay_ms' => 250,
'smtp_host' => 'smtp.example.nl',
'smtp_port' => 587,
'smtp_encryption' => 'tls',
'smtp_user' => 'zomerfeest@kerkeninhilversum.nl',
'smtp_password' => 'VUL_HIER_HET_SMTP_WACHTWOORD_IN',
```

Voor lokaal gebruik moeten ook `db_host`, `db_name`, `db_user` en `db_password`
in die lokale configuratie naar de online database verwijzen. De databasehost
moet externe verbindingen vanaf deze machine toestaan. Voer het script vanuit
de projectmap uit en begin altijd met een dry-run en een testmail:

```sh
php scripts/send-zomerfeest-email.php
php scripts/send-zomerfeest-email.php --test=jouw-adres@example.nl
php scripts/send-zomerfeest-email.php --send
```

Na een geaccepteerde mail registreert het script de combinatie van campagne en
e-mailadres in `zomerfeest_email_deliveries`. Daardoor worden die adressen bij
een volgende `--send` overgeslagen. Gebruik alleen bewust `--send --resend` als
iedereen de mail opnieuw moet ontvangen. Met bijvoorbeeld `--limit=10` kan een
kleine batch worden verwerkt.

Het script is alleen via de commandoregel uitvoerbaar en de map `scripts/` is
ook via `.htaccess` afgeschermd voor webverkeer. Wie op de hosting de lokale
PHP-mailserver wil gebruiken, kan `mail_transport` op `mail` zetten; de
SMTP-instellingen zijn dan niet nodig. PHP heeft voor lokaal gebruik de
extensies `pdo_mysql`, `mbstring` en `openssl` nodig.

### Zonder terminaltoegang

Na deployment is het beveiligde mailingbeheer beschikbaar op:

```text
https://www.kerkeninhilversum.nl/api/zomerfeest-mailing.php
```

Voeg op de server aan `api/config.php` ook een uniek wachtwoord van minimaal 16
tekens toe:

```php
'mail_admin_password' => 'GEBRUIK_HIER_EEN_LANG_UNIEK_WACHTWOORD',
'mail_from' => 'zomerfeest@kerkeninhilversum.nl',
'mail_from_name' => 'Zomerfeest',
'mail_reply_to' => 'website@kerkeninhilversum.nl',
'mail_delay_ms' => 250,
```

De beheerpagina werkt uitsluitend via HTTPS. Na inloggen kan eerst één testmail
worden verstuurd. De deelnemersmailing gaat daarna in batches van maximaal tien
adressen om time-outs te vermijden. Alleen succesvol door PHP `mail()`
geaccepteerde adressen worden geregistreerd als verzonden. E-mailadressen worden
niet in het dashboard getoond.

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
styles.css?v=20260617-17 script.js?v=20260617-17
```

## Deployment

Deployment loopt via GitHub Actions:

```text
.github/workflows/deploy-www-ftp.yml
```

De workflow uploadt:

- `index.html`
- `zomerfeest.html`
- `zomerfeest-programma.html`
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
- Vul het definitieve programma, de liedteksten en bijbelteksten in op
  `zomerfeest-programma.html`.
- Controleer na visuele wijzigingen desktop en mobiel op tekstoverlap en
  horizontale overflow.
- Laat `.env` lokaal; dit bestand hoort niet in deployment.
