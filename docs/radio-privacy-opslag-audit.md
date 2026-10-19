# Radio-app — opslag- en externe-dienstencheck (19 oktober 2026)

## Reikwijdte
Statische inspectie van `radio.html`, `radio-sw.js`, `radio-artwork.php`,
`radio-artwork-photo-select.php`, `be-nowplaying.php` en `vrt-nowplaying.php`
in `pjaminon-bit/rc045`. Dit is geen live browser- of netwerk/cookieaudit
en geen volledige AVG-beoordeling van de gehele RC045-website, hosting of serverlogs.

## Eigen browseropslag

| Techniek | Gegevens | Doel | Beoordeling |
| --- | --- | --- | --- |
| `localStorage` | Laatste zender (`radio-station-id`) | Station hervatten | Functionele voorkeur |
| `localStorage` | Kleurmodus, trackinfo/programma/albumart/artist-art/animaties/miniplayer/themazenders | Persoonlijke weergave | Functionele voorkeur |
| `localStorage` | Volume, mute, sleeptimer | Bediening onthouden | Functionele voorkeur |
| `localStorage` | Favoriete zenders, favoriete regio's en actieve regiotab | Navigatie/favorieten | Functionele voorkeur |
| `localStorage` | Eventuele cache van Wikipedia-/informatiegegevens (indien actief) | Minder herhaalde verzoeken | Functionele clientcache |
| Serviceworker + Cache Storage | `radio.html`, manifest en PWA-iconen | Offline interface en snelle herstart | Functionele PWA-opslag |

De eigen `radio.html` gebruikt bij deze inspectie geen `document.cookie`,
Cookie Store API, `navigator.sendBeacon`, Google Analytics, Matomo,
Plausible of zichtbare advertentiepixels. De radiopagina heeft geen
ingebouwde advertentie- of analyticsmodule aangetroffen. *Dit zegt niets
over cookies of scripts van derden of andere pagina's op rc045.nl.*

Functionele opslag kan normaal zonder voorafgaande cookietoestemming,
maar informeer bezoekers duidelijk. Zie
[Autoriteit Persoonsgegevens — Cookies](https://autoriteitpersoonsgegevens.nl/nl/onderwerpen/internet-telefoon-tv-en-post/cookies).

## Externe diensten

| Categorie | Vastgesteld in bron | Privacy-aandachtspunt |
| --- | --- | --- |
| Radio/audio | Directe HTTPS-streams van NPO, VRT, DPG, Triton/StreamTheWorld, SLAM!, KINK en andere aanbieders | Bij afspelen kunnen radio-/streamproviders IP-adres, browserinformatie en luisterverbinding zien. |
| Track- en programmainformatie | Externe zender-API's en de eigen endpoints `be-nowplaying.php` / `vrt-nowplaying.php` | Directe API-verzoeken kunnen het bezoekadres zien; server-side requests kunnen anders lopen. |
| Artwork | Eigen endpoint `radio-artwork.php` voor MusicBrainz/Cover Art Archive/Wikimedia, plus direct getoonde externe afbeeldingen | Afbeeldingproviders kunnen bij rechtstreekse afbeeldingsverzoeken netwerkgegevens ontvangen; de eigen PHP heeft gedeelde **track-gebaseerde** servercache, geen individuele bezoeker-ID vastgesteld. |
| Chromecast | `https://www.gstatic.com/cv/js/sender/v1/cast_sender.js?loadCastFramework=1` wordt **bij paginaopenen** als extern script geladen | Google ontvangt mogelijk IP-adres/headers voor dit verzoek, ook zonder Cast-gebruik. Cookies/opslag door SDK zijn niet uit alleen de broncode uit te sluiten. |
| Muziekdiensten | Links naar Spotify, Apple Music, Deezer, Tidal; icoontjes lokaal | De links worden pas door de gebruiker geopend. |
| Hosting | De website zelf | Hosting/HTTP-logs kunnen IP-adressen verwerken. Bewaartermijnen en verwerkers zijn niet in deze code te beoordelen. |

De PWA-serviceworker slaat **geen** radiostreams en live-trackresponses
bewust op. Eigen settings worden niet via een aparte gebruikersprofiel-API
naar de server verstuurd.

## Advies en open punten

1. **Geen cookiebanner uitsluitend voor de functionele instellingen en PWA-cache.**
   De footer geeft nu een permanente ingang tot uitleg over lokale opslag.
2. **Voer op Chrome en Safari een runtime-controle uit van cookies,
   `localStorage`, IndexedDB en netwerkverzoeken**, met name bij het laden
   van de Google Cast SDK en het starten van streams. Alleen dan kan worden
   vastgesteld wat de derde partijen werkelijk zetten.
3. Als niet-functionele cookies of vergelijkbare tracking door derden blijken
   plaats te vinden, beoordeel dan toestemming vóór laden en de mogelijkheid
   om de betreffende derde-partijfunctionaliteit uit te stellen. Stel dat niet
   op basis van aannames.
4. Voor een volledige AVG-privacyverklaring moeten nog de verantwoordelijke
   partij/contactgegevens, eventuele bewaartermijnen van serverlogs,
   rechtsgronden en relevante verwerkers/ontvangers worden vastgelegd.
5. Houd de uitleg bij bij nieuwe externe SDK's, analytics, tracking of andere
   opslagtoepassingen.

**Uitgevoerde wijziging:** een toegankelijke, ingebouwde
`Privacy en opslag`-dialoog met een link in de footer van `radio.html`.
Geen invloed op audio, trackinfo, artwork of bestaande opslagwaarden.
