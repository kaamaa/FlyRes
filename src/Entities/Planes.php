<?php

namespace App\Entities;

use App\Entities\Bookings;

class Planes
{
  const const_geloescht = 'geloescht';
  const const_inactive = 'inactive';
  
  public static function DeletePlane ($em, $clientid, $id)
  {
    //$plane = $em->getRepository('App\Entity\FresAircraft')->findOneBy(array('clientid' => $clientid, 'id' => $id));
    $querystring = "SELECT b FROM App\Entity\FresAircraft b WHERE b.clientid = :clientID and b.id = :id and b.status <> '" . Planes::const_geloescht . "' and b.status <> '" . Planes::const_inactive . "'";
    $query = $em->createQuery($querystring)->setParameters(array('clientID' =>  $clientid, 'id' => $id));
    $plane = $query->getSingleResult();
    if ($plane)
    {
      Bookings::DeleteAllBookingsForAPlane($em, $clientid, $id);
      $plane->setStatus(Planes::const_geloescht);
      $em->persist($plane);
      $em->flush();
    }
  }
  
  public static function SetPlaneToInactive ($em, $clientid, $id)
  {
    //$plane = $em->getRepository('App\Entity\FresAircraft')->findOneBy(array('clientid' => $clientid, 'id' => $id));
    $querystring = "SELECT b FROM App\Entity\FresAircraft b WHERE b.clientid = :clientID and b.id = :id and b.status <> '" . Planes::const_geloescht . "'and b.status <> '" . Planes::const_inactive . "'";
    $query = $em->createQuery($querystring)->setParameters(array('clientID' =>  $clientid, 'id' => $id));
    $plane = $query->getSingleResult();
    if ($plane)
    {
      $plane->setStatus(Planes::const_inactive);
      $em->persist($plane);
      $em->flush();
    }
  }
  
  public static function CheckIfBookingIsInAdvanceRange ($em, $clientid, $id, $bookingdate)
  {
    $querystring = "SELECT b FROM App\Entity\FresAircraft b WHERE b.clientid = :clientID and b.id = :id and b.status <> '" . Planes::const_geloescht . "'and b.status <> '" . Planes::const_inactive . "'";
    $query = $em->createQuery($querystring)->setParameters(array('clientID' =>  $clientid, 'id' => $id));
    $plane = $query->getSingleResult();
    if ($plane)
    {
      $advancebooking = $plane->getAdvancebooking();
      if ($advancebooking == 0) return '';
      
      $maxdate = new \DateTime();
      $maxdate = $maxdate->add(new \DateInterval('P' . $advancebooking . 'D'));
      
      if ($bookingdate > $maxdate) return 'Eine Vorrausbuchung für dieses Flugzeug ist für maximal ' . $advancebooking. ' Tage (' . $maxdate->format('d.m.Y') . ') möglich';
        else return '';
    }
  } 
  
  public static function GetPlaneObject ($em, $clientid, $id, $inactive = false)
  {
    if ($inactive) // Es werden auch Flugzeuge zurückgegeben, die auf inaktiv gesetzt sind
    {
      $querystring = "SELECT b FROM App\Entity\FresAircraft b WHERE b.clientid = :clientID and b.id = :id and b.status <> '" . Planes::const_geloescht . "'";
    }
    else
    {  
      $querystring = "SELECT b FROM App\Entity\FresAircraft b WHERE b.clientid = :clientID and b.id = :id and b.status <> '" . Planes::const_geloescht . "'and b.status <> '" . Planes::const_inactive . "'";
    }  
    $query = $em->createQuery($querystring)->setParameters(array('clientID' =>  $clientid, 'id' => $id));
    $plane = $query->getSingleResult();
    return $plane;
  } 
  
  public static function GetAllPlanesAsObject ($em, $clientid)
  {
    $planes = array ();
    $querystring = "SELECT b FROM App\Entity\FresAircraft b WHERE b.clientid = :clientID and b.status <> '" . Planes::const_geloescht . "'and b.status <> '" . Planes::const_inactive . "'";
    $query = $em->createQuery($querystring)->setParameters(array('clientID' =>  $clientid));
    $query->setCacheable(true);
    $planes = $query->getResult();
    return $planes;
  }
  
