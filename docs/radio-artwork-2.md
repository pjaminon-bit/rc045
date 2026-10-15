# Radio Artworkherkenning 2.0 — technische notities

## Afbakening

Deze wijziging gebruikt uitsluitend de **al door de radio herkende artiest en titel**.
De bestaande trackinfo-providers, parsing, fallback, stale-checks, pre-roll,
metadata-refresh, stations, streams, audiobediening, Media Session, casting,
Spotify/Apple/Deezer/TIDAL en Wikipedia-trackinformatie blijven ongemoeid.

De huidige stationstegel blijft bestaan. De artworkachtergrond is optioneel.
De schakelaar **Albumart tonen**, inclusief de bestaande localStorage-voorkeur,
blijft actief. Ingevlogen afbeeldingen worden pas na een geslaagde afbeeldingsload
zichtbaar; bij ontbrekende, beschadigde of afgewezen afbeeldingen blijft het
gekleurde stationstegelontwerp over. Ook na zenderwissels worden achterhaalde
fetch- en imagepreload-resultaten genegeerd.

## Algoritme

1. Verwijder variaties in Unicode-typografie, accenten, hoofdletters en
   interpunctie, zonder losse substringmatches tussen verschillende artiesten.
2. Vergelijk opnamen met de huidige titel en de uitvoering. Een live-uitvoering,
   remix of instrumental mag niet als reguliere studio-opname worden behandeld;
   radio-edits en een remaster-kwalificatie kunnen onder voorwaarden dezelfde
   originele release gebruiken.
3. Zoek MusicBrainz-opnamen en hun officieel uitgegeven albums/singles/EP's.
   Gebruik Recording/Release/Release Group-relaties in plaats van alleen de
   eerste resultaten. Zoek daarna zo nodig een unieke MusicBrainz-artiest-ID
   met echte aliasgegevens; volg pas dan een MBID-zoekroute. Een gelijknamige
   artiest zonder voldoende zekerheid krijgt geen impliciet ID.
4. Zoek eventueel de **officiële single** via release-groups, inclusief
   specifieke soundtrack-singles. Compilaties, livealbums, remixes en
   Various Artists-hoesafbeeldingen worden niet als standaardalbum gekozen.
5. Ken deterministische scores toe aan opname-overeenkomst, artiest en
   release-artiest, officiële status, releasetype en datering. De matcher
   accepteert alleen scores >= 83 en geeft de reden en score terug in
   `artworkRankedReleases` en `artworkRankedSingles` (testbaar zonder API).
6. Controleer maximaal zeven verschillende releasegroepen bij Cover Art
   Archive. Gebruik 500px indien mogelijk, browserfallback 250px bij laadfout.
   De server slaat **geen albumhoezen** op; alleen de afbeeldings-URL.
7. Als er geen betrouwbare hoes is, probeer een Wikimedia Commons-foto met
   de benodigde licentie-/auteursinformatie. Anders blijft de stationstegel.

## Grenzen en caching

| Eigenschap | Waarde / beleid |
|---|---|
| MusicBrainz | Maximaal vijf zoek-/browseaanvragen per koude lookup |
| MusicBrainz-tempo | Centrale bestandslock, minimaal 1,2 seconden afstand |
| Cover Art Archive | Maximaal zeven geprobeerde releasegroepen per lookup |
| Serverwerk | Doelbudget maximaal 11 seconden; afzonderlijke cURL-timeouts |
| Concurrentie | Zelfde artiest+titel: gedeelde bestandslock; tweede bezoeker krijgt een bestaande/stale waarde of een korte tijdelijke fout |
| Positieve albumhoes | Server zeven dagen; client 24 uur |
| Artiestenfoto | Server 12 uur, of 1 uur na externe storing; client 30 minuten |
| Definitieve miss | Server 45 minuten; client 30 minuten |
| Tijdelijke fout | Server 30 seconden; client 30 seconden |
| Cache-identiteit | Genormaliseerde artiest, titel en uitvoering; geen station-ID |
| Cacheversie | `rc045-radio-artwork-v5`, zodat fouten uit eerdere cache niet blijven bestaan |

De responseheaders `X-Radio-Artwork-MusicBrainz`,
`X-Radio-Artwork-CoverChecks` en `X-Radio-Artwork-Cache` maken API-gebruik
en cacheeffect zichtbaar in audits.

Bij te veel wachttijd geven nieuwe gelijktijdige aanvragen geen nieuwe
MusicBrainz-burst, maar een kort cachebaar `temporary_error` zonder afbeelding.
De interface wacht niet met het afspelen van de stream op artwork.

**Aandachtspunten:** bestandslocks veronderstellen een gedeelde, beschrijfbare
servermap (`sys_get_temp_dir()`) voor alle PHP-workers op één host. Multi-host
installaties vereisen een extern gedeeld cache-/ratelimitmechanisme. De URL
`/front-500` kan redirects geven waarvan het uiteindelijke bestand ontbreekt;
de client probeert in dat geval `/front-250` en anders de oorspronkelijke
achtergrond. De zoekstrategie kiest bewust voor het vermijden van misidentificatie
boven maximale dekking.

## Regressie en audit

- `php tests/radio-artwork-release-select.php`: opname-identiteit,
  uitvoering, foutieve artiest/compilatie, singles en soundtracks.
- `node --test tests/radio-artwork.test.cjs tests/radio-artwork-catalog.test.cjs`:
  browsergebeurtenissen, toggle, fallback, caching en 180 unieke testnummers
  uit negen categorieën.
- De bestaande technische, stream- en Playwright-layouttests blijven actief.
- `ARTWORK_AUDIT_LIVE=1 ARTWORK_AUDIT_LIMIT=10 node tests/radio-artwork-audit.mjs`
  gebruikt een **bestaand bereikbare PHP-omgeving** via
  `ARTWORK_AUDIT_BASE_URL` (standaard `http://127.0.0.1:8765`), met
  begrensde sequentiële requests. De audit schrijft `radio-artwork-audit.json`
  met duur, cachegebruik, aantallen MusicBrainz/CAA-aanroepen, beschikbaarheid,
  soort afbeelding en release-group-link. Maximaal 20 nummers per run.
- De technische GitHub Action start een lokale PHP-server en voert maximaal
  tien checks uit (de acht gemelde missers plus PINK- en Unicode-varianten).
  Het rapport wordt als GitHub Actions-artifact opgeslagen.

**Methodeverschil:** 180 gecureerde nummers zijn een reproduceerbare
**testcatalogus**, niet 180 automatisch geverifieerde albumhoezen. De
live-probe test alleen de opgegeven kleine selectie; de mogelijke juistheid
van elke cover moet afzonderlijk worden gecontroleerd. Voor een solide
volledige nauwkeurigheidsmeting moet een reviewer per teruggegeven
releasegroep vastleggen of de afbeelding hoort bij de juiste originele
album/single/uitvoering. Voeg handmatige verificatie toe en rapporteer
dan apart `beschikbaarheid` en `daadwerkelijk juiste hoes`.
Verspreid grotere audits over meerdere runs om de publieke MusicBrainz-API
niet te overbelasten.

## Bestaande baseline

De gebruiker constateerde in een eerdere (niet identieke) steekproef
10/15 beschikbare afbeeldingen. Vergelijk dit **niet rechtstreeks** met
nieuwe resultaten uit acht of tien gerichte regressies: de steekproef en
cachetoestand verschillen. Verbetering in werkelijke dekking én juistheid
kan pas worden vastgesteld met dezelfde vaste, handmatig nagekeken
testset in beide implementaties.
