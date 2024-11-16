<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Cache;

/**
 * FresAircrafttype2licences
 *
 * @ORM\Table(name="FRes_aircraftType2Licences")
 * @ORM\Entity
 * @ORM\Cache(usage="NONSTRICT_READ_WRITE")
 */
class FresAircrafttype2licences
{
    /**
     * @var integer
     *
     * @ORM\Column(name="aircrafttypeid", type="integer", nullable=false)
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="NONE")
     */
    private $aircrafttypeid;

    /**
     * @var integer
     *
     * @ORM\Column(name="licenceid", type="integer", nullable=false)
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="NONE")
     */
    private $licenceid;



    /**
     * Set aircrafttypeid
     *
     * @param integer $aircrafttypeid
     * @return FresAircrafttype2licences
     */
    public function setAircrafttypeid($aircrafttypeid)
    {
        $this->aircrafttypeid = $aircrafttypeid;
    
        return $this;
    }

    /**
     * Get aircrafttypeid
     *
     * @return integer 
     */
    public function getAircrafttypeid()
    {
        return $this->aircrafttypeid;
    }

    /**
     * Set licenceid
     *
     * @param integer $licenceid
     * @return FresAircrafttype2licences
     */
    public function setLicenceid($licenceid)
    {
        $this->licenceid = $licenceid;
    
        return $this;
    }

    /**
     * Get licenceid
     *
     * @return integer 
     */
    public function getLicenceid()
    {
        return $this->licenceid;
    }
}