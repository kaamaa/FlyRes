<?php

namespace App\Entities;

use App\Entities\FIAvailability;
use App\Entities\Users;

class FIAvailability
{
  const const_geloescht = 'geloescht';
  
  public static function GetAvailabilityStateObject ($em, $id)
  {
    return $em->getRepository('App\Entity\FresFIAvailabilityStates')->findOneBy(array('id' => $id));
  }
  
  public static function GetAvailabilityObject ($em, $clientid, $id)
  {
    $availability = $em->getRepository('App\Entity\FresFIAvailability')->findOneBy(array('clientid' => $clientid, 'id' => $id));
    return $availability;
  }
  
  public static function DeleteAvailability ($em, $clientid, $id)
  {
    $availability = $em->getRepository('App\Entity\FresFIAvailability')->findOneBy(array('clientid' => $clientid, 'id' => $id));
    if ($availability)
    {
      $availability->setStatus(FIAvailability::const_geloescht);
      $em->persist($availability);
      $em->flush();
    }
  }
  
  public static function IsOverlapping ($em, $newAvailability)
  {
    // Doctrine kann nicht mit Null umgehen, daher den Wert 0 setzen
    $id = $newAvailability->getId();
    if (empty($id)) $id = 0;
    
    // Diese Function sucht anhand der Verfügbbarkeit eines Lehrers in $newAvailability (von Start bis Stop)
    // heraus, der Lehrer zu der Zeit verfügbar ist
    $querystring = "SELECT b FROM App\Entity\FresFIAvailability b WHERE ";
    
    //Buchungen finden: Verfügbarkeit liegt innerhalb der geplanten Buchung
    $querystring .= "((b.itemstart >= :booking_start and b.itemstop <= :booking_end) or "; 
    
    //Buchungen finden: Verfügbarkeit startet vor und endet nach der geplanten Buchung
    $querystring .= "(b.itemstart <= :booking_start and b.itemstop >= :booking_end) or "; 
    
    //Buchungen finden: Verfügbarkeit startet an oder und endet genau in der Zeit, in der eine Verfügbarkeit werden soll 
    $querystring .= "(:booking_start > b.itemstart and :booking_start < b.itemstop) or ";
    $querystring .= "(:booking_end > b.itemstart and :booking_end < b.itemstop)) and "; 
    
    //Sonstige Parameter prüfen
    $querystring .= "b.clientid = :clientID and b.flightinstructor = :flightinstructor and ";
    $querystring .= "b.status <> '" . FIAvailability::const_geloescht . "' and b.id <> :ID";
    
    $query = $em->createQuery($querystring)->setParameters(array('booking_start' => $newAvailability->getItemstart(), 
                                                                 'booking_end' => $newAvailability->getItemstop(),
                                                                 'clientID' => $newAvailability->getClientid(),
                                                                 'flightinstructor' => $newAvailability->getFlightinstructor(),
                                                                 'ID' => $id));
    $query->setCacheable(true);
    $available = $query->getResult();
    if ($available) return 'Zu diesem Zeitpunkt liegt schon ein Eintrag vor, der mit dem neuen Eintrag überlappt oder direkt an ihn grenzt.';
      else return null;
  }
  
  private static function IsAvailableOnDemand($em, $newBooking)
  {
    // Prüft ob der Fluglehrer für diese Zeit auf Anfrage verfügbar ist
    $querystring = "SELECT b FROM App\Entity\FresFIAvailability b WHERE ";
    //Buchungen finden: Buchung liegt innerhalb der Verfügbarkeit
    $querystring .= "(b.itemstart <= :booking_start and b.itemstop >= :booking_end) and "; 
    //Sonstige Parameter prüfen
    $querystring .= " b.clientid = :clientID and b.flightinstructor = :flightinstructor and ";
    // Typ = 1 bedeutet "verfügbar" und Type = 2 bedeutet "auf Anfrage"
    $querystring .= "(b.typ = 1 or b.typ = 2) and ";
    $querystring .= "b.status <> '" . FIAvailability::const_geloescht . "'";
    $query = $em->createQuery($querystring)->setParameters(array('booking_start' => $newBooking->getItemstart(), 
                                                               'booking_end' => $newBooking->getItemstop(),
                                                               'clientID' => $newBooking->getClientid(),
                                                               'flightinstructor' => $newBooking->getFlightinstructor()));
    $query->setCacheable(true);
    $available_and_OnDemand = $query->getResult();
    if($available_and_OnDemand or !empty($available_and_OnDemand)) return true;
      else return false;
  }
  
