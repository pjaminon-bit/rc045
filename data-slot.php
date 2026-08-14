<?php
// ============================================================
// RC045 slot om lees-wijzig-schrijf heen
// ------------------------------------------------------------
// Alle databestanden van de vereniging zijn JSON (of PHP met JSON erin) en
// worden gelezen, in het geheugen aangepast en daarna in hun geheel
// teruggeschreven. LOCK_EX op file_put_contents() beschermt alleen dat
// laatste stukje. Twee verzoeken die vlak na elkaar binnenkomen lezen dan
// allebei dezelfde versie en de tweede schrijft het werk van de eerste
// stilzwijgend weg.
//
// Dit bestand geeft één slot voor alle databestanden samen. Niet één per
// bestand: een enkele opslagactie raakt er vaak meerdere tegelijk (een lid
// dat aan een commissie wordt gekoppeld, een taak bij een vergadering), en
// met losse sloten kunnen twee verzoeken elkaar dan alsnog klemzetten.
// Kort vasthouden dus, en altijd weer vrijgeven.
//
// Gebruik:
//
//   $slot = dataSlotOpen();
//   ... lezen, aanpassen, schrijven ...
//   dataSlotDicht($slot);
//
// Neem het slot nooit twee keer in hetzelfde verzoek: de tweede aanvraag
// wacht dan op zichzelf. In beheer.php en leden.php zit het om het hele
// opslaan-blok heen, dus binnen zo'n blok is er niets meer te doen.
//
// Lukt het openen niet (map niet schrijfbaar, zeldzaam), dan geeft
// dataSlotOpen() null en gaat de aanroeper gewoon door zonder slot. Liever
// een klein risico op een botsing dan een opslag die helemaal niet werkt.
//
// Het lockbestand staat in data-backups/, want die map is server-only.
// ============================================================

function dataSlotPad() {
  return __DIR__ . '/data-backups/.data.lock';
}

// Geeft een vergrendeld bestandshandvat, of null als dat niet lukt.
function dataSlotOpen() {
  $pad = dataSlotPad();
  $map = dirname($pad);
  if (!is_dir($map) && !@mkdir($map, 0755, true)) return null;
  $handvat = @fopen($pad, 'c');
  if ($handvat === false) return null;
  if (!flock($handvat, LOCK_EX)) {
    fclose($handvat);
    return null;
  }
  return $handvat;
}

// Geeft het slot weer vrij. Een null-handvat (openen mislukt) mag gewoon.
function dataSlotDicht($handvat) {
  if (!$handvat) return;
  flock($handvat, LOCK_UN);
  fclose($handvat);
}
