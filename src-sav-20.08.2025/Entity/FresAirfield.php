<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Cache;

/**
 * App\Entity\FresAirfield
 *
 * @ORM\Table(name="FRes_airfield")
 * @ORM\Entity
 * @ORM\Cache(usage="NONSTRICT_READ_WRITE")
 */
class FresAirfield
{
    /**
     * @var integer $id
     *
     * @ORM\Column(name="id", type="integer", nullable=false)
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="IDENTITY")
     */
    private $id;

    /**
     * @var string $airfield
     *
     * @ORM\Column(name="airfield", type="string", length=150, nullable=true)
     */
    private $airfield;

    /**
     * @var string $kennung
     *
     * @ORM\Column(name="kennung", type="string", length=150, nullable=true)
     */
    private $kennung;


    /**
     * Get id
     *
     * @return integer 
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set airfield
     *
     * @param string $airfield
     */
    public function setAirfield($airfield)
    {
        $this->airfield = $airfield;
    }

    /**
     * Get airfield
     *
     * @return string 
     */
    public function getAirfield()
    {
        return $this->airfield;
    }

    /**
     * Set kennung
     *
     * @param string $kennung
     */
    public function setKennung($kennung)
    {
        $this->kennung = $kennung;
    }

    /**
     * Get kennung
     *
     * @return string 
     */
    public function getKennung()
    {
        return $this->kennung;
    }
}