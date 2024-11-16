<?php

namespace App\Entity;

use Symfony\Component\Validator\ExecutionContext;
use Doctrine\ORM\Mapping as ORM;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Cache;

/**
 * App\Entity\FresBooking
 * 
 * @ORM\Entity()
 * @ORM\Cache(usage="NONSTRICT_READ_WRITE")
 *
 * @ORM\Table(name="FRes_booking")
 * @ORM\Entity
 */
class FresBooking
{  
    /**
     * @var string $clientid
     *
     * @ORM\Column(name="clientid", type="integer", nullable=false)
     */
    private $clientid;
    
    /**
     * @var integer $id
     *
     * @ORM\Column(name="id", type="integer", nullable=false)
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="IDENTITY")
     */
    private $id;

    /**
     * @var datetime $changeddate
     *
     * @ORM\Column(name="changedDate", type="datetime", nullable=false)
     */
    private $changeddate;

    /**
     * @var integer $changedbyuserid
     *
     * @ORM\Column(name="changedByUserID", type="integer", nullable=true)
     */
    private $changedbyuserid;

    /**
     * @var datetime $createddate
     *
     * @ORM\Column(name="createdDate", type="datetime", nullable=false)
     */
    private $createddate;

    /**
     * @var integer $createdbyuserid
     *
     * @ORM\Column(name="createdByUserID", type="integer", nullable=true)
     */
    private $createdbyuserid;

    /**
     * @var integer $aircraftid
     *
     * @ORM\Column(name="aircraftID", type="integer", nullable=false)
     */
    private $aircraftid;

    /**
     * @var integer $airfieldid
     *
     * @ORM\Column(name="airfieldID", type="integer", nullable=true)
     */
    private $airfieldid;

    /**
     * @var integer $flightpurposeid
     *
     * @ORM\Column(name="flightPurposeID", type="integer", nullable=true)
     */
    private $flightpurposeid;

    /**
     * @var datetime $itemstart
     *
     * @ORM\Column(name="itemstart", type="datetime", nullable=false)
     */
    private $itemstart;

    /**
     * @var datetime $itemstop
     *
     * @ORM\Column(name="itemstop", type="datetime", nullable=false)
     */
    private $itemstop;

    /**
     * @var text $description
     *
     * @ORM\Column(name="description", type="text", nullable=true)
     */
    private $description;

    /**
     * @var text $emailinfoi
     *
     * @ORM\Column(name="emailinfoi", type="text", nullable=true)
     */
    private $emailinfoi;

    /**
     * @var text $emailinfoe
     *
     * @ORM\Column(name="emailinfoe", type="text", nullable=true)
     */
    private $emailinfoe;

    /**
     * @var string $status
     *
     * @ORM\Column(name="status", type="string", length=30, nullable=false)
     */
    private $status;

    /**
     * @var integer $flightinstructor
     *
     * @ORM\Column(name="flightinstructor", type="integer", nullable=true)
     */
    private $flightinstructor;

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
     * Get id
     *
     * @return integer 
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set changeddate
     *
     * @param datetime $changeddate
     */
    public function setChangeddate($changeddate)
    {
        $this->changeddate = $changeddate;
    }

    /**
     * Get changeddate
     *
     * @return datetime 
     */
    public function getChangeddate()
    {
        return $this->changeddate;
    }

    /**
     * Set changedbyuserid
     *
     * @param integer $changedbyuserid
     */
    public function setChangedbyuserid($changedbyuserid)
    {
        $this->changedbyuserid = $changedbyuserid;
    }

    /**
     * Get changedbyuserid
     *
     * @return integer 
     */
    public function getChangedbyuserid()
    {
        return $this->changedbyuserid;
    }

    /**
     * Set createddate
     *
     * @param datetime $createddate
     */
    public function setCreateddate($createddate)
    {
        $this->createddate = $createddate;
    }

    /**
     * Get createddate
     *
     * @return datetime 
     */
    public function getCreateddate()
    {
        return $this->createddate;
    }

    /**
     * Set createdbyuserid
     *
     * @param integer $createdbyuserid
     */
    public function setCreatedbyuserid($createdbyuserid)
    {
        $this->createdbyuserid = $createdbyuserid;
    }

    /**
     * Get createdbyuserid
     *
     * @return integer 
     */
    public function getCreatedbyuserid()
    {
        return $this->createdbyuserid;
    }

    /**
     * Set aircraftid
     *
     * @param integer $aircraftid
     */
    public function setAircraftid($aircraftid)
    {
        $this->aircraftid = $aircraftid;
    }

    /**
     * Get aircraftid
     *
     * @return integer 
     */
    public function getAircraftid()
    {
        return $this->aircraftid;
    }

    /**
     * Set airfieldid
     *
     * @param integer $airfieldid
     */
    public function setAirfieldid($airfieldid)
    {
        $this->airfieldid = $airfieldid;
    }

    /**
     * Get airfieldid
     *
     * @return integer 
     */
    public function getAirfieldid()
    {
        return $this->airfieldid;
    }

    /**
     * Set flightpurposeid
     *
     * @param integer $flightpurposeid
     */
    public function setFlightpurposeid($flightpurposeid)
    {
        $this->flightpurposeid = $flightpurposeid;
    }

    /**
     * Get flightpurposeid
     *
     * @return integer 
     */
    public function getFlightpurposeid()
    {
        return $this->flightpurposeid;
    }

    /**
     * Set itemstart
     *
     * @param datetime $itemstart
     */
    public function setItemstart($itemstart)
    {
        $this->itemstart = $itemstart;
    }

    /**
     * Get itemstart
     *
     * @return datetime 
     */
    public function getItemstart()
    {
        return $this->itemstart;
    }

    /**
     * Set itemstop
     *
     * @param datetime $itemstop
     */
    public function setItemstop($itemstop)
    {
        $this->itemstop = $itemstop;
    }

    /**
     * Get itemstop
     *
     * @return datetime 
     */
    public function getItemstop()
    {
        return $this->itemstop;
    }

    /**
     * Set description
     *
     * @param text $description
     */
    public function setDescription($description)
    {
        $this->description = $description;
    }

    /**
     * Get description
     *
     * @return text 
     */
    public function getDescription()
    {
        return $this->description;
    }

    /**
     * Set emailinfoi
     *
     * @param text $emailinfoi
     */
    public function setEmailinfoi($emailinfoi)
    {
        $this->emailinfoi = $emailinfoi;
    }

    /**
     * Get emailinfoi
     *
     * @return text 
     */
    public function getEmailinfoi()
    {
        return $this->emailinfoi;
    }

    /**
     * Set emailinfoe
     *
     * @param text $emailinfoe
     */
    public function setEmailinfoe($emailinfoe)
    {
        $this->emailinfoe = $emailinfoe;
    }

    /**
     * Get emailinfoe
     *
     * @return text 
     */
    public function getEmailinfoe()
    {
        return $this->emailinfoe;
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
     * Set flightinstructor
     *
     * @param integer $flightinstructor
     */
    public function setFlightinstructor($flightinstructor)
    {
        $this->flightinstructor = $flightinstructor;
    }

    /**
     * Get flightinstructor
     *
     * @return integer 
     */
    public function getFlightinstructor()
    {
        return $this->flightinstructor;
    }
    
    /*
    // Diese Funktion wird wegen eines Softwarebugs in Symfony nicht verwendet
    public function isCorrect()
    {
        if ($this->getItemstart() >= $this->getItemstop()) return FALSE;
    }
    */
}