  private static function IsAvailable($em, $newBooking)
  {
    // Prüft ob der Fluglehrer für diese Zeit verfügbar ist
    $querystring = "SELECT b FROM App\Entity\FresFIAvailability b WHERE ";
    //Buchungen finden: Buchung liegt innerhalb der Verfügbarkeit
    $querystring .= "(b.itemstart <= :booking_start and b.itemstop >= :booking_end) and "; 
    //Sonstige Parameter prüfen
    $querystring .= " b.clientid = :clientID and b.flightinstructor = :flightinstructor and ";
    // Typ = 1 bedeutet "verfügbar"
    $querystring .= "(b.typ = 1) and ";
    $querystring .= "b.status <> '" . FIAvailability::const_geloescht . "'";
    $query = $em->createQuery($querystring)->setParameters(array('booking_start' => $newBooking->getItemstart(), 
                                                               'booking_end' => $newBooking->getItemstop(),
                                                               'clientID' => $newBooking->getClientid(),
                                                               'flightinstructor' => $newBooking->getFlightinstructor()));
    $query->setCacheable(true);
    $available = $query->getResult();
    if($available or !empty($available)) return true;
      else return false;
  }
  
  private static function IsOverlap($em, $newBooking)
  {
    // Diese Fubktion sucht nach zusammenhängenden Bereichen von Verfügbar und "verfügbar auf Anfrage" im Zeitbereich 
    // der neuen Reservierung
    $querystring = "SELECT b FROM App\Entity\FresFIAvailability b WHERE ";
   
    //Buchungen finden: Buchung startet vor und endet nach der geplanten Buchung
    $querystring .= "((b.itemstart <= :booking_start and b.itemstop >= :booking_end) or "; 

    //Buchungen finden: Buchung liegt innerhalb der Zeit, in der geflogen werden soll 
    $querystring .= "(b.itemstart >= :booking_start and b.itemstop <= :booking_end) or ";

    //Buchungen finden: Buchung startet in der Zeit, in der geflogen werden soll 
    $querystring .= "(:booking_start >= b.itemstart and :booking_start < b.itemstop) or ";
    
    //Buchungen finden: Buchung endet in der Zeit, in der geflogen werden soll 
    $querystring .= "(:booking_end > b.itemstart and :booking_end <= b.itemstop)) and "; 
    
    //Sonstige Parameter prüfen
    $querystring .= " b.clientid = :clientID and b.flightinstructor = :flightinstructor and ";
    // Typ = 1 bedeutet "verfügbar" und Type = 2 bedeutet "auf Anfrage"
    $querystring .= "(b.typ = 1 or b.typ = 2) and ";
    $querystring .= "b.status <> '" . FIAvailability::const_geloescht . "'" . "ORDER BY b.itemstart";
    $query = $em->createQuery($querystring)->setParameters(array('booking_start' => $newBooking->getItemstart(), 
                                                               'booking_end' => $newBooking->getItemstop(),
                                                               'clientID' => $newBooking->getClientid(),
                                                               'flightinstructor' => $newBooking->getFlightinstructor()));
    $query->setCacheable(true);
    $overlapps = $query->getResult();
    
    // prüpfen ob der gesamte Bereich abgedeckt ist
    if($overlapps && count($overlapps) > 0)
    {
      $start = $overlapps[0]->getItemstart();
      $end = $overlapps[count($overlapps)-1]->getItemstop();
      $bstart = $newBooking->getItemstart();
      $bend = $newBooking->getItemstop();
      if ($bstart < $start || $bend > $end)
      {
        return false;
      }
    }

    // Wenn es Lücken gibt dann false zurückgeben, da der Fluglehrer dann nicht für den Teil zur Verfügung steht
    // Lücken suchen:
    $overlapplist = new \ArrayObject($overlapps);
    if($overlapplist && count($overlapplist) > 0)
    {
      $array_iterator = $overlapplist->getIterator();
      while($array_iterator->valid())
      {
        try 
        {
          $current = $array_iterator->current();
          if (isset($last,$current))
          {
            // liegen die beiden Elemente nicht direkt aneinander?
            if ($last->getItemstop() != $current->getItemstart())
            {
              // Die Elemente liegen nicht direkt aneinander, also ist hat der Fluglehrer eine Lücke in der
              // Buchungszeit und kann daher nicht gennutzt werden
              return false;
            }
          }
          $last = $array_iterator->current();
          $array_iterator->next();
        } 
        catch (Exception $exception) 
        {
          continue;
        }    
      }
    }
    if($overlapplist && count($overlapplist) > 0) return true;
      else return false;
  }
  
