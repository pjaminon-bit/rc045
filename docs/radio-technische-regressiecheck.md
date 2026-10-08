# Radio — regressie en iOS Safari testplan

Deze PR wijzigt **geen** trackinfo-provider, metadata-API, programma-/track-parser,
polling, cache, fallback of pre-rollregels. De miniplayer leest uitsluitend de
reeds gerenderde informatie uit de grote player.

## Automatisch gecontroleerd
- De track-UI en het volledige TRACK SPLIT t/m KEYBOARD-blok zijn byte-identiek
  aan main (alle track- en programmafuncties, inclusief timers).
- Er bestaat maar één HTML-audio-element.
- Inline JavaScript is syntactisch geldig.
- Zoekfunctie, PWA-registratie en miniplayer zijn aanwezig.

## Handmatige regressiecheck (nog uitvoeren)
1. Play/Pauze bij normale verbinding en snel dubbelklikken.
2. Tijdens 'Verbinden…' pauzeren en meteen een ander station kiezen.
3. Stream laten falen: maximaal vijf wachttijden, geen dubbele audiosporen.
4. Offline/online herstellen; geen herstel als de gebruiker bewust pauzeert.
5. Miniplayer Play/Pauze/volgende/vorige en Cast/AirPlay onafhankelijk controleren.
6. Zoekfunctie, favorieten, landen open/dicht, mute, volume en thema; na herladen terug.
7. Zender wisselen bij zichtbare track- en programmainformatie (alle stations).
8. iOS Safari: echte gebruikersklik bij eerste Play, schermvergrendeling,
   terugkeren uit appwisselaar, Bluetooth en AirPlay, scrollen, PWA op beginscherm.
9. Browserhistorie terug (BFCache): juiste play-status, geen ongewenste autoplay.
10. Toetsenbord: Space op Play-knop veroorzaakt slechts één klik,
    pijltjes op volumeslider wisselen niet van station.
11. Check afmetingen en scrollgedrag op 320/375/430/768/1440 px.
12. Diagnosepaneel: fouten zichtbaar, geen URL's/trackgegevens, kopiëren veilig.

Echte Safari-, AirPlay-, Bluetooth- en provider-failure-tests zijn **niet**
automatisch uitgevoerd en vereisen handmatige controle.