  public static function GetAllPlanesForMonthview ($em, $clientid)
  {
    $planelist = array ();
    //$planes = $em->getRepository('App\Entity\FresAircraft')->findBy(array('clientid' => $clientid));
    $querystring = "SELECT b FROM App\Entity\FresAircraft b WHERE b.clientid = :clientID and b.status <> '" . Planes::const_geloescht . "'and b.status <> '" . Planes::const_inactive . "'";
    $query = $em->createQuery($querystring)->setParameters(array('clientID' =>  $clientid));
    $query->setCacheable(true);
    $planes = $query->getResult();
    if ($planes) {
      foreach ($planes as $plane) {
        $planelist[] = array('planeID' => $plane->getId(), 'type' => $plane->getAircraft(), 'kennung' => $plane->getKennung());
      }
    }
    return $planelist;
  }
  
  public static function GetPlaneNameAndKennung ($em, $clientid, $id)
  {
    //$querystring = "SELECT b FROM App\Entity\FresAircraft b WHERE b.clientid = :clientID and b.id = :id and b.status <> '" . Planes::const_geloescht . "'and b.status <> '" . Planes::const_inactive . "'";
    $querystring = "SELECT b FROM App\Entity\FresAircraft b WHERE b.clientid = :clientID and b.id = :id and b.status <> '" . Planes::const_geloescht . "'";
    $query = $em->createQuery($querystring)->setParameters(array('clientID' =>  $clientid, 'id' => $id));
    try {
      $plane = $query->getSingleResult();
      if ($plane) return $plane->getAircraft() . ' (' . $plane->getkennung() . ')';
    } 
    catch (\Doctrine\Orm\NoResultException $e) 
    {
      return "Flugzeug nicht gefunden";
    }  
  }
  
  public static function GetAllPlanesForListbox ($em, $clientid)
  {
    $querystring = "SELECT b FROM App\Entity\FresAircraft b WHERE b.clientid = :clientID and b.status <> '" . Planes::const_geloescht . "'" . "and b.status <> '" . Planes::const_inactive . "' ORDER BY b.aircraft ASC";
    $query = $em->createQuery($querystring)->setParameters(array('clientID' =>  $clientid));
    $query->setCacheable(true);
    $planes = $query->getResult();
    if ($planes) {
      foreach ($planes as $plane) {
        $planelist[$plane->getAircraft() . " (" . $plane->getKennung() . ")"] = $plane->getId();
      }
    }
    if (!isset($planelist)) $planelist[0] = 'kein Flugzeug vorhanden';
    return $planelist;
  } 
  
  public static function GetAllAircraftTypes ($em, $clientid)
  {
    $aircraftTypeList = array ();
    $querystring = "SELECT b FROM App\Entity\FresAircrafttype b WHERE b.clientid = :ClientId";
    $query = $em->createQuery($querystring)->setParameters(array('ClientId' => $clientid));
    
    //$query = $em->createQuery($querystring);
    $query->setCacheable(true);
    $aircfraftTypes = $query->getResult();
    if ($aircfraftTypes) {
      foreach ($aircfraftTypes as $aircfraftType) {
        $aircraftTypeList[$aircfraftType->getLongname() . ' (' . $aircfraftType->getShortname(). ')'] = $aircfraftType->getId();
      }
    }
    return $aircraftTypeList;
  } 
  
  public static function GetAircraftTypeObject ($em, $id, $clientid)
  {
    $querystring = "SELECT b FROM App\Entity\FresAircrafttype b WHERE b.id = :Id and b.clientid = :ClientId";
    $query = $em->createQuery($querystring)->setParameters(array('Id' => $id, 'ClientId' => $clientid));
    $aircraftType = $query->getSingleResult();
    return $aircraftType;
  } 
  
  public static function GetAircraftTypeForAircraft ($em, $id, $clientid)
  {
    $querystring = "SELECT b FROM App\Entity\FresAircraft b WHERE b.clientid = :clientID and b.id = :id and b.status <> '" . Planes::const_geloescht . "'and b.status <> '" . Planes::const_inactive . "'";
    $query = $em->createQuery($querystring)->setParameters(array('clientID' =>  $clientid, 'id' => $id));
    try {
       // default action is always to return a Document
       $plane = $query->getSingleResult();
       if ($plane) return $plane->getAircrafttype();
    } catch (QueryException $e) {
        return NULL;
    }
    return NULL;
  } 
  
}