<?php

namespace App\Entity;

use Symfony\Component\Validator\ExecutionContext;
use Doctrine\ORM\Mapping as ORM;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Cache;

/**
 * App\Entity\FresBooking
 *
 *
 */
#[ORM\Table(name: 'FRes_note')]
#[ORM\Cache(usage: 'NONSTRICT_READ_WRITE')]
#[ORM\Entity]
class FresNote
{  
    /**
     * @var string $clientid
     */
    #[ORM\Column(name: 'clientid', type: 'integer', nullable: false)]
    private $clientid;
    
    /**
     * @var integer $id
     */
    #[ORM\Column(name: 'id', type: 'integer', nullable: false)]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    private $id;
    

    /**
     * @var datetime $changeddate
     */
    #[ORM\Column(name: 'changedDate', type: 'datetime', nullable: false)]
    private $changeddate;

    /**
     * @var integer $changedbyuserid
     */
    #[ORM\Column(name: 'changedByUserID', type: 'integer', nullable: true)]
    private $changedbyuserid;

    /**
     * @var datetime $createddate
     */
    #[ORM\Column(name: 'createdDate', type: 'datetime', nullable: false)]
    private $createddate;

    /**
     * @var integer $createdbyuserid
     */
    #[ORM\Column(name: 'createdByUserID', type: 'integer', nullable: true)]
    private $createdbyuserid;

    #[ORM\JoinColumn(name: 'createdByUserID', referencedColumnName: 'id')]
    #[ORM\OneToOne(targetEntity: \FResAccounts::class)]
    protected $user;
    
    /**
     * @var datetime $validuntil
     */
    #[ORM\Column(name: 'validuntil', type: 'datetime', nullable: false)]
    private $validuntil;

    /**
     * @var text header
     */
    #[ORM\Column(name: 'header', type: 'text', nullable: true)]
    private $header;
    
    /**
     * @var text $description
     */
    #[ORM\Column(name: 'description', type: 'text', nullable: true)]
    private $description;

    /**
     * @var string $status
     */
    #[ORM\Column(name: 'status', type: 'string', length: 30, nullable: false)]
    private $status;

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
     * Get user
     *
     * @return FresLicencetype 
     */
    public function getUser()
    {
        return $this->user;
    }
    
    /**
     * Set user
     *
     * @param object $user
     * @return FresUserlicences
     */
    public function setUser($user)
    {
        $this->user = $user;
        return $this;
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
     * Set validuntil
     *
     * @param datetime $validuntil
     */
    public function setValiduntil($validuntil)
    {
        $this->validuntil = $validuntil;
    }

    /**
     * Get validuntil
     *
     * @return datetime 
     */
    public function getValiduntil()
    {
        return $this->validuntil;
    }
    
    /**
     * Set header
     *
     * @param text $header
     */
    public function setHeader($header)
    {
        $this->header = $header;
    }

    /**
     * Get header
     *
     * @return text 
     */
    public function getHeader()
    {
        return $this->header;
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
}