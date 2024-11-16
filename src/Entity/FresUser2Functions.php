<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Cache;

// SQL zum Erzeugen
// insert into FRes_user2Functions (userid, functionid) select FRes_accounts.`id`, FRes_accounts.`function` from FRes_accounts
//
// update `FRes_user2Functions` set functionid = 4 where functionid = 2

/**
 * FresUser2Functions
 *
 * @ORM\Table(name="FRes_user2Functions")
 * @ORM\Entity
 * @ORM\Cache(usage="NONSTRICT_READ_WRITE")
 */
class FresUser2Functions
{
    /**
     * @var integer
     *
     * @ORM\Column(name="userid", type="integer", nullable=false)
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="NONE")
     */
    private $userid;

    /**
     * @var integer
     *
     * @ORM\Column(name="functionid", type="integer", nullable=false)
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="NONE")
     */
    private $functionid;

    
    /**
     * Set userid
     *
     * @param integer $userid
     * @return FresUser2Functions
     */
    public function setAircrafttypeid($userid)
    {
        $this->userid = $userid;
    
        return $this;
    }

    /**
     * Get userid
     *
     * @return integer 
     */
    public function getUserid()
    {
        return $this->userid;
    }

    /**
     * Set functionid
     *
     * @param integer $functionid
     * @return FresUser2Functions
     */
    public function setFunctionid($functionid)
    {
        $this->functionid = $functionid;
    
        return $this;
    }

    /**
     * Get functionid
     *
     * @return integer 
     */
    public function getFunctionid()
    {
        return $this->functionid;
    }
}