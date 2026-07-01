<?php

namespace App\Security\Hasher;

use Symfony\Component\PasswordHasher\Exception\InvalidPasswordException;
use Symfony\Component\PasswordHasher\Hasher\CheckPasswordLengthTrait;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;

/**
 * Geschichteter Passwort-Hasher: bcrypt ueber dem MD5 des Klartexts.
 *
 * Hintergrund: Die Alt-Hashes waren ungesalzenes, einfaches MD5 (GPU-knackbar).
 * Dieser Hasher umschliesst das MD5 mit bcrypt (gesalzen, langsam) und behebt
 * damit die "unsalted MD5 at rest"-Schwachstelle. Der Clou: bestehende MD5-Hashes
 * koennen OHNE Klartext migriert werden (einmal mit bcrypt umhuellen), und der
 * Joomla-Pfad (loginwithcredentials liefert bereits MD5) bleibt kompatibel –
 * siehe verifyMd5().
 *
 * verify() akzeptiert weiter rohes Legacy-MD5, sodass nicht migrierte Konten
 * sich weiter anmelden koennen; needsRehash() loest dann den Upgrade auf bcrypt
 * beim naechsten erfolgreichen Login aus (PasswordUpgraderInterface im Repo).
 */
class CustomPasswordHasher implements PasswordHasherInterface
{
    use CheckPasswordLengthTrait;

    private const COST = 12;

    public function hash(string $plainPassword): string
    {
        if ($this->isPasswordTooLong($plainPassword)) {
            throw new InvalidPasswordException();
        }

        return password_hash(md5($plainPassword), PASSWORD_BCRYPT, ['cost' => self::COST]);
    }

    public function verify(string $hashedPassword, string $plainPassword): bool
    {
        if ('' === $plainPassword || $this->isPasswordTooLong($plainPassword)) {
            return false;
        }

        return self::verifyMd5($hashedPassword, md5($plainPassword));
    }

    public function needsRehash(string $hashedPassword): bool
    {
        // Alt-Hash (rohes MD5) -> migrieren; bcrypt -> nur bei Cost-Aenderung.
        if (!self::isBcrypt($hashedPassword)) {
            return true;
        }

        return password_needs_rehash($hashedPassword, PASSWORD_BCRYPT, ['cost' => self::COST]);
    }

    /**
     * Prueft einen bereits MD5-gehashten Wert gegen den gespeicherten Hash –
     * unterstuetzt sowohl das neue bcrypt(MD5) als auch rohes Legacy-MD5.
     * Wird vom Joomla-Pfad (LoginController::loginwithcredentials) genutzt, der
     * das Passwort bereits als MD5 liefert (kein Klartext verfuegbar).
     */
    public static function verifyMd5(string $hashedPassword, string $md5Password): bool
    {
        if (self::isBcrypt($hashedPassword)) {
            return password_verify($md5Password, $hashedPassword);
        }

        // Legacy: roher, ungesalzener MD5-Vergleich (zeitkonstant)
        return $hashedPassword !== '' && hash_equals($hashedPassword, $md5Password);
    }

    private static function isBcrypt(string $hash): bool
    {
        return str_starts_with($hash, '$2y$')
            || str_starts_with($hash, '$2a$')
            || str_starts_with($hash, '$2b$');
    }
}
