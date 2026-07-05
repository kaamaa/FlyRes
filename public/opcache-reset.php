<?php
/**
 * Einmal-Helfer: leert den PHP-OPcache und loescht sich danach selbst.
 * Aufruf im Browser:  https://flyres.flugschule-worms.de/opcache-reset.php
 * Danach ist die Datei weg (kein offener Endpunkt).
 */
header('Content-Type: text/plain; charset=utf-8');

$status = function_exists('opcache_get_status') ? @opcache_get_status(false) : null;
$cachedBefore = is_array($status) && !empty($status['opcache_enabled']);

$ok = function_exists('opcache_reset') ? opcache_reset() : false;

// Wenn moeglich, gezielt auch die Klassendatei aus dem Cache werfen.
if (function_exists('opcache_invalidate')) {
    @opcache_invalidate(__DIR__ . '/../src/Entities/Bookings.php', true);
}

$deleted = @unlink(__FILE__);

echo "OPcache aktiv gewesen: " . ($cachedBefore ? 'ja' : 'nein/unbekannt') . "\n";
echo "opcache_reset(): " . ($ok ? 'ok' : 'nicht verfuegbar/fehlgeschlagen') . "\n";
echo "Skript geloescht: " . ($deleted ? 'ja' : 'NEIN – bitte manuell loeschen!') . "\n";
echo "\nFertig. Jetzt eine Buchung erneut testen.\n";
