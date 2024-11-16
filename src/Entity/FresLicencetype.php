<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use APY\DataGridBundle\Grid\Mapping as GRID;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Cache;

/**
 * FresLicencetype
 *
 * @ORM\Table(name="FRes_licenceType")
 * @ORM\Entity
 * @ORM\Cache(usage="NONSTRICT_READ_WRITE")
 */
class FresLicencetype
{
    /**
     * @var integer
     *
     * @ORM\Column(name="id", type="integer", nullable=false)
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="IDENTITY")
     * @GRID\Column(title="ID", filterable = true)
     *
     */
    private $id;

    /**
     * @var integer
     *
     * @ORM\Column(name="categoryid", type="integer", nullable=true)
     * @GRID\Column(title="Kategorie", filterable = true)
     */
    private $categoryid;

    /**
     * @var string
     *
     * @ORM\Column(name="categoryname", type="string", length=30, nullable=true)
     * @GRID\Column(title="Kategoriename", filterable = true)
     */
    private $categoryname;

    /**
     * @var string
     *
     * @ORM\Column(name="longname", type="string", length=1000, nullable=false)
     * @GRID\Column(title="Bezeichnung", filterable = true)
     */
    private $longname;
    
    /**
     * @var string
     *
     * @ORM\Column(name="description", type="string", length=3000, nullable=false)
     * @GRID\Column(title="Beschreibung", filterable = true)
     */
    private $description;
    
    /**
     * @var string $status
     *
     * @ORM\Column(name="status", type="string", length=30, nullable=true)
     * @GRID\Column(title="Status", visible=false)
     */
    private $status;
    
    public function __toString()
    {
        return sprintf('(%s) %s', $this->categoryname, $this->longname);
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
     * Set categoryid
     *
     * @param integer $categoryid
     * @return FresLicencetype
     */
    public function setCategoryid($categoryid)
    {
        $this->categoryid = $categoryid;
    
        return $this;
    }

    /**
     * Get categoryid
     *
     * @return integer 
     */
    public function getCategoryid()
    {
        return $this->categoryid;
    }

    /**
     * Set categoryname
     *
     * @param string $categoryname
     * @return FresLicencetype
     */
    public function setCategoryname($categoryname)
    {
        $this->categoryname = $categoryname;
    
        return $this;
    }

    /**
     * Get categoryname
     *
     * @return string 
     */
    public function getCategoryname()
    {
        return $this->categoryname;
    }

    /**
     * Set longname
     *
     * @param string $longname
     * @return FresLicencetype
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
     * Set description
     *
     * @param string $description
     * @return FresLicencetype
     */
    public function setDescription($description)
    {
        $this->description = $description;
    
        return $this;
    }

    /**
     * Get description
     *
     * @return string 
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