<?php
/**
 * OPTIONAL – nur für maximale Sicherheit.
 * Datei in „config.php“ umbenennen und den Pfad anpassen, wenn der Datenordner
 * AUSSERHALB des öffentlich erreichbaren Webordners liegen soll.
 *
 * Beispiel Webhosting:  Webordner  /html/flottivo   →  Daten  /html/flottivo-daten
 * Beispiel Rootserver:  Webordner  /var/www/flottivo →  Daten  /var/lib/flottivo
 *
 * Vorgehen bei bestehender Installation:
 *   1. Ordner „data“ komplett an den neuen Ort verschieben (nicht kopieren).
 *   2. Diese Datei als config.php mit dem neuen Pfad hochladen.
 *   3. plattform.php → System: „Datenordner liegt außerhalb des Webroots“ ist grün.
 */
return [
    'data_dir' => '/pfad/zu/flottivo-daten',

    // OPTIONAL – Schlüssel für die Verschlüsselung der Kundendaten getrennt vom Datenordner aufbewahren.
    // Wert = Ihr Notfall-Schlüssel (plattform.php → System → Verschlüsselung) OHNE „FLK1-“ und ohne
    // die Prüfsumme am Ende. Danach kann die Datei data/geheim.key gelöscht werden.
    // ACHTUNG: Ein falscher Wert macht alle verschlüsselten Daten unlesbar – die Seite meldet das dann.
    // 'schluessel' => 'HIER-DER-SCHLUESSEL==',
];
