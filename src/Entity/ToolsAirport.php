<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass="App\Repository\AirportRepository")
 * @ORM\Table(name="tools_airports")
 */
class ToolsAirport
{
    /**
     * @ORM\Id()
     * @ORM\GeneratedValue()
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\Column(type="string", length=2, nullable=true)
     */
    private $Type;

    /**
     * @ORM\Column(type="string", length=50, nullable=true)
     */
    private $Airport_Code;

    /**
     * @ORM\Column(type="string", length=10, nullable=true)
     */
    private $ICAO;

    /**
     * @ORM\Column(type="string", length=100, nullable=true)
     */
    private $Airport;

    /**
     * @ORM\Column(type="string", length=100, nullable=true)
     */
    private $Airport_ICAO;

    /**
     * @ORM\Column(type="string", length=2, nullable=true)
     */
    private $Continent;

    /**
     * @ORM\Column(type="string", length=20, nullable=true)
     */
    private $sLat;

    /**
     * @ORM\Column(type="string", length=20, nullable=true)
     */
    private $sLong;

    /**
     * @ORM\Column(type="integer", nullable=true)
     */
    private $ELEV;

    /**
     * @ORM\Column(type="integer", nullable=true)
     */
    private $Freq;

    /**
     * @ORM\Column(type="string", length=10, nullable=true)
     */
    private $FL_High_Ident;

    /**
     * @ORM\Column(type="string", length=10, nullable=true)
     */
    private $FL_Low_Ident;

    /**
     * @ORM\Column(type="integer", nullable=true)
     */
    private $FL_Length;

    /**
     * @ORM\Column(type="integer", nullable=true)
     */
    private $FL_Width;

    /**
     * @ORM\Column(type="string", length=20, nullable=true)
     */
    private $FL_Surface;

    /**
     * @ORM\Column(type="string", length=3, nullable=true)
     */
    private $Country;

    /**
     * @ORM\Column(type="string", length=100, nullable=true)
     */
    private $ICAO_Airport;

    /**
     * @ORM\Column(type="string", length=10, nullable=true)
     */
    private $ICAO1;

    // Getter und Setter...

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): ?string
    {
        return $this->Type;
    }

    public function setType(?string $Type): self
    {
        $this->Type = $Type;

        return $this;
    }

    public function getAirportCode(): ?string
    {
        return $this->Airport_Code;
    }

    public function setAirportCode(?string $Airport_Code): self
    {
        $this->Airport_Code = $Airport_Code;

        return $this;
    }

    public function getICAO(): ?string
    {
        return $this->ICAO;
    }

    public function setICAO(?string $ICAO): self
    {
        $this->ICAO = $ICAO;

        return $this;
    }

    public function getAirport(): ?string
    {
        return $this->Airport;
    }

    public function setAirport(?string $Airport): self
    {
        $this->Airport = $Airport;

        return $this;
    }

    public function getAirportICAO(): ?string
    {
        return $this->Airport_ICAO;
    }

    public function setAirportICAO(?string $Airport_ICAO): self
    {
        $this->Airport_ICAO = $Airport_ICAO;

        return $this;
    }

    public function getContinent(): ?string
    {
        return $this->Continent;
    }

    public function setContinent(?string $Continent): self
    {
        $this->Continent = $Continent;

        return $this;
    }

    public function getsLat(): ?string
    {
        return $this->sLat;
    }

    public function setsLat(?string $sLat): self
    {
        $this->sLat = $sLat;

        return $this;
    }

    public function getsLong(): ?string
    {
        return $this->sLong;
    }

    public function setsLong(?string $sLong): self
    {
        $this->sLong = $sLong;

        return $this;
    }

    public function getELEV(): ?int
    {
        return $this->ELEV;
    }

    public function setELEV(?int $ELEV): self
    {
        $this->ELEV = $ELEV;

        return $this;
    }

    public function getFreq(): ?int
    {
        return $this->Freq;
    }

    public function setFreq(?int $Freq): self
    {
        $this->Freq = $Freq;

        return $this;
    }

    public function getFLHighIdent(): ?string
    {
        return $this->FL_High_Ident;
    }

    public function setFLHighIdent(?string $FL_High_Ident): self
    {
        $this->FL_High_Ident = $FL_High_Ident;

        return $this;
    }

    public function getFLLowIdent(): ?string
    {
        return $this->FL_Low_Ident;
    }

    public function setFLLowIdent(?string $FL_Low_Ident): self
    {
        $this->FL_Low_Ident = $FL_Low_Ident;

        return $this;
    }

    public function getFLLength(): ?int
    {
        return $this->FL_Length;
    }

    public function setFLLength(?int $FL_Length): self
    {
        $this->FL_Length = $FL_Length;

        return $this;
    }

    public function getFLWidth(): ?int
    {
        return $this->FL_Width;
    }

    public function setFLWidth(?int $FL_Width): self
    {
        $this->FL_Width = $FL_Width;

        return $this;
    }

    public function getFLSurface(): ?string
    {
        return $this->FL_Surface;
    }

    public function setFLSurface(?string $FL_Surface): self
    {
        $this->FL_Surface = $FL_Surface;

        return $this;
    }

    public function getCountry(): ?string
    {
        return $this->Country;
    }

    public function setCountry(?string $Country): self
    {
        $this->Country = $Country;

        return $this;
    }

    public function getICAOAirport(): ?string
    {
        return $this->ICAO_Airport;
    }

    public function setICAOAirport(?string $ICAO_Airport): self
    {
        $this->ICAO_Airport = $ICAO_Airport;

        return $this;
    }

    public function getICAO1(): ?string
    {
        return $this->ICAO1;
    }

    public function setICAO1(?string $ICAO1): self
    {
        $this->ICAO1 = $ICAO1;

        return $this;
    }
}
