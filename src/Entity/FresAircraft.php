<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use APY\DataGridBundle\Grid\Mapping as GRID;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Cache;

/**
 * App\Entity\FresAircraft
 *
 * @ORM\Table(name="FRes_aircraft")
 * @ORM\Entity
 * @ORM\Cache(usage="NONSTRICT_READ_WRITE")
 * 
 */
class FresAircraft
{
    /**
     * @var string $clientid
     *
     * @ORM\Column(name="clientid", type="integer", nullable=false)
     * @GRID\Column(visible=false)
     */
    private $clientid;
    
    /**
     * @var integer $id
     *
     * @ORM\Column(name="id", type="integer", nullable=false)
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="IDENTITY")
     * @GRID\Column(title="Flugzeug ID", filterable = false)
     */
    private $id; 

    /**
     * @var string $aircraft
     *
     * @ORM\Column(name="aircraft", type="string", length=100, nullable=true)
     * @GRID\Column(title="Flugzeugname", filterable = false)
     */
    private $aircraft;

    /**
     * @var string $kennung
     *
     * @ORM\Column(name="kennung", type="string", length=100, nullable=true)
     * @GRID\Column(title="Flugzeugkennung", filterable = false)
     */
    private $kennung;

    /**
     * @var string $adminids
     *
     * @ORM\Column(name="adminIDs", type="string", length=100, nullable=true)
     * @GRID\Column(visible=false)
     */
    private $adminids;
    
    /**
     * @var string $status
     *
     * @ORM\Column(name="status", type="string", length=30, nullable=true)
     * @GRID\Column(visible=false)
     */
    private $status;
    
    /**
     * @var string $aircrafttype
     *
     * @ORM\Column(name="aircrafttype", type="integer", nullable=false)
     * @GRID\Column(visible=false)
     */
    private $aircrafttype;
    
    /**
     * @var integer $advancebooking
     *
     * @ORM\Column(name="advancebooking", type="integer", nullable=true)
    * @GRID\Column(title="Vorrausbuchung", filterable = false)
     */
    private $advancebooking;  


    /**
     * Set clientid
     *
     * @param integer $clientid
     */
    public function setClientid($clientid)
    {
        $this->clientid = $clientid;
    }

    /**
     * Get clientid
     *
     * @return integer 
     */
    public function getClientid()
    {
        return $this->clientid;
    }
    
    /**
     * Set id
     *
     * @param integer $id 
     */
    public function SetId($id)
    {
        $this->id = $id;
    }
    
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
     * Set aircraft
     *
     * @param string $aircraft
     */
    public function setAircraft($aircraft)
    {
        $this->aircraft = $aircraft;
    }

    /**
     * Get aircraft
     *
     * @return string 
     */
    public function getAircraft()
    {
        return $this->aircraft;
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

    /**
     * Set adminids
     *
     * @param string $adminids
     */
    public function setAdminids($adminids)
    {
        $this->adminids = $adminids;
    }

    /**
     * Get adminids
     *
     * @return string 
     */
    public function getAdminids()
    {
        return $this->adminids;
    }
    
    /**
     * Set status
     *
     * @param string $status
     */
    public function setStatus($status)
    {
        $this->status = $status;
    }
    
    /**
     * Get status
     *
     * @return string 
     */
    public function getStatus()
    {
        return $this->status;
    }
    
    /**
     * Set aircrafttype
     *
     * @param string $aircrafttype
     */
    public function setAircrafttype($aircrafttype)
    {
        $this->aircrafttype = $aircrafttype;
    }
    
    /**
     * Get aircrafttype
     *
     * @return string 
     */
    public function getAircrafttype()
    {
        return $this->aircrafttype;
    }
    
    /**
     * Set advancebooking
     *
     * @param integer $advancebooking
     */
    public function setAdvancebooking($advancebooking)
    {
        $this->advancebooking = $advancebooking;
    }

    /**
     * Get advancebooking
     *
     * @return integer 
     */
    public function getAdvancebooking()
    {
        return $this->advancebooking;
    }        
    
}