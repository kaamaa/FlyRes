<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Table(name="tools_countries")
 * @ORM\Entity
 * @ORM\Cache(usage="NONSTRICT_READ_WRITE")
 */
class ToolsCountry
{
    /**
     * @ORM\Id()
     * @ORM\GeneratedValue()
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\Column(type="string", length=20, nullable=true)
     */
    private $Country_Code;

    /**
     * @ORM\Column(type="string", length=200, nullable=true)
     */
    private $Country_Name;

    /**
     * @ORM\Column(type="string", length=20, nullable=true)
     */
    private $ICAO_Region;

    /**
     * @ORM\Column(type="string", length=100, nullable=true)
     */
    private $Usage;

    /**
     * @ORM\Column(type="string", length=40, nullable=true)
     */
    private $Timezone;

    // Getter und Setter...

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

    public function getCountryName(): ?string
    {
        return $this->Country_Name;
    }

    public function setCountryName(?string $Country_Name): self
    {
        $this->Country_Name = $Country_Name;

        return $this;
    }

    public function getICAORegion(): ?string
    {
        return $this->ICAO_Region;
    }

    public function setICAORegion(?string $ICAO_Region): self
    {
        $this->ICAO_Region = $ICAO_Region;

        return $this;
    }

    public function getUsage(): ?string
    {
        return $this->Usage;
    }

    public function setUsage(?string $Usage): self
    {
        $this->Usage = $Usage;

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
}
