<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

// Bildet (Country_Code, auf tools_airports.TIME normiertes Muster) auf eine echte
// IANA-Zeitzone ab. Siehe sql/2026-10-25-airport-timezone-map.sql fuer den Hintergrund:
// tools_countries.Timezone kennt nur eine Zeitzone pro Land, was fuer Laender mit
// mehreren Zeitzonen (USA, Russland, Kanada, Australien, ...) falsche Sonnenzeiten
// erzeugt.
#[ORM\Table(name: 'tools_airport_timezone_map')]
#[ORM\Entity]
#[ORM\Cache(usage: 'NONSTRICT_READ_WRITE')]
class ToolsAirportTimezoneMap
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;

    #[ORM\Column(type: 'string', length: 3)]
    private $Country_Code;

    #[ORM\Column(type: 'string', length: 40)]
    private $Time_Pattern;

    #[ORM\Column(type: 'string', length: 40)]
    private $Timezone;

    #[ORM\Column(type: 'string', length: 10)]
    private $Confidence;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCountryCode(): ?string
    {
        return $this->Country_Code;
    }

    public function setCountryCode(?string $Country_Code): self
    {
        $this->Country_Code = $Country_Code;

        return $this;
    }

    public function getTimePattern(): ?string
    {
        return $this->Time_Pattern;
    }

    public function setTimePattern(?string $Time_Pattern): self
    {
        $this->Time_Pattern = $Time_Pattern;

        return $this;
    }

    public function getTimezone(): ?string
    {
        return $this->Timezone;
    }

    public function setTimezone(?string $Timezone): self
    {
        $this->Timezone = $Timezone;

        return $this;
    }

    public function getConfidence(): ?string
    {
        return $this->Confidence;
    }

    public function setConfidence(?string $Confidence): self
    {
        $this->Confidence = $Confidence;

        return $this;
    }
}
