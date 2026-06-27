<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Cache;

/**
 * App\Entity\FresClient — Mandant (Flugschule).
 *
 * @ORM\Table(name="FRes_client")
 * @ORM\Entity
 * @ORM\Cache(usage="NONSTRICT_READ_WRITE")
 */
class FresClient
{
    /**
     * @var integer $id
     * @ORM\Column(name="id", type="integer", nullable=false)
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="IDENTITY")
     */
    private $id;

    /**
     * @var string $name
     * @ORM\Column(name="name", type="string", length=120, nullable=true)
     */
    private $name;

    /** @ORM\Column(name="active", type="boolean", nullable=false, options={"default":1}) */
    private $active = true;

    public function getId() { return $this->id; }

    public function getName() { return $this->name; }
    public function setName($name) { $this->name = $name; return $this; }

    public function isActive() { return (bool) $this->active; }
    public function setActive($v) { $this->active = (bool) $v; return $this; }
}
