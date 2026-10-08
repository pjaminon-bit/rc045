# Provinciale radio & KINK-cleanup — 8 oktober 2026

## Regiocompleet
Nederland heeft 13 regionale publieke omroepen in 12 provincies,
waaronder twee in Zuid-Holland. Alle 13 in Lokaal NL:
RTV Noord (Groningen), Omrop Fryslân (Friesland), RTV Drenthe (Drenthe),
Radio Oost (Overijssel), Omroep Flevoland (Flevoland), Radio Gelderland
(Gelderland), Radio M Utrecht (Utrecht), NH Radio (Noord-Holland),
Radio West en Radio Rijnmond (beide Zuid-Holland), Omroep Zeeland
(Zeeland), Omroep Brabant (Noord-Brabant) en L1 Radio (Limburg).

L1 en Brabant waren al aanwezig; 11 nieuwe streamadressen toegevoegd.
Elke kaart toont provincie, ook doorzoekbaar via het bestaande zoekveld.
Alle eerdere favorieten, land-/tabvoorkeuren en de miniplayer blijven bestaan.

## Correctie KINK
KINK Classics werd op 1 april 2023 officieel vervangen door KINK 80's.
De oude 'Classics' en '80s' in radio.html hadden identieke KINK_DNA-streams
en gaven een valse keuze. Verouderde Classics-kaart verwijderd.
KINK 80's, KINK 90's en KINK Distortion blijven ieder hun eigen
bekende mount gebruiken. Opgeslagen Classics-favorieten en
laatst gekozen Classics worden gemigreerd naar KINK 80's.

Bron officiële KINK: https://kink.nl/nieuws/vanaf-zaterdag-kink80s
Bron regionale publieke omroepen: https://www.stichtingrpo.nl/omroepen/

## Trackinfo-regressiegrens
TRACK UI en TRACK SPLIT → KEYBOARD code zijn 100% gelijk aan main:
geen wijziging van providers, polling, trackpresentatie, metadatafetch,
fallback, caching of programmainformatie.
Nieuwe regionale omroepen krijgen géén onbekende/onjuiste metadatafeed.
De bestaande trackinfo blijft ongewijzigd; KINK 80's behoudt dezelfde
bestaande Triton-feed en de bestaande 90s/Distortion ook.

## Uit te voeren controle
- GitHub Actions voert bestaande technische tests en een directe
  bereikbaarheidstest van alle nieuwe regionale MP3-audio-URL's uit.
- Binnen Safari iOS, AirPlay, Chromecast en andere echte apparaten is
  handmatige playbacktest nodig; HTTP-test is geen playbacktest.
- Favoriet Classics -> 80s migratie bij oud browserprofiel; landtabs
  en zoeken op provincienaam, Lokaal NL alle 13 kanalen tonen.
