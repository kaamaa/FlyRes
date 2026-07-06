<?php
/**
 * Einmal-Check VOR dem Aktivieren des Login-Throttlings.
 * Aufruf im Browser:  https://flyres.flugschule-worms.de/ratelimit-check.php
 *
 * Nur wenn hier "OK" steht, ist symfony/rate-limiter auf DIESEM Server vorhanden
 * und die neue security.yaml (mit login_throttling) kann gefahrlos hochgeladen
 * werden. Sagt es "FEHLT", zuerst vendor/symfony/rate-limiter/ auf den Server
 * bringen - security.yaml sonst NICHT hochladen (sonst 500-Totalausfall).
 *
 * Danach bitte wieder loeschen.
 */
require __DIR__ . '/../vendor/autoload.php';
header('Content-Type: text/plain; charset=utf-8');

echo class_exists('Symfony\\Component\\RateLimiter\\RateLimiterFactory')
    ? "RateLimiter: OK\n\nsymfony/rate-limiter ist vorhanden. Die neue security.yaml mit\nlogin_throttling kann jetzt hochgeladen werden. (Danach diese Datei loeschen.)\n"
    : "RateLimiter: FEHLT\n\nsymfony/rate-limiter ist auf diesem Server NICHT vorhanden.\nBitte zuerst den Ordner vendor/symfony/rate-limiter/ hochladen.\nDie neue security.yaml (mit login_throttling) noch NICHT hochladen!\n";
