<?php
namespace App\Service;

/**
 * Token-Generierung und -Hashing fuer die API-Bearer-Authentifizierung.
 *
 * Klartext-Token-Format: "flyres_" + base64url(32 random bytes), 50 Zeichen.
 * In der DB wird ausschliesslich der SHA-256-Hex-Hash gespeichert.
 */
class ApiTokenService
{
    private const PREFIX = 'flyres_';
    private const RANDOM_BYTES = 32;

    public function generate(): string
    {
        $raw = random_bytes(self::RANDOM_BYTES);
        // base64url ohne Padding: +/= durch -_ ersetzen, = strippen
        $b64 = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
        return self::PREFIX . $b64;
    }

    public function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
