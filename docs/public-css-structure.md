# Styling van de publieke RC045-website

## Structuur

- **`styles.css`** bevat de gedeelde basis: kleurvariabelen (licht/donker), typografie, knoppen, navigatie, footer, gedeelde hero-geometrie en toegankelijkheidsstijlen.
- **`page-styles/<pagina>.css`** bevat uitsluitend de eigen opmaak van de zeven publieke pagina's: `index`, `aanmelden`, `baanreglement`, `fotoboek`, `media`, `ontstaan` en `bedankt`.
- **`site-styles.php`** serveert de gedeelde stylesheet.
- **`page-styles.php?page=<pagina>`** serveert uitsluitend een van de zeven toegestane pagina-stylesheets. De twee links staan in deze volgorde in de HTML, zodat pagina-specifieke overschrijvingen behouden blijven.

Bestaande cachingheaders (`no-store`) zijn bewust niet gewijzigd. Eventuele toekomstige cachingoptimalisatie is een afzonderlijk auditpunt.

## Wijzigingsregels

1. Gemeenschappelijke componenten één keer definiëren in `styles.css`. Voor elke nieuwe pagina alleen pagina-specifieke CSS in `page-styles/`.
2. Niet terugvallen op grote interne `<style>`-blokken of nieuwe inline `style`-attributen. Er bestaan nog enkele inline-attributen voor door JavaScript gemanipuleerde zichtbaarheid, waarschuwingen en dynamisch gegenereerde opmaak; die zijn bewust intact gelaten.
3. Fotoachtergronden worden pagina-specifiek gekozen, maar de gedeelde geometrie van `.page-hero-bg` staat slechts één keer in `styles.css`.
4. De CSS van `beheer.php` en `leden.php` is buiten deze wijziging gebleven. Het dashboard heeft eigen componenten en mag niet per ongeluk gedeelde publieke overrides krijgen.
5. Pagina's hebben dezelfde bestaande `site-styles.php`-link én de bijbehorende `page-styles.php`-link. Behoud deze cascadevolgorde.

## Automatische controles

GitHub Actions toetst PHP-syntaxis, het CSS-endpoint (inclusief ongeldige paginanaam), cascadevolgorde, WCAG-contrast, navigatie en mobiele opmaak. Chromium controleert voor alle zeven pagina's of de pagina-CSS daadwerkelijk geladen en toegepast wordt, en voert de bestaande mobiele/vertalingsregressies uit.

Dit is een structuurwijziging. De kleuren, content, foto-URL's, interacties en lichte/donkere modus zijn niet bewust veranderd.
