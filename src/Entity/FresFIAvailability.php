<?php

namespace App\Entity;

use Symfony\Component\Validator\ExecutionContext;
use Doctrine\ORM\Mapping as ORM;
use APY\DataGridBundle\Grid\Mapping as GRID;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Cache;

/**
 * App\Entity\FresFIAvailability
 * 
 * @ORM\Table(name="fres_fi_availability")
 * @ORM\Entity()
 * @ORM\Cache(usage="NONSTRICT_READ_WRITE")
 * @GRID\Source(sortable=true) groupBy={"status"})
 */

class FresFIAvailability
{  
    /**
     * @var integer $id
     *
     * @ORM\Column(name="id", type="integer", nullable=false)
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="IDENTITY")
     * @GRID\Column(visible=false)
     */
    private $id;

    /**
     * @var string $clientid
     *
     * @ORM\Column(name="clientid", type="integer", nullable=false)
     * @GRID\Column(visible=false)
     */
    private $clientid;
    
    /**
     * @ORM\OneToOne(targetEntity="FResAccounts")
     * @ORM\JoinColumn(name="flightinstructor", referencedColumnName="id")
     * 
     * @GRID\Column(field="flightinstructor.id", title="Id", filterable = false, visible=false)
     * @GRID\Column(field="flightinstructor.firstname", title="Vorname", filterable = true, filter="select", visible=true)
     * @GRID\Column(field="flightinstructor.lastname", title="Nachname", filterable = true, filter="select", visible=true)
     * 
     */
    private $flightinstructor;
    
    /**
     * @ORM\OneToOne(targetEntity="FresFIAvailabilityStates")
     * @ORM\JoinColumn(name="typ", referencedColumnName="id")
     * 
     * @GRID\Column(field="typ.id", title="TypID", filterable = false, visible=false)
     * @GRID\Column(field="typ.name", title="Typ", filterable = false, visible=true)
     */
    private $typ;
    
    /**
     * @var datetime $itemstart
     *
     * @ORM\Column(name="itemstart", type="datetime", nullable=false)
     * @GRID\Column(title="Start", type="datetime", filterable = false, format = "l d.m.Y G:i")
     
     */
    private $itemstart;

    /**
     * @var datetime $itemstop
     *
     * @ORM\Column(name="itemstop", type="datetime", nullable=false)
     * @GRID\Column(title="Ende", type="datetime", filterable = false, format = "l d.m.Y G:i")
     */
    private $itemstop;
    

    /**
     * @var string $status
     *
     * @ORM\Column(name="Status", type="string", length=30, nullable=false)
     * @GRID\Column(visible=false, filterable = false)
     */
    private $status;

     /**
     * @ORM\Column(name="comment", type="string", length=255, nullable=true)
     * @GRID\Column(title="Kommentar", visible=true, filterable = false)
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