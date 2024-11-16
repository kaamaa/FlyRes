<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Cache;

/**
 * App\Entity\FresFlightpurpose
 *
 * @ORM\Table(name="FRes_flightPurpose")
 * @ORM\Entity
 * @ORM\Cache(usage="NONSTRICT_READ_WRITE")
 */
class FresFlightpurpose
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
     * @var string $flightpurpose
     *
     * @ORM\Column(name="flightPurpose", type="string", length=30, nullable=true)
     */
    private $flightpurpose;

    /**
     * @var string $color
     *
     * @ORM\Column(name="color", type="string", length=7, nullable=true)
     */
    private $color;



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
     * Set flightpurpose
     *
     * @param string $flightpurpose
     */
    public function setFlightpurpose($flightpurpose)
    {
        $this->flightpurpose = $flightpurpose;
    }

    /**
     * Get flightpurpose
     *
     * @return string 
     */
    public function getFlightpurpose()
    {
        return $this->flightpurpose;
    }

    /**
     * Set color
     *
     * @param string $color
     */
    public function setColor($color)
    {
        $this->color = $color;
    }

    /**
     * Get color
     *
     * @return string 
     */
    public function getColor()
    {
        return $this->color;
    }
}