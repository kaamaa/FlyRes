<?php

namespace App\Entity;

use Symfony\Component\Validator\ExecutionContext;
use Doctrine\ORM\Mapping as ORM;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Cache;

/**
 * App\Entity\FresFIAvailability
 * 
 * @ORM\Table(name="fres_fi_availability")
 * @ORM\Entity()
 * @ORM\Cache(usage="NONSTRICT_READ_WRITE")
 */

class FresFIAvailability
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
     * @var string $clientid
     *
     * @ORM\Column(name="clientid", type="integer", nullable=false)
     */
    private $clientid;
    
    /**
     * @ORM\OneToOne(targetEntity="FResAccounts")
     * @ORM\JoinColumn(name="flightinstructor", referencedColumnName="id")
     * 
     * 
     */
    private $flightinstructor;
    
    /**
     * @ORM\OneToOne(targetEntity="FresFIAvailabilityStates")
     * @ORM\JoinColumn(name="typ", referencedColumnName="id")
     * 
     */
    private $typ;
    
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
     * @var string $status
     *
     * @ORM\Column(name="Status", type="string", length=30, nullable=false)
     */
    private $status;

     /**
     * @ORM\Column(name="comment", type="string", length=255, nullable=true)
     */
    private $comment;

    
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
     * Set flightinstructor
     *
     * @param object FresAccounts
     */
    public function setFlightinstructor($flightinstructor)
    {
        $this->flightinstructor = $flightinstructor;
    }

    /**
     * Get flightinstructor
     *
     * @return FresAccounts 
     */
    public function getFlightinstructor()
    {
        return $this->flightinstructor;
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
     * Set typ
     *
     * @param integer $typ
     */
    public function setTyp($typ)
    {
        $this->typ = $typ;
    }

    /**
     * Get typ
     *
     * @return integer 
     */
    public function getTyp()
    {
        return $this->typ;
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

    public function getComment(): ?string
    {
        return $this->comment;
    }

    /**
     * Set comment
     *
     * @param string $comment
     */
    public function setComment(?string $comment): self
    {
        $this->comment = $comment;

        return $this;
    }
}