  public static function IsFlightinstructorAvailable ($em, $newBooking, $loggedin_user)
  {
    // Prüft, ob der Fluglehrer zu der Zeit der geplanten Reservierung verfügbar ist
    // zunähst die ID des Fluglehrers aus der neuen Buchung ermitteln
    // Es wird immer geprüft ob der Lehrer verfügbar ist, auch wenn keine Schulung
    // oder Soloflug sondern Charter angeben, aber ein Fluglehrer bei der Buchung eingetragen wurde
    
    $fid = $newBooking->getFlightinstructor();
    if (empty($fid))
    {
      // kein Fluglehrer ausgewählt, daher ergibt die Prüfung, das kein Fehler vorliegt
      return "";
    }
    
    $onRequest = Users::IsFlightinstructorBookableOnRequest($em, $fid);  

    // prüfen ob der Fluglehrer generell immer verfügbar ist 
    if(Users::IsFlightinstructorAlwaysAvailable($em, $fid, $loggedin_user)) return "";

    if(FIAvailability::IsAvailable($em, $newBooking)) 
      return "";

    if($onRequest && FIAvailability::IsAvailableOnDemand($em, $newBooking)) 
      return "";

    if(!$onRequest && FIAvailability::IsAvailableOnDemand($em, $newBooking)) 
      return "Der ausgewählte Fluglehrer steht in dieser Zeit erst nach vorherige Absprache zur Verfügung. Bitte den Fluglehrer vorab ansprechen.";

    if($onRequest && FIAvailability::IsOverlap($em, $newBooking))
      return "";
    
    if(!$onRequest && FIAvailability::IsOverlap($em, $newBooking))
      return "Der ausgewählte Fluglehrer steht für einen Teil der Zeit erst nach vorherige Absprache zur Verfügung. Bitte den Fluglehrer vorab ansprechen.";

    return "Der ausgewählte Fluglehrer steht zu dieser Zeit nicht zur Verfügung.";
     
  }
  
  public static function GetAvailabilityForOneDayAndFiAsObjects ($em, \DateTime $day, $fid, $clientid)
  {
    $int_day = (int) date_format($day, 'd');
    $int_month = (int) date_format($day, 'm');
    $int_year = (int) date_format($day, 'Y');
    
    $day_start = date('Y-m-d H:i:s', mktime ( 0,0,0 ,$int_month ,$int_day, $int_year));
    $day_end = date('Y-m-d H:i:s', mktime ( 0,0,0 ,$int_month ,$int_day+1, $int_year));
    
    $querystring = "SELECT b FROM App\Entity\FresFIAvailability b WHERE ";
    //Verfügbarkeit finden: Verfügbarkeit startet vor und endet nach dem Tag der angezeigt werden soll 
    $querystring .= "((b.itemstart <= :day_start and b.itemstop >= :day_end) or "; 
    
    //Verfügbarkeit finden: Verfügbarkeit startet an oder und endet genau an dem Tag der angezeigt werden soll 
    $querystring .= "(b.itemstart >= :day_start and b.itemstart <= :day_end) or ";
    $querystring .= "(b.itemstop >= :day_start and b.itemstop <= :day_end)) and "; 
    
    $querystring .= "b.clientid = :clientID and b.flightinstructor = :flightinstructor and ";
    // Typ = 1 bedeutet "verfügbar" und Type = 2 bedeutet "auf Anfrage"
    $querystring .= "b.status <> 'storniert' and (b.typ = 1 or b.typ = 2) and ";
    $querystring .= "b.status <> '" . FIAvailability::const_geloescht . "' ORDER BY b.itemstart";
    $query = $em->createQuery($querystring)->setParameters(array('day_start' => $day_start, 
                                                                 'day_end' => $day_end, 
                                                                 'clientID' =>  $clientid,
                                                                 'flightinstructor' => $fid));
    $query->setCacheable(true);
    $result = $query->getResult();  
    return $result;  
  }
  
