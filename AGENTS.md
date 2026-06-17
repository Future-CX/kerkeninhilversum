# Kerken in Hilversum Agent Guide

## Project Goal

Build and maintain a one-page website that promotes Protestant and evangelical churches and faith communities in Hilversum. The site should help residents, newcomers, families, students, and visitors quickly discover these churches, understand what they offer, and find a low-friction way to visit or submit missing information.

The tone should be warm, local, inclusive, and practical. The website should present the initiative as shared by the participating Protestant and evangelical churches; do not make one denomination, parish, or church look like the sole owner of the whole site unless the user explicitly asks for that.

The initiative churches that must remain represented are:

- Verbinding (Schuilhof en Kompas)
- Grote Kerk
- VEG
- Vitamine G
- Bethlehemkerk, Morgensterkerk, Regenboogkerk
- Gereformeerde Gemeente
- Mystery Church
- Celebration Church
- The Garden
- Hervormde Gemeente 's-Graveland
- Sypekerk

## Current Site Shape

This is a dependency-free static website:

- `index.html` contains the page content and church directory.
- `styles.css` contains all layout and visual styling.
- `script.js` contains client-side search and category filtering.
- `assets/` contains visual assets such as the hero image and favicon.

There is no build step. The site should work by opening `index.html` directly or by serving the folder with a simple static server.

## Local Preview

Use a local static server when checking browser behavior:

```bash
python3 -m http.server 8081
```

Then open:

```text
http://127.0.0.1:8081/
```

After frontend changes, verify at least:

- The hero image loads.
- The page has no console errors.
- Search and category filters still work.
- Mobile and desktop layouts have no horizontal overflow.
- Text remains readable over the hero image.

## Content Principles

- Write in Dutch by default.
- Keep church descriptions short, factual, and welcoming.
- Avoid ranking churches or implying endorsement.
- Focus the public directory on Protestant and evangelical churches.
- Use neutral labels such as `Protestants` and `Evangelisch` unless more precise categories are requested.
- Do not add Catholic, Orthodox, interfaith, or non-church social organizations to the directory unless the user explicitly broadens the scope.
- Keep all initiative churches listed in the project goal represented on the page. If exact details or websites are missing, use neutral placeholder copy such as `Informatie volgt` rather than inventing facts.
- Treat the directory as a growing guide. If coverage is incomplete, make that clear through copy like “Mist er een kerk?”
- Verify church names, websites, and factual claims before adding or changing directory entries when internet access is available.
- Do not invent exact service times, addresses, leaders, or ministries unless provided by a reliable source or the user.

## Design Direction

The site should feel like a polished local civic guide, not a generic landing page. Prioritize:

- Strong first viewport with the Hilversum/church/community signal immediately visible.
- Clear navigation to `Kerken`, `Agenda`, and `Meedoen`.
- Dense but readable church cards.
- Accessible contrast and large enough touch targets.
- Responsive behavior for phone, tablet, and desktop.

Avoid:

- Overly decorative UI that distracts from finding a church.
- One-note color palettes.
- Text-heavy hero sections that hide the actual page purpose.
- In-app explanatory text about how the UI works.

## Engineering Instructions

- Keep the implementation static unless the user asks for a framework or CMS.
- Prefer simple HTML, CSS, and vanilla JavaScript.
- Keep file names consistent with the deployed site and workflow.
- Use semantic HTML and meaningful `aria-label` values where appropriate.
- Preserve accessibility basics: heading order, link text, form labels, keyboard-friendly controls, and sufficient contrast.
- Do not add external runtime dependencies without a clear reason.
- Do not commit secrets. `.env` is intentionally ignored.
- Do not remove user changes you did not make.

## Deployment Notes

The repository contains `.github/workflows/deploy-www-ftp.yml` for FTP deployment from `main`.

Important: keep deployment paths in sync with actual files. The site currently uses:

- `styles.css`
- `assets/favicon.svg`
- `assets/hilversum-churches-hero.png`

The deploy workflow should upload `index.html`, `script.js`, `styles.css`, and the `assets/` directory.

The workflow uses FTP secrets:

- `FTP_HOST`
- `FTP_USERNAME`
- `FTP_PASSWORD`
- `FTP_REMOTE_DIR`

Never print or commit these values.

## Quality Checklist

Before finishing changes, run or perform the relevant checks:

- Static server preview works.
- Browser console has no errors.
- Network panel has no missing local assets.
- Church filters return the expected card counts.
- Empty search state appears when there are no matches.
- Mobile viewport around 390px wide is usable.
- Desktop viewport around 1440px wide is usable.
- `git status --short` is checked so the final response can list changed files accurately.
