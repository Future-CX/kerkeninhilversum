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

Er is geen build step nodig. Start een lokale static server:

```sh
python3 -m http.server 8081
```

Open daarna:

- `http://127.0.0.1:8081/`
- `http://127.0.0.1:8081/zomerfeest.html`

## Functionaliteit

- Kerkengids met zoekveld en filters voor `Protestants` en `Evangelisch`.
- CSS-only page transition tussen homepage en Zomerfeest via View Transitions.
- Zomerfeest-aanmeldformulier via `mailto:website@kerkeninhilversum.nl`.
- Vrijwilligersknop via mailto in de organisatie-sectie.

## Styling

De styling staat in `styles.css`.

Hoofdkleuren:

- Navy: `#071B2F`
- Geel: `#FFC000`
- Roze accent: `#EC2F8C`
- Cyaan accent: `#159BD3`

Titels gebruiken `Montserrat` weight `800` via Google Fonts.

Wanneer `styles.css` of `script.js` wijzigt, verhoog de querystring in beide
HTML-pagina's, bijvoorbeeld:

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
- `script.js`
- `styles.css`
- `assets/`

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
