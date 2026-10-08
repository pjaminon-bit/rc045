# Nieuwe Nederlandse radiozenders — 8 oktober 2026

## Behoud van bestaande functionaliteit

De 25 bestaande zenders zijn intact. Het bestaande TRACK UI-blok en volledige
TRACK SPLIT-naar-KEYBOARD gedeelte, inclusief provider-routing, caches,
fallbacks, pre-roll guards, programmainformatie, trackweergave en polling,
zijn **byte-voor-byte ongewijzigd**.

Er zijn 19 landelijke/thematische Nederlandse zenders en 2 regionale zenders
toegevoegd. Totaal 46: NL 35, BE 9 en Lokaal NL 2.

Lokaal NL is een apart tabblad met L1 Radio en Omroep Brabant.
Tabkeuze wordt bewaard onder `radio-country-tab`; de bestaande zoekfunctie
doorzoekt alle tabs en toont bij een zoekopdracht tijdelijk alle matches.

## Trackinfo

Voor zenders bij Triton en NPO zijn de **bestaande** provider-types toegewezen:
`triton-track` en `npo-mini`. Daar wordt alleen een track getoond wanneer
de bestaande parser actuele en geldige gegevens teruggeeft. Geen metadata
van bestaande zenders wordt doorgestuurd naar een ander station.

Geen nieuwe trackinfo-logica of parsers toegevoegd. Voor Qmusic themakanalen,
RADIONL, NPO BLEND, Sterren NL en de regionale omroepen is geen bewezen,
browser-toegankelijke en zenderspecifieke feed gekoppeld. De standaardspeler
verbergt trackinfo in dat geval; dit is geen regressie van bestaande kanalen.

## Aanbieders en belangrijke verificatiepunten

- NPO Klassiek: officiële Icecast-link.
- BLEND en Sterren NL: NPO Icecast-streams; bestaande NPO-trackprovider
  gekoppeld aan Klassiek, alleen waar er een herleidbare channel-id bestaat.
- Talpa-themakanalen, Noordzee, Sky, Radio 10, KINK, Sublime en Grand Prix:
  StreamTheWorld HTTP(S)-MP3; bestaande Triton-provider met eigen mount.
- Qmusic Non-Stop: officiële Qmusic MP3-URL.
- Qmusic Easy en Energy: beide kanalen bestaan sinds 1 oktober 2026.
  Hun kandidaat-streams met het kanaal-id `qnl_easy` / `qnl_energy`
  moeten nog live in de browser worden bevestigd; dit is **niet bevestigd**
  door de publieke streamdocumentatie van Qmusic.
- **KINK Classics**: KINK's officiële website linkt hiervoor naar
  `KINK_DNA.pls`, net als KINK 80's. Dit zijn daarom op dit moment
  twee afzonderlijke selecties met dezelfde bron. Niet doen alsof een
  afzonderlijke stream is bevestigd.
- L1 Radio: regionaal Icecast via cloudfront.
- Omroep Brabant: regionaal Icecast via cloudfront.
- RADIONL: https://stream.radionl.fm/radionl.

## Handmatige regressie na preview (voor merge)

1. iPhone Safari & desktop: afspelen van ieder nieuw kanaal; vooral Q Easy/Energy.
2. Geen dubbele streams bij veelvuldig zenderwisselen / miniplayer / Cast.
3. NL, BE, Lokaal NL: tabs bedienen; favorieten sorteren; inklappen,
   geselecteerde tab bewaren en zoekactie over alle tabbladen.
4. Miniplayer scrollen en spelen.
5. Bestaande 25 zenders: trackinfo en programmainfo ongewijzigd.
6. Nieuwe zenders met triton/npo provider: artiest/titel alleen correct
   en actueel wanneer de betreffende provider data levert.
7. Logo's controleren: thema-radio's hergebruiken het moedermerklogo,
   andere kanalen gebruiken favicon-fallbacks; sommige zijn generiek.
