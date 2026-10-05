<?php
/**
 * Automatische Aufgaben – einmal täglich ausführen (z. B. 3:00 Uhr):
 *   Rootserver (crontab -e):  0 3 * * *  php /pfad/zur/mietverwaltung/cron.php
 *   Ohne Shell (Webcron):     https://IHRE-PLATTFORM/cron.php?key=SCHLUESSEL  (Schlüssel: plattform.php → System)
 *
 * Aufgaben: Datensicherung aller Vermietungen + Plattform (mit Aufbewahrung),
 * Erinnerungs-Mails zum Ende der Testphase, Aufräumen abgelaufener Links.
 */
define('PLATFORM_AREA', true);
define('NO_SESSION', true);
require __DIR__ . '/app/bootstrap.php';

$cli = PHP_SAPI === 'cli';
if (!$cli) {
    header('Content-Type: text/plain; charset=utf-8');
    $key = (string) psetting('cron_key', '');
    if ($key === '' || !hash_equals($key, (string) ($_GET['key'] ?? ''))) { http_response_code(403); exit("Zugriff verweigert\n"); }
}
// Nur auf dem Server: Liste aller Adressen ausgeben (für SSL-Zertifikate, siehe werkzeuge/zertifikate.sh)
if ($cli && ($argv[1] ?? '') === 'adressen') {
    $base = trim((string) psetting('basis_domain', ''));
    $out = [];
    if ($base !== '') { $out[] = $base; $out[] = 'www.' . $base; }
    if ($ah = trim((string) psetting('admin_host', ''))) $out[] = $ah;
    foreach (pall("SELECT slug, domain FROM tenants") as $t) {
        if ($base !== '') $out[] = $t['slug'] . '.' . $base;
        if (!empty($t['domain'])) $out[] = $t['domain'];
    }
    echo implode("\n", array_values(array_unique(array_filter($out)))) . "\n";
    exit;
}
@set_time_limit(1800);
$lock = fopen(DATA_DIR . '/cron.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit("Läuft bereits.\n");

$log = [];
$say = function (string $m) use (&$log) { $log[] = date('H:i:s') . ' ' . $m; echo $m . "\n"; };

/* 0. Gelöste Support-Tickets nach 14 Tagen ohne Rückmeldung automatisch schließen */
$nTk = pq("UPDATE tickets SET status = 'geschlossen', closed_at = COALESCE(closed_at, ?) WHERE status = 'geloest' AND COALESCE(updated_at, created_at) < ?", [date('Y-m-d H:i:s'), date('Y-m-d H:i:s', strtotime('-14 days'))])->rowCount();
if ($nTk) $say('Support: ' . $nTk . ' gelöste Tickets geschlossen');

/* 0b. Verschlüsselung: Kundendaten und Dateien aller Vermietungen (nachholen, falls nötig) */
try {
    platform_secrets_migrate();
    $nf = 0; $nd = 0;
    foreach (pall('SELECT slug FROM tenants') as $t) {
        $td = tenant_dir($t['slug']);
        if (!is_file($td . '/mietverwaltung.sqlite')) continue;
        $nd += enc_migrate_db(tenant_db($t['slug']), $t['slug']);
        [$x] = enc_migrate_files($td . '/uploads', data_key($t['slug']), 300);
        $nf += $x;
    }
    [$x] = enc_migrate_files(support_dir(), data_key('#support'), 120);
    $nf += $x;
    if ($nd || $nf) $say('Verschlüsselung: ' . $nd . ' Datensätze und ' . $nf . ' Dateien verschlüsselt');
    // Temporäre entschlüsselte Dateien (PDF-Erzeugung) aufräumen
    foreach (glob(DATA_DIR . '/tmp/*') ?: [] as $f) if (is_file($f) && filemtime($f) < time() - 3600) @unlink($f);
} catch (Throwable $e) {
    $say('FEHLER Verschlüsselung: ' . $e->getMessage());
}

/* 1. Datensicherung */
try {
    $dir = DATA_DIR . '/backups/' . date('Y-m-d');
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $snap = function (string $src, string $dst) {
        @unlink($dst);
        $pdo = new PDO('sqlite:' . $src);
        $pdo->exec('PRAGMA busy_timeout = 10000');
        $pdo->exec("VACUUM INTO " . $pdo->quote($dst)); // konsistente Kopie im laufenden Betrieb
    };
    $snap(PLATFORM_DB, $dir . '/plattform.sqlite');
    // Schlüssel NICHT neben die Daten legen, sobald der Notfall-Schlüssel sicher aufbewahrt wurde (System → Verschlüsselung).
    // Vorher zur Sicherheit noch mitsichern, damit niemand seine Daten verliert.
    if (psetting('schluessel_gesichert', '') === '') {
        if (secret_key_source() === 'datei' && is_file(DATA_DIR . '/geheim.key')) @copy(DATA_DIR . '/geheim.key', $dir . '/geheim.key');
    } else {
        foreach (glob(DATA_DIR . '/backups/*/geheim.key') ?: [] as $old) @unlink($old);
    }
    if (is_dir(support_dir())) {
        $zip = new ZipArchive();
        $zip->open($dir . '/support-screenshots.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(support_dir(), FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) if ($f->isFile()) $zip->addFile($f->getPathname(), substr($f->getPathname(), strlen(support_dir()) + 1));
        $zip->close();
    }
    $n = 0;
    foreach (pall('SELECT slug FROM tenants') as $t) {
        $td = tenant_dir($t['slug']);
        if (!is_file($td . '/mietverwaltung.sqlite')) continue;
        $tmp = $dir . '/' . $t['slug'] . '.sqlite';
        $snap($td . '/mietverwaltung.sqlite', $tmp);
        $zip = new ZipArchive();
        $zip->open($dir . '/' . $t['slug'] . '.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFile($tmp, 'mietverwaltung.sqlite');
        foreach (['uploads', 'branding'] as $sub) {
            if (!is_dir($td . '/' . $sub)) continue;
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($td . '/' . $sub, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) if ($f->isFile()) $zip->addFile($f->getPathname(), $sub . '/' . substr($f->getPathname(), strlen($td . '/' . $sub) + 1));
        }
        $zip->close();
        unlink($tmp);
        $n++;
    }
    // Alte Sicherungen löschen
    $keep = max(3, (int) psetting('backup_tage', 14));
    foreach (glob(DATA_DIR . '/backups/*', GLOB_ONLYDIR) ?: [] as $old) {
        if (preg_match('/(\d{4}-\d{2}-\d{2})$/', $old, $m) && $m[1] < date('Y-m-d', strtotime("-$keep days"))) {
            foreach (glob($old . '/*') ?: [] as $f) @unlink($f);
            @rmdir($old);
        }
    }
    set_psetting('backup_last', date('Y-m-d H:i:s') . ' · ' . $n . ' Vermietungen');
    $say("Datensicherung: $n Vermietungen + Plattform gesichert, Aufbewahrung $keep Tage.");
} catch (Throwable $e) {
    $say('FEHLER Datensicherung: ' . $e->getMessage());
    error_log('Cron-Backup: ' . $e->getMessage());
}

/* 2. Erinnerungen zur Testphase */
if (mail_configured()) {
    $in3 = date('Y-m-d', strtotime('+3 days'));
    foreach (pall("SELECT * FROM tenants WHERE status = 'test' AND test_bis IS NOT NULL") as $t) {
        $done = array_filter(explode(',', (string) $t['erinnert']));
        $abo = tenant_url($t) . url('abo');
        $key = null;
        if ($t['test_bis'] <= $in3 && $t['test_bis'] >= date('Y-m-d') && !in_array('test3', $done, true)) {
            $key = 'test3';
            $err = tenant_notify_admin($t, 'Ihre Testphase endet bald', '<p>Ihre Testphase für <b>' . e($t['name']) . '</b> endet am <b>' . e(d($t['test_bis'])) . '</b>. Damit Sie ohne Unterbrechung weiterarbeiten können, wählen Sie jetzt einen Tarif. Alle Daten bleiben erhalten.</p>' . (stripe_configured() ? mail_button($abo, 'Tarif wählen') : ''));
        } elseif ($t['test_bis'] < date('Y-m-d') && !in_array('testende', $done, true)) {
            $key = 'testende';
            $err = tenant_notify_admin($t, 'Ihre Testphase ist abgelaufen', '<p>die Testphase für <b>' . e($t['name']) . '</b> ist abgelaufen. Ihre Daten sind weiterhin gespeichert. Sobald Sie einen Tarif wählen, geht es genau dort weiter, wo Sie aufgehört haben.</p>' . (stripe_configured() ? mail_button($abo, 'Tarif wählen und weiterarbeiten') : ''));
        }
        if ($key) {
            if (empty($err)) pq('UPDATE tenants SET erinnert = ? WHERE id = ?', [implode(',', array_merge($done, [$key])), $t['id']]);
            $say('Erinnerung „' . $key . '“ an ' . $t['name'] . ($err ? ' – FEHLER: ' . $err : ''));
        }
    }
}

/* 3. Aufräumen */
pq('DELETE FROM registrations WHERE expires < ?', [time()]);
pq('DELETE FROM admin_tokens WHERE expires < ?', [time()]);
pq('DELETE FROM sso_tokens WHERE expires < ?', [time()]);
pq('DELETE FROM rate_limits WHERE at < ?', [time() - 86400]);
pq("DELETE FROM mail_log WHERE created_at < datetime('now','-180 days')");
pq("DELETE FROM stripe_events WHERE created_at < datetime('now','-90 days')");

set_psetting('cron_last', date('Y-m-d H:i:s'));
$say('Fertig.');
flock($lock, LOCK_UN);