  public static function GetAvailabilityForOneDayAsObjects ($em, \DateTime $day, $clientid)
  {
    $int_day = (int) date_format($day, 'd');
    $int_month = (int) date_format($day, 'm');
    $int_year = (int) date_format($day, 'Y');
    
    $day_start = date('Y-m-d H:i:s', mktime ( 0,0,0 ,$int_month ,$int_day, $int_year));
    $day_end = date('Y-m-d H:i:s', mktime ( 0,0,0 ,$int_month ,$int_day+1, $int_year));
    
    $querystring = "SELECT b FROM App\Entity\FresFIAvailability b WHERE ";
    //Buchungen finden: Buchung startet vor und endet nach dem Tag der angezeigt werden soll 
    $querystring .= "((b.itemstart <= :day_start and b.itemstop >= :day_end) or "; 
    //Buchungen finden: Buchung startet an oder und endet genau an dem Tag der angezeigt werden soll 
    $querystring .= "(b.itemstart >= :day_start and b.itemstart <= :day_end) or (b.itemstop >= :day_start and b.itemstop <= :day_end)) and "; 
    $querystring .= "b.clientid = :clientID and b.status <> 'storniert' and b.status <> '" . FIAvailability::const_geloescht . "' ORDER BY b.itemstart";
    $query = $em->createQuery($querystring)->setParameters(array('day_start' => $day_start, 
                                                                 'day_end' => $day_end, 
                                                                 'clientID' =>  $clientid));
    $query->setCacheable(true);
    return $query->getResult();  
  }
  
  public static function GetAvailabilityForOneDayAndFIAsObjectsWithWidth ($em, $fi, $clientid, $y, $m, $d)
  {
    // Übersicht über alle Fluglehrer und ihre Verfügbarkeit ab einem bestimmten Startdatum
    // Die Liste aller Verfügbarkeiten wird als Objektliste zurück gegeben
      
    $filist = Users::GetAllFlightinstructorsAsObject($em, $clientid);
    $daystart = new \DateTime();
    $daystart->setDate($y, $m, $d);
    $availabilitylist = FIAvailability::GetAvailabilityForOneDayAsObjects($em, $daystart, $clientid);
    
    foreach ($availabilitylist as $availability)
    {
      $di = $availability->getItemstart()->diff($availability->getItemstop());
      $width = floor(($di->d * 24 * 60 + $di->h * 60 + $di->i) / 30);
    
      // Der Klasse wird dynamisch ein neues Element "width" hinzugefügt
      $availability->width = $width;
    }

    $viewList = array('day' => $daystart, 
                      'fis' => $filist, 
                      'availabilities' => $availabilitylist);
    
    return $viewList;

    /*        
    $di = $start->diff($end);
    $rowspan = floor(($di->d * 24 * 60 + $di->h * 60 + $di->i) / 30);
    $time = date_format($start, 'H : i');
    $bookingList[] = array('bookingid' => $booking->getid(), 
                           'time' => $time,
                           'rowspan' => $rowspan, 
                           'start' => date_format($booking->getItemstart(), 'd.m.Y H:i'),
                           'end' => date_format($booking->getItemstop(), 'd.m.Y H:i'),
                           'userid' => $booking->getCreatedbyuserid(),
                           'user' => Users::GetUserName($em, $booking->getClientid(), $booking->getCreatedbyuserid()),
                           'flightpurpose' => FlightPurposes::GetFlightpurpose($em, $booking->getflightpurposeid()), 
                           'isflighttraining' => $isFlightTraining,
                           'flightinstructor' => $flightinstructor,
                           'airfield' => Airfields::GetAirfield($em, $booking->getAirfieldid()),
                           'description' => $booking->getdescription()); 
     * 
     */     

  }
  
  public static function GetAllAvailabilityStates ($em)
  {
    $availabilitystateList = array ();
    $querystring = "SELECT b FROM App\Entity\FresFIAvailabilityStates b order by b.id asc";
    $query = $em->createQuery($querystring);
    $query->setCacheable(true);
    $availabilitystates = $query->getResult();
    if ($availabilitystates) {
      foreach ($availabilitystates as $availabilitystate) {
        $availabilitystateList[$availabilitystate->getName()] = $availabilitystate->getId();
      }
    }
    return $availabilitystateList;
  } 
  
}