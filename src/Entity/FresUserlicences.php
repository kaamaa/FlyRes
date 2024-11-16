<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use APY\DataGridBundle\Grid\Mapping as GRID;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Cache;


/**
 * FresUserlicences
 *
 * @ORM\Table(name="FRes_userLicences")
 * @ORM\Entity
 * @GRID\Source(sortable=true)
 * @ORM\Cache(usage="NONSTRICT_READ_WRITE")
 *
 */
class FresUserlicences
{
    /**
     * @var integer
     *
     * @ORM\Column(name="id", type="integer", nullable=false)
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="IDENTITY")
     * 
     * @GRID\Column(visible=false)
     */
    private $id;

    /**
     * @var integer
     *
     * @ORM\Column(name="clientid", type="integer", nullable=false)
     * 
     * @GRID\Column(visible=false)
     */
    private $clientid;

    /**
     * @var integer
     *
     * @ORM\Column(name="accountid", type="integer", nullable=false)
     * 
     * @GRID\Column(visible=false)
     */
    private $accountid;
    
    /**
     * @ORM\OneToOne(targetEntity="FResAccounts")
     * @ORM\JoinColumn(name="accountid", referencedColumnName="id")
     * 
     * @GRID\Column(field="user.firstname", title="Vorname", filterable = false)
     * @GRID\Column(field="user.lastname", title="Nachname", filterable = false)
     */
    protected $user;

    /**
     * @var integer
     *
     * @ORM\Column(name="licenceid", type="integer", nullable=false)
     * @GRID\Column(visible=false)
     */
    private $licenceid;
    
    /**
     * @ORM\OneToOne(targetEntity="FresLicencetype")
     * @ORM\JoinColumn(name="licenceid", referencedColumnName="id")
     * 
     * @GRID\Column(field="licence.categoryid", title="Kategorie", filterable = true, visible=false)
     * @GRID\Column(field="licence.categoryname", title="Lizenztyp", filterable = false)
     * @GRID\Column(field="licence.longname", title="Bezeichunung", filterable = false)
     *
     */
    protected $licence;
    
    /**
     * @var boolean
     *
     * @ORM\Column(name="validunlimited", type="boolean", nullable=false)
     * 
     * @GRID\Column(title="unbegrenzt gültig", visible = false, filterable = false)
     */
    private $validunlimited;

    /**
     * @var \DateTime
     *
     * @ORM\Column(name="validuntil", type="date", nullable=false)
     * 
     * @GRID\Column(title="Gültig bis", filterable = false, format = "d.m.Y")
     */
    private $validuntil;

    /**
     * @var string
     *
     * @ORM\Column(name="comment", type="string", length=1000, nullable=true)
     * 
     * @GRID\Column(title="Anmerkung", filterable = false)
     * @GRID\Column(visible=false)
     */
    private $comment;
    
    /**
     * @var string $status
     *
     * @ORM\Column(name="status", type="string", length=30, nullable=true)
     * @GRID\Column(visible=false)
     */
    private $status;

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
     * @return FresUserlicences
     */
    public function setClientid($clientid)
    {
        $this->clientid = $clientid;
    
        return $this;
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
     * Set accountid
     *
     * @param integer $accountid
     * @return FresUserlicences
     */
    public function setAccountid($accountid)
    {
        $this->accountid = $accountid;
    
        return $this;
    }

    /**
     * Get accountid
     *
     * @return integer 
     */
    public function getAccountid()
    {
        return $this->accountid;
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
     * Set licenceid
     *
     * @param integer $licenceid
     * @return FresUserlicences
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
    
    /**
     * Get licence
     *
     * @return FresLicencetype 
     */
    public function getLicence()
    {
        return $this->licence;
    }
    
    /**
     * Set licence
     *
     * @param object $licence
     * @return FresUserlicences
     */
    public function setLicence($licence)
    {
        $this->licence = $licence;
        return $this;
    }
    
    /**
     * Set validunlimited
     *
     * @param boolean $validunlimited
     * @return FresUserlicences
     */
    public function setValidunlimited($validunlimited)
    {
        $this->validunlimited = $validunlimited;
    
        return $this;
    }

    /**
     * Get validunlimited
     *
     * @return boolean 
     */
    public function getValidunlimited()
    {
        return $this->validunlimited;
    }


    /**
     * Set validuntil
     *
     * @param \DateTime $validuntil
     * @return FresUserlicences
     */
    public function setValiduntil($validuntil)
    {
        $this->validuntil = $validuntil;
    
        return $this;
    }

    /**
     * Get validuntil
     *
     * @return \DateTime 
     */
    public function getValiduntil()
    {
        return $this->validuntil;
    }

    /**
     * Set comment
     *
     * @param string $comment
     * @return FresUserlicences
     */
    public function setComment($comment)
    {
        $this->comment = $comment;
    
        return $this;
    }

    /**
     * Get comment
     *
     * @return string 
     */
    public function getComment()
    {
        return $this->comment;
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