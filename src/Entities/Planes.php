<?php

namespace App\Entities;

use App\Entities\Bookings;

class Planes
{
  const const_geloescht = 'geloescht';
  const const_inactive = 'inactive';
  
  /**
   * Spaetester buchbarer Zeitpunkt (Start) fuer ein Flugzeug mit N Tagen
   * Vorausbuchung. Regel: es zaehlen ganze Tage; der aeusserste Tag (heute + N)
   * wird erst am Vorabend um 20:00 Uhr komplett freigegeben. Davor endet das
   * Fenster am Vortag. Rueckgabe = Ende (23:59:59) des letzten buchbaren Tages,
   * oder null bei 0 = unbegrenzt.
   */
  public static function GetAdvanceBookingCutoff ($advancebooking, ?\DateTime $now = null)
  {
    $advancebooking = (int) $advancebooking;
    if ($advancebooking <= 0) return null;                 // unbegrenzt

    $now    = $now ?: new \DateTime();
    $maxday = (new \DateTime('today'))->add(new \DateInterval('P' . $advancebooking . 'D'));   // heute + N Tage
    // Der neue aeusserste Tag wird erst am Vorabend um 20:00 Uhr freigegeben.
    if ($now < (new \DateTime('today'))->setTime(20, 0)) {
      $maxday->sub(new \DateInterval('P1D'));
    }
    return $maxday->setTime(23, 59, 59);                    // ganzer Tag buchbar
  }

  public static function CheckIfBookingIsInAdvanceRange ($em, $clientid, $id, $bookingdate)
  {
    $querystring = "SELECT b FROM App\Entity\FresAircraft b WHERE b.clientid = :clientID and b.id = :id and b.status <> '" . Planes::const_geloescht . "'and b.status <> '" . Planes::const_inactive . "'";
    $query = $em->createQuery($querystring)->setParameters(array('clientID' =>  $clientid, 'id' => $id));
    $plane = $query->getSingleResult();
    if ($plane)
    {
      $advancebooking = (int) $plane->getAdvancebooking();
      if ($advancebooking == 0) return '';

      $cutoff = self::GetAdvanceBookingCutoff($advancebooking);
      if ($cutoff !== null && $bookingdate > $cutoff)
      {
        // Zeitpunkt, ab dem der gewaehlte Tag freigegeben wird: (Tag - N Tage) um 20:00 Uhr.
        $opens = (clone $bookingdate);
        $opens->setTime(0, 0, 0);
        $opens->sub(new \DateInterval('P' . $advancebooking . 'D'));
        return 'Dieses Flugzeug kann maximal ' . $advancebooking . ' Tage im Voraus gebucht werden. Der '
             . $bookingdate->format('d.m.Y') . ' wird am ' . $opens->format('d.m.Y') . ' um 20:00 Uhr freigegeben.';
      }
      return '';
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
    $querystring = "SELECT b FROM App\Entity\FresAircrafttype b WHERE b.clientid = :ClientId and b.status <> 'geloescht'";
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