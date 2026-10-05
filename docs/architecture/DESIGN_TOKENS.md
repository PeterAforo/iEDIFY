# Brand tokens and asset evidence

Source: `iEDIFY_Website_Content_Pack/images/03_logo-white.png`, 640 × 283 PNG. The deterministic `bin/content-inventory.php` inspection counts opaque pixels and records SHA-256, dimensions and MIME for every source asset.

## Measured source swatches

| Swatch | Meaning | Exact opaque-pixel occurrences |
|---|---|---|
| #BE4210 | Burnt orange within Africa pattern | 7546 |
| #FFFFFF | White logo lettering | 7377 |
| #990A1B | Burgundy/red within Africa pattern | 5662 |
| #000000 | Black pattern | 3293 |
| #471513 | Deep brown/red pattern | 3004 |
| #6EBD52 | Representative green lettering pixel | 1727 |
| #6EBE53 | Nearby green antialias/image variant | 1367 |
| #FF7F24 | Orange highlight | 1304 |

The green lettering contains many neighboring green values; #6EBD52 is a documented representative source pixel, not a claim that every pixel is identical.

## UI adjustments

Use original logo artwork without redrawing or recoloring it. Proposed UI primary #245C38 and dark panel #173D29 are accessibility-oriented darkened greens, not extracted source values. Supporting surfaces: white #FFFFFF, warm off-white #F7F8F2; body text #202820. Source orange/burgundy are accents; text/background combinations must pass contrast checks before use. Status colors require labels and icons/text, not color alone.

The foundation preview uses a system font stack and a textual development label, not a replacement logo. The real public design will use the supplied logo on a contrasting panel, rights-checked locally hosted display/body fonts, authentic supplied imagery and a connected four-pillar layout. Header/footer navigation, hero, public pages, dashboards and component catalog are still pending.

Mobile-first rules: 320px minimum supported viewport, clear keyboard focus and skip link, accessible errors and dialogs, no content hidden if JavaScript fails, no autoplay or scroll hijacking. Reduced-motion preferences disable nonessential transitions. Chart.js is restricted to chart pages with equivalent tables. The existing asset widths limit meaningful hero resolution; do not upscale and call it an original-resolution image.
