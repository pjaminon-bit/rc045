# RC045: beheer van afbeeldingen

## Welke bestanden horen waar?

- **Vaste websiteafbeeldingen:** rechtstreeks in `images/`, als de pagina's ze met een vaste bestandsnaam gebruiken, bijvoorbeeld `images/crawlergroep.jpg`. Bewaar deze ongewijzigd in GitHub. Na controle mogen ze door de reguliere FTPS-deployment worden gepubliceerd.
- **Dynamische fotoboekbestanden:** `images/fotoboek/`, inclusief alle albums, video's en thumbnails. Deze zijn uitsluitend serverbeheer via `beheer.php`; nooit opnemen in GitHub of automatisch overschrijven.
- **Dynamische sponsorlogo's:** `images/sponsors/`. Ook uitsluitend via `beheer.php` en op de server houden.
- **Logo en iconen in de repository:** bijvoorbeeld `rc045-logo.png`, favicons en `assets/radio/`. Die staan al onder versiebeheer.
- **JSON en back-ups:** `data/`, `data-backups/` blijven server-only.

## Controle en bescherming

De gitignore en FTPS-deployment sluiten de dynamische mappen uit. Ook als er ooit per ongeluk lokaal een fotoalbum of sponsorlogo wordt opgeslagen, mag dit niet in een commit terechtkomen.

De vaste afbeeldingen zijn 1-op-1 over te nemen van de bestaande hosting. Geen nieuwe compressie, hernoeming, gegenereerde vervangers of verwijderen van bestanden. Als een vaste afbeelding op de server ontbreekt, moet dit expliciet worden gerapporteerd. Een ontbrekende afbeelding is geen reden om foto's van andere categorieën te gebruiken.

Bij een volgende wijziging in een publieke pagina met een nieuw vast `images/*.jpg`-bestand moet die afbeelding óók in de repository worden opgenomen. Voor afbeeldingen die door beheerders via het dashboard worden toegevoegd, blijft het uploadbeheer op de server de bron van waarheid.

## Migratie

Inventarisatie van de vaste afbeeldingsverwijzingen komt uit de actuele publieke PHP/HTML/CSS/JS-bronnen. De eenmalige FTPS-import is alleen-lezen voor de productiehosting en schrijft ontbrekende bestanden uitsluitend naar de GitHub-featurebranch. Controleer de ontbrekende bestandsnamen in het workflowlog voordat de import wordt gepubliceerd.

De bestaande FTPS-deployment staat ingesteld met `dangerous-clean-slate: false` en uitzonderingen voor alle dynamische uploadmappen. Die uitzonderingen mogen niet worden verwijderd.

## Resultaat inventarisatie op 30 oktober 2026

Van de 35 unieke vaste afbeeldingsverwijzingen zijn 34 originele afbeeldingen (samen 18.077.576 bytes) rechtstreeks van de productiehosting overgenomen naar de repository. De SHA256-controlewaarden staan in het importlog van GitHub Actions-run 41057218666. De fotoboek- en sponsoruploadmappen zijn niet geïmporteerd.

**Nog ontbrekend op de productiehosting:** `images/hero-achtergrond.jpg`, waarnaar `aanmelden.php` en `baanreglement.php` verwijzen. Dit bestand is bewust niet vervangen door een niet-overeenkomende foto. Hiervoor is een afzonderlijke keuze van een passende afbeelding nodig.
