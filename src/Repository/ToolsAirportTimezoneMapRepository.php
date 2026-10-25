<?php
// src/Repository/ToolsAirportTimezoneMapRepository.php

namespace App\Repository;

use App\Entity\ToolsAirportTimezoneMap;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ToolsAirportTimezoneMapRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ToolsAirportTimezoneMap::class);
    }

    /**
     * Liefert die korrekte IANA-Zeitzone fuer einen Flugplatz anhand seines
     * Laender-Codes und seines rohen TIME-Feldes (z.B. "UTC-6(-5DT)"), oder null,
     * wenn kein passender Eintrag existiert (z.B. TIME ist leer, oder das Muster
     * ist noch nicht erfasst). Aufrufer fallen in dem Fall auf
     * ToolsCountryRepository::GetTimeZone() zurueck.
     *
     * @return string|null IANA-Zeitzonenname, z.B. "America/Chicago"
     */
    public static function GetTimezoneForAirport($em, ?string $countryCode, ?string $rawTime): ?string
    {
        if (empty($countryCode) || empty($rawTime)) {
            return null;
        }

        // Normalisieren: Leerzeichen entfernen, damit z.B. "UTC-8 (-7DT)" (bekannter
        // Daten-Tippfehler) genauso trifft wie "UTC-8(-7DT)".
        $pattern = preg_replace('/\s+/', '', $rawTime);
        if ($pattern === '') {
            return null;
        }

        $entry = $em->getRepository(ToolsAirportTimezoneMap::class)->findOneBy([
            'Country_Code' => $countryCode,
            'Time_Pattern' => $pattern,
        ]);

        return $entry?->getTimezone();
    }
}
