<?php

namespace App\Entities;

class Licensetype
{
  const const_geloescht = 'geloescht';
  
  public static function SetLicensetypeToInactive ($em, int $id)
  {
    $querystring = "SELECT b FROM App\Entity\FresLicencetype b WHERE b.id = :id";
    $query = $em->createQuery($querystring)->setParameter('id', $id);
    $licenseType = $query->getSingleResult();
    if ($licenseType)
    {
      $licenseType->setStatus(Licensetype::const_geloescht);
      $em->persist($licenseType);
      $em->flush();
    }
  }
  
  public static function GetLicenseTypeObject ($em, int $id)
  {
    $querystring = "SELECT b FROM App\Entity\FresLicencetype b WHERE b.id = :id";
    $query = $em->createQuery($querystring)->setParameter('id', $id);
    $licenseType = $query->getSingleResult();
    return $licenseType;
  } 
}