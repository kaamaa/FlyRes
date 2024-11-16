<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use APY\DataGridBundle\Grid\Mapping as GRID;
use Doctrine\Common\Collections\ArrayCollection;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Cache;
use App\Entity\FresAircrafttype;
use App\Entity\FresLicencetype;

use App\Entity\FresAircrafttype2licences;

/**
 * FresAircrafttype
 *
 * @ORM\Table(name="FRes_aircraftType")
 * @ORM\Entity
 * @ORM\Cache(usage="NONSTRICT_READ_WRITE")
 */
class FresAircrafttype
{
    /**
     * @var integer
     *
     * @ORM\Column(name="id", type="integer", nullable=false)
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="IDENTITY")
     * 
     * @GRID\Column(visible=true)
     * @GRID\Column(title="Flugzeugtypen ID", filterable = false)
     */
    private $id;
    
    /**
     * @var string $clientid
     *
     * @ORM\Column(name="clientid", type="integer", nullable=false)
     * @GRID\Column(visible=false)
     * 
     */
    private $clientid;

    /**
     * @var string
     *
     * @ORM\Column(name="shortname", type="string", length=30, nullable=false)
     * 
     * @GRID\Column(visible=true)
     * @GRID\Column(title="Kurzname", filterable = false)
     */
    private $shortname;

    /**
     * @var string
     *
     * @ORM\Column(name="longname", type="string", length=1000, nullable=false)
     * @GRID\Column(visible=true)
     * @GRID\Column(title="Flugzeugtypenname", filterable = false)
     * 
     */
    private $longname;
    
    /**
     * @var string $status
     *
     * @ORM\Column(name="status", type="string", length=30, nullable=true)
     * @GRID\Column(visible=false)
     */
    private $status;
    
    /**
     * @ORM\Cache(usage="NONSTRICT_READ_WRITE")
     * @ORM\ManyToMany(targetEntity="FresLicencetype")
     * @ORM\JoinTable(name="FRes_aircraftType2Licences",
     *      joinColumns={@ORM\JoinColumn(name="aircrafttypeid", referencedColumnName="id")},
     *      inverseJoinColumns={@ORM\JoinColumn(name="licenceid", referencedColumnName="id")}
     *      )
     * 
     * //@GRID\Column(field="licencetypes.longname", title="Bezeichnung", filterable = false)
     * 
     */
    private $licencetypes;
    
    
    public function __construct() 
    {
      // Initialize collection
      $this->licencetypes = new ArrayCollection();
    }
    
    /**
     * Get licencetypes
     *
     */
    public function getLicencetypes()
    {
        return $this->licencetypes;
    }
    
    /**
     * Set licencetypes
     *
     * @param $licencetypes
     */
    public function setLicencetypes($licencetypes)
    {
        $this->licencetypes = $licencetypes;
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
     * Set shortname
     *
     * @param string $shortname
     * @return FresAircrafttype
     */
    public function setShortname($shortname)
    {
        $this->shortname = $shortname;
    
        return $this;
    }

    /**
     * Get shortname
     *
     * @return string 
     */
    public function getShortname()
    {
        return $this->shortname;
    }

    /**
     * Set longname
     *
     * @param string $longname
     * @return FresAircrafttype
     */
    public function setLongname($longname)
    {
        $this->longname = $longname;
    
        return $this;
    }

    /**
     * Get longname
     *
     * @return string 
     */
    public function getLongname()
    {
        return $this->longname;
    }
    
    /**
     * Set status
     *
     * @param string $status
     */
    public function setStatus($status)
    {
        $this->status = $status;
        
        return $this;
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