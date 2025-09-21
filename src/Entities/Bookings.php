<?php

namespace App\Entities;

use App\TimeFunctions;
use App\Entities\Planes;
use App\Entities\Users;
use App\Entities\FlightPurposes;
use App\Entities\Airfields;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Address;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;

class BookingGap
// Die Klasse BookingGap wird genutzt, um Freizeiten für Fluglehrer oder Flugzeuge aufzunehmen
{
  protected $start;
  protected $end;
  
  public function setStart($start) {
    $this->start = $start;
  }

  public function getStart() {
    return $this->start;
  }
  
  public function setEnd($end) {
    $this->end = $end;
  }

  public function getEnd() {
    return $this->end;
  }
}

class Bookings
{
  public static $em;
  private $params;
  
  public function __construct(ContainerBagInterface $params)
  {
      $this->params = $params;
  }
  
  
  protected static function IsInRange ($booking, $min, $max)
  {
    // Prüft, ob das Buchungsobjekt außerhalb des mitgegebenen Zeitraum liegt
    if ($booking)
    {
      if (($booking->getItemstart() <= $min and $booking->getItemstop() <= $min) or 
          ($booking->getItemstart() >= $max and $booking->getItemstop() >= $max)) 
      {
        return false;
      }
    }
    return true;
  }
  protected static function IsInbound ($booking, $min, $max)
  {
    // Prüft, ob das Buchungsobjekt vor oder an dem mitgegebenen Zeitraum startet und im Zeitraum endet
    if ($booking)
    {
      if ($booking->getItemstart() <= $min and 
          $booking->getItemstop() >= $min and 
          $booking->getItemstop() <= $max) 
      {
        return TRUE;
      }
    }
    return FALSE;
  }
  
  protected static function IsOutbound ($booking, $min, $max)
  {
    // Prüft, ob das Buchungsobjekt in dem mitgegebenen Zeitraum startet und nach oder an dem Zeitraum endet
    if ($booking)
    {
      if ($booking->getItemstart() >= $min and 
          $booking->getItemstart() <= $max and 
          $booking->getItemstop() >= $max) 
      {
        return TRUE;
      }
    }
    return FALSE;
  }
  
  protected static function GetGap ($booking1, $booking2)
  {
    // Prüft, ob zwischen zwei Buchungsobjekten eine Lücke existiert und gibt diese ggf. als BookingGap-Object zurücks
    if ($booking1 && $booking2)
    {
      // existiert eine Lücke?
      if ($booking1->getItemstop() < $booking2->getItemstart())
      {
        $bg = new BookingGap();
        $bg->setStart($booking1->getItemstop());
        $bg->setEnd($booking2->getItemstart());
        return $bg;
      }
    }
    return NULL;
  }
  
  protected static function GetBookingGaps($bookings, $mindate, $maxdate)
  {
    // ermittelt aus einer Liste von Buchungen die dazwischen enthaltenen freien Bereiche
    // wird für das Reservierungsfenster genutzt
    $bookinggaps = array();
    // Liegen Buchungen vor
    if (count($bookings) == 0)
    {
      // es liegen keinen Buchungen vor, also eine große Lücke erzeugen
      // Flugzeug ist also immer verfügbar
      $e = new BookingGap();
      $e->setStart($mindate);
      $e->setEnd($maxdate);
      $bookinggaps[] = $e;
    }
    else
    {
      // Über alle weiteren Buchungsobjekte iterieren
      for($i = 0; $i < count($bookings); $i++)
      {
        $booking = $bookings[$i];
        // Prüfen ob die Buchung im Zeitrahmen des Fluglehrers ist
        if (Bookings::IsInRange($booking, $mindate, $maxdate))
        {
          // Ist es das erste Element?
          if ($booking === reset($bookings))
          {
            // FIRST ELEMENT
            // Beginnt das Element auch am Tagesbeginn oder davor, oder muss eine freie
            // Lücke vorne eingefügt werden
            if (!Bookings::IsInbound($booking, $mindate, $maxdate))
            {
              $e = new BookingGap();
              $e->setStart($mindate);
              $e->setEnd($booking->getItemstart());
              $bookinggaps[] = $e;
            }
          }
          // jetzt  mittleren Elemente prüfen
          // gibt es noch eine Folgebuchung?
          if ($i+1 < count($bookings))
          {
            // es gibt noch mindestens ein weitere Folgebuchung im Array, 
            // deswegen die Lücke ermitteln, wenn es eien gibt
            $e = Bookings::GetGap($booking, $bookings[$i+1]);
            if ($e) $bookinggaps[] = $e;
          }
          // Ist es das letzte Element
          if ($booking === $bookings[count($bookings)-1])
          {
            // LAST ELEMENT
            // Endet das Element auch am Tagesende oder danach, oder muss eine freie
            // Lücke am Ende eingefügt werden
            if (!Bookings::IsOutbound($booking, $mindate, $maxdate))
            {
              $e = new BookingGap();
              $e->setStart($booking->getItemstop());
              $e->setEnd($maxdate);
              $bookinggaps[] = $e;
            }
          }
        }
      }
    }
    return $bookinggaps;
  }
  
  protected static function AdjustAvailabilitiesAccordingToBooking(&$availabilities, $booking, $index, $av)
  {
    // Diese Funktion nimmt die  Verfügbarkeit des Fluglehrers und reduziert sie um die Zeit 
    // der bereits existierenden Buchungen für den Fluglehrer
    // Rückgabe ist ein Array mit den Verfügbarkeiten unter Berücksichtigung der Buchungen
    $astart = $av->getItemstart();
    $astop = $av->getItemstop();
    $bstart = $booking->getItemstart();
    $bstop = $booking->getItemstop();

    if ($astart <= $bstart && $astop >= $bstop)
    {
        // die Buchung liegt gebau mitten in der Verfügbarkeit
        // Daher muss die Verfüpgbarkeit in zwei Teile rechts und links geteilt werden
      
        // ein neues Elementzu Beginn nur einführen, wenn Start der Verfügbarkeit und 
        // Start der Buchung nicht identisch sind
        if ($astart != $bstart)
        {
          $new1 = clone $av;
          $new1->setItemstart($astart);
          $new1->setItemstop($bstart);
          $availabilities[] = $new1;
        }
        // ein neues Element am Ende nur einführen, wenn Stopp der Verfügbarkeit und 
        // Stopp der Buchung nicht identisch sind
        if ($astop != $bstop)
        {
          $new2 = clone $av;
          $new2->setItemstart($bstop);
          $new2->setItemstop($astop);
          $availabilities[] = $new2;
        }
        unset($availabilities[$index]);    
        return;
    } 
    if ($bstart <= $astart && $bstop >= $astop)
    {
        // die Buchung überlappt die Verfügbarkeit komplett aus, daher die Verfügbarkeit löschen
        unset($availabilities[$index]);    
        return;
    } 
    if ($bstart <= $astart && ($bstop >= $astart && $bstop <= $astop))
    {
        // die Buchung liegt am linken Rand (zu Beginn), daher die Verfügbarkeit kürzen
        $availabilities[$index]->setItemstart($bstop);  
        return;
    }   
    if (($bstart >= $astart && $bstart <= $astop) && $bstop >= $astop)
    {
        // die Buchung liegt am rechten Rand (am Ende), daher die Verfügbarkeit kürzen
        $availabilities[$index]->setItemstop($bstart);  
        return;
    }   
  }
 
  public static function GetAllAvailableFIsForADate ($em, $clientid, $date)
  {
    // Ermittelt für das Reservierungsfenster alle verfügbaren Fluglehrer und ihrer Verfügbarkeit für den Tag
    date_default_timezone_set('Europe/Berlin');
    $int_day = (int) $date->format('d');
    $int_month = (int) $date->format('m');
    $int_year = (int) $date->format('Y');
    $message = '';
    
    // Zunächst alle Fluglehrer für den Mandanten ermitteln
    $FIs = Users::GetAllFlightinstructorsAsObject($em, $clientid);
    if ($FIs) {
      // Über alle Fluglehrer iterieren
      foreach ($FIs as $FI) 
      {
        if ($FI)  
        {
            // Ist der Fluglehrer verfügbar
            $availabilities = FIAvailability::GetAvailabilityForOneDayAndFiAsObjects ($em, $date, $FI->getId(), $clientid);
            $bookings = Bookings::GetBookingsForOneDayAndFIAsObjects ($em, $int_day, $int_month, $int_year, $FI->getId(), $clientid);
           
            foreach ($bookings as $booking) 
            {
                $avs = $availabilities;
                foreach ($avs as $index=>$av) 
                {
                    Bookings::AdjustAvailabilitiesAccordingToBooking($availabilities, $booking, $index, $av);    
                }
            }   
            
            if (!empty($availabilities))
            {
                $message = $message . "<b>" .$FI->getfirstname() . ' ' . $FI->getlastname() . '</b> ';
                foreach($availabilities as $av) 
                {
                  if ($av->getTyp()->getID() == 2) 
                  { 
                    $message = $message . "<span style='color:orange'>"; 
                  } 
                  else 
                  { 
                    $message = $message . "<span>"; 
                  }
                  $message = $message . $av->getItemstart()->format('G:i-') . $av->getItemstop()->format('G:i ');
                  $message = $message . "</span>"; 
                }
                $message = $message . "<br>";
            }
        }
      }
    }
    return $message;
  }
  
  
  
  public static function GetAllAvailablePlanesForADate ($em, $clientid, $date)
  {
    // Ermittelt für das Reservierungsfenster alle verfügbaren Flugzeuge und ihrer Verfügbarkeit für den Tag
    date_default_timezone_set('Europe/Berlin');
    
    $int_day = (int) $date->format('d');
    $int_month = (int) $date->format('m');
    $int_year = (int) $date->format('Y');
    
    $mindate = clone $date;
    $srary = TimeFunctions::GetDayStart($date);
    $mindate->setTime ( $srary[0] , $srary[1]);
    
    $maxdate = clone $date;
    $srary = TimeFunctions::GetDayEnd($date);
    $maxdate->setTime ( $srary[0] , $srary[1]);
    
    // Zunächst alle Flugzeuge für den Mandanten ermitteln
    $planes = Planes::GetAllPlanesAsObject($em, $clientid);
    if ($planes) {
      $message = '';
      // Über alle Flugzeuge iterieren
      foreach ($planes as $plane) 
      {
        // Alle Buchungen für das Flugzeug und den Tag ermitteln
        $bookings = Bookings::GetBookingsForOneDayAsObjects ($em, $int_day, $int_month, $int_year, $clientid, $plane->getId());
        $bookinggaps = Bookings::GetBookingGaps($bookings, $mindate, $maxdate);
        
        // Jetzt den Anzeigestring zusammenbauen
        if (count($bookinggaps) > 0 && $plane)
        {
          // Flugzeugname ausgeben
          $message = $message . "<b>" .$plane->getKennung() . '</b> ';
          foreach($bookinggaps as $bookinggap) 
          {
            // Lücken anfügen
            $message = $message . $bookinggap->getStart()->format('G:i-') . $bookinggap->getEnd()->format('G:i ');
          }
          // Zeilenumbruch für HTML-Textarea
          //$message = $message . "\r\n";
          $message = $message . "<br>";
        }
        
      }
    }
    return $message;
  }
  
  public static function IsAllowedtoChangeBooking ($em, $user, $booking)
  {
    if ($user)
    {
      // Prüfen ober der Nutzer Adminstratir ist oder der Eigentümer der Buchung oder Fluglehrer der Buchung
      if (Users::isAdmin($em, $user) || 
          $booking->getCreatedbyuserid() == $user->getId()|| 
          (Users::isFlightinstructor($em, $user->getId()) && $booking->getFlightinstructor() == $user->getId())) 
          return TRUE;
        else return FALSE;
    }
    return FALSE;
  }
  
  public static function IsPlaneAvailable ($em, $newBooking)
  {
    // Doctrine kann nicht mit Null umgehen, daher den Wert 0 setzen
    $id = $newBooking->getId();
    if (empty($id)) $id = 0;
    
    $querystring = "SELECT b FROM App\Entity\FresBooking b WHERE ";
    
    //Buchungen finden: Buchung liegt innerhalb der geplanten Buchung
    $querystring .= "((b.itemstart >= :booking_start and b.itemstop <= :booking_end) or "; 
    
    //Buchungen finden: Buchung startet vor und endet nach der geplanten Buchung
    $querystring .= "(b.itemstart <= :booking_start and b.itemstop >= :booking_end) or "; 
    
    //Buchungen finden: Buchung startet an oder und endet genau in der Zeit, in der geflogen werden soll 
    $querystring .= "(:booking_start > b.itemstart and :booking_start < b.itemstop) or (:booking_end > b.itemstart and :booking_end < b.itemstop)) and"; 
    
    //Sonstige Parameter prüfen
    $querystring .= " b.aircraftid = :planeID and b.clientid = :clientID and b.status <> 'storniert' and b.status <> 'flugzeug_geloescht' and b.status <> 'user_geloescht' and b.id <> :bookingID";
    $query = $em->createQuery($querystring)->setParameters(array('booking_start' => $newBooking->getItemstart(), 
                                                                 'booking_end' => $newBooking->getItemstop(),
                                                                 'planeID' => $newBooking->getAircraftid(), 
                                                                 'clientID' => $newBooking->getClientid(), 
                                                                 'bookingID' => $id));
    $query->setCacheable(true);
    $bookings = $query->getResult();
    if ($bookings) return 'Zu diesem Zeitpunkt liegt schon eine Reservierung für das Flugzeug vor.';
      else return null;
  }
  
  public static function HasFlightinstructorOwnBooking ($em, $newBooking)
  {
    // Prüft, ob der Fluglehrer nicht schon anderweitig eine Flugzeug reserviert hat und selber fliegen geht
    
    $bookings = NULL;
    $fid = $newBooking->getFlightinstructor();
    if (!empty($fid))
    {
      // Doctrine kann nicht mit Null umgehen, daher den Wert 0 setzen
      $id = $newBooking->getId();
      if (empty($id)) $id = 0;
      
      $querystring = "SELECT b FROM App\Entity\FresBooking b WHERE ";
      //Buchungen finden: Buchung startet vor und endet nach der geplanten Buchung
      $querystring .= "((b.itemstart <= :booking_start and b.itemstop >= :booking_end) or "; 
      
      //Buchungen finden: Buchung liegt innerhalb der Zeit, in der geflogen werden soll 
      $querystring .= "(b.itemstart >= :booking_start and b.itemstop <= :booking_end) or ";
      
      //Buchungen finden: Buchung startet in der Zeit, in der geflogen werden soll 
      $querystring .= "(:booking_start >= b.itemstart and :booking_start < b.itemstop) or ";
      //Buchungen finden: Buchung endet in der Zeit, in der geflogen werden soll 
      $querystring .= "(:booking_end > b.itemstart and :booking_end <= b.itemstop)) and "; 
      
      $querystring .= "b.clientid = :clientID and b.status <> 'storniert' and b.status <> 'flugzeug_geloescht' and b.status <> 'user_geloescht' and ";
      $querystring .= "b.createdbyuserid = :flightinstructor and b.id <> :bookingID";
      
      $query = $em->createQuery($querystring)->setParameters(array('booking_start' => $newBooking->getItemstart(), 'booking_end' => $newBooking->getItemstop(),
                                                                   'flightinstructor' => $fid,
                                                                   'clientID' => $newBooking->getClientid(),
                                                                   'bookingID' => $id));
      $query->setCacheable(true);
      $bookings = $query->getResult();
      if ($bookings) return true;
    }  
    return false;
  }

  public static function IsFlightinstructorNotBooked ($em, $newBooking, $parallelSoloAndDual = false)
  {
    // Prüft, ob der Fluglehrer nicht schon anderweitig gebucht ist
    // Parallel Soloflüge von Flugschülern werden in Abhängigkeit der Einstellung $parallelSoloAndDual zugelassen
    // Rückgabe ist NULL, wenn der Fluglehrer frei ist, ansonsten eine Fehlermeldung
    
    define('message1', 'Zu diesem Zeitpunkt hat der ausgewählte Fluglehrer bereits eine eigene Reservierung.');
    define('message2', 'Zu diesem Zeitpunkt ist der ausgewählte Fluglehrer schon für einen anderen Schulflug gebucht.');

    // Wenn parallele Solo- und Dualflüge zugelassen sind und der Flugschüler der neuen Buchung Solo fliegt, 
    // dann gilt der Fluglehrer als frei
    if ($parallelSoloAndDual && FlightPurposes::IsSolo($newBooking->getFlightpurposeid())) return NULL;
    
    $bookings = NULL;
    $fid = $newBooking->getFlightinstructor();  
    // Kein FI gesetzt -> kein Konflikt
    if (empty($fid)) return NULL;
    
    // Prüfen ob der Fluglehrer Doppelbuchungen zulässt
    if (Users::AllowDoubleBookingsforFlightinstructor($em, $fid)) return Null;
    
    // Prüfen ob der Fluglehrer eine eigene Reservierung hat
    if (Bookings::HasFlightinstructorOwnBooking($em, $newBooking)) return message1;
    
    // Doctrine kann nicht mit Null umgehen, daher den Wert 0 setzen
    $id = $newBooking->getId();
    if (empty($id)) $id = 0;
    
    $querystring = "SELECT b FROM App\Entity\FresBooking b WHERE ";
    //Buchungen finden: Buchung startet vor und endet nach der geplanten Buchung
    $querystring .= "((b.itemstart <= :booking_start and b.itemstop >= :booking_end) or "; 
    
    //Buchungen finden: Buchung liegt innerhalb der Zeit, in der geflogen werden soll 
    $querystring .= "(b.itemstart >= :booking_start and b.itemstop <= :booking_end) or ";
    
    //Buchungen finden: Buchung startet in der Zeit, in der geflogen werden soll 
    $querystring .= "(:booking_start >= b.itemstart and :booking_start < b.itemstop) or ";
    //Buchungen finden: Buchung endet in der Zeit, in der geflogen werden soll 
    $querystring .= "(:booking_end > b.itemstart and :booking_end <= b.itemstop)) and "; 
    
    $querystring .= "b.clientid = :clientID and b.status <> 'storniert' and b.status <> 'flugzeug_geloescht' and b.status <> 'user_geloescht' and ";
    $querystring .= "b.flightinstructor = :flightinstructor and b.id <> :bookingID";
    // Solo-Flüge von Flugschülern werden parallel nicht zugelassen, daher die folgende Zeile auskommentieren

    if ($parallelSoloAndDual)
    {
      // Wenn parallele Solo- und Dualflüge zugelassen sind, dann müssem Solo-Flüge von Flugschülern 
      // aus der Abfrage ausgeschlossen werden
      $querystring .= " and b.flightpurposeid <> :soloID";
      $query = $em->createQuery($querystring)->setParameters(array('booking_start' => $newBooking->getItemstart(), 'booking_end' => $newBooking->getItemstop(),
                                                                  'flightinstructor' => $fid,
                                                                  'clientID' => $newBooking->getClientid(),
                                                                  'soloID' => FlightPurposes::GetSoloID(),
                                                                  'bookingID' => $id));
    }
    else
    {
      // Wenn parallele Solo- und Dualflüge nicht zugelassen sind, dann müssen alle Flüge herausgesucht werden 
      $query = $em->createQuery($querystring)->setParameters(array('booking_start' => $newBooking->getItemstart(), 'booking_end' => $newBooking->getItemstop(),
                                                                  'flightinstructor' => $fid,
                                                                  'clientID' => $newBooking->getClientid(),
                                                                  'bookingID' => $id));
    }
    
    $query->setCacheable(true);
    $bookings = $query->getResult();
    //var_dump($bookings);
    if ($bookings) return message2;
     
    return null;
  }
  
  public static function _cmpStartDate($m, $n) {
    // Sortieren nach Datum und Flugzeug
    $date_m = date_format($m->getItemstart(), 'Y.m.d');
    $date_n = date_format($n->getItemstart(), 'Y.m.d');
    
    if ($date_m == $date_n) {
        // gleicher Tag, daher zuerst nach Flugzeug und dann nach Datum sortieren
        
        if ($m->getAircraftid() == $n->getAircraftid())
        {
          // Flugzeuge sind identisch
          return ($m->getItemstart() < $n->getItemstart()) ? -1 : 1;
        }
        else
        {
          // Flugzeuge sind nicht identisch
          return ($m->getAircraftid() < $n->getAircraftid()) ? -1 : 1;
        }
    }
    return ($m->getItemstart() < $n->getItemstart()) ? -1 : 1;
  }

  public static function _cmpStartDateDesc($m, $n): int
  {
      $am = $m->getItemstart(); // DateTimeInterface
      $an = $n->getItemstart();

      // 1) Tag DESC (neueste zuerst)
      $byDay = $an->format('Ymd') <=> $am->format('Ymd');
      if ($byDay !== 0) return $byDay;

      // 2) AircraftID ASC
      $byAircraft = (string)$m->getAircraftid() <=> (string)$n->getAircraftid();
      if ($byAircraft !== 0) return $byAircraft;

      return $am <=> $an; // Uhrzeit ASC
  }
  
  public static function _cmpAircraft($m, $n) {
    // Sortieren nach Flugzeug und Datum
    $date_m = date_format($m->getItemstart(), 'Y.m.d');
    $date_n = date_format($n->getItemstart(), 'Y.m.d');
    
    if ($m->getAircraftid() == $n->getAircraftid()) 
    {
      // Flugzeuge sind identisch
      if ($date_m == $date_n) 
      {
        // Es ist auch der gleiche Tag
        return ($m->getItemstart() < $n->getItemstart()) ? -1 : 1;
      }
      else
      {  
        // Tage sind nicht identisch
        return ($date_m < $date_n) ? -1 : 1;
      }  
      
    }
    return ($m->getAircraftid() < $n->getAircraftid()) ? -1 : 1;
  }
  
  public static function _cmpFlightinstructor($m, $n) {
    // Sortieren nach Fluglehrer und Datum
    $date_m = date_format($m->getItemstart(), 'Y.m.d');
    $date_n = date_format($n->getItemstart(), 'Y.m.d');
    
    if ($m->getFlightinstructor() == $n->getFlightinstructor()) 
    {
      // Fluglehrer sind identisch
      if ($date_m == $date_n) 
      {
        // Es ist auch der gleiche Tag
        return ($m->getItemstart() < $n->getItemstart()) ? -1 : 1;
      }
      else
      {  
        // Tage sind nicht identisch
        return ($date_m < $date_n) ? -1 : 1;
      }  
      
    }
    return ($m->getFlightinstructor() < $n->getFlightinstructor()) ? -1 : 1;
  }
  
  public static function _cmpUser($m, $n) {
    // Sortieren nach Flugzeug und Datum
    $date_m = date_format($m->getItemstart(), 'Y.m.d');
    $date_n = date_format($n->getItemstart(), 'Y.m.d');
           
    if (strcasecmp(Users::GetUserNameForAlphabeticOrder(self::$em, $m->getClientID(), $m->getCreatedbyuserid()), Users::GetUserNameForAlphabeticOrder(self::$em, $n->getClientID(), $n->getCreatedbyuserid())) == 0) 
    {
      // Nutzer sind identisch
      if ($date_m == $date_n) 
      {
        // Es ist auch der gleiche Tag
        return ($m->getItemstart() < $n->getItemstart()) ? -1 : 1;
      }
      else
      {  
        // Tage sind nicht identisch
        return ($date_m < $date_n) ? -1 : 1;
      }  
      
    }
    return (strcasecmp(Users::GetUserNameForAlphabeticOrder(self::$em, $m->getClientID(), $m->getCreatedbyuserid()), Users::GetUserNameForAlphabeticOrder(self::$em, $n->getClientID(), $n->getCreatedbyuserid())) < 0) ? -1 : 1;
  }
  
  public static function GetBookingsForGeneralView ($em, $command, $clientid, $userID = null)
  {
    setlocale(LC_TIME, 'de_DE.UTF-8', 'de_DE@euro', 'de_DE', 'de', 'ge');
    $bookingList = array();
    
    switch ($command) {
      case 'date':
      case 'fi':
      case 'planes':
      case 'users':
      case 'training':  
      case 'own':  
        $day_start_ux = mktime ( 0,0,0 , date("m"), date("j"), date("Y"));
        $day_end_ux = mktime ( 23,59,59 , 01, 01, 9999);
        break;
      case 'own_history':  
        $day_start_ux = mktime ( 0,0,0 , 01, 01, 1980);
        $day_end_ux = mktime ( 23,59,59 , 01, 01, 9999);
        break;
      case 'today':
        $day_start_ux = mktime ( 0,0,0 , date("m"), date("j"), date("Y"));
        $day_end_ux = mktime ( 23,59,59 , date("m"), date("j"), date("Y"));
        break;
      case 'tomorrow':
        $day_start_ux = mktime ( 0,0,0 , date("m",strtotime("tomorrow")),date("j",strtotime("tomorrow")),date("Y",strtotime("tomorrow")));
        $day_end_ux = mktime ( 23,59,59 , date("m",strtotime("tomorrow")),date("j",strtotime("tomorrow")),date("Y",strtotime("tomorrow")));
        break;
      case 'thisweek':
        $day_start_ux = mktime ( 0,0,0 , date("m",strtotime("sunday -6 days")),date("j",strtotime("sunday -6 days")),date("Y",strtotime("sunday -6 days")));
        $day_end_ux = mktime ( 23,59,59 , date("m",strtotime("sunday")),date("j",strtotime("sunday")),date("Y",strtotime("sunday")));
        break;
      case 'weekafter':
        $day_start_ux = mktime ( 0,0,0 , date("m",strtotime("sunday -6 days +1 week")),date("j",strtotime("sunday -6 days +1 week")),date("Y",strtotime("sunday -6 days +1 week")));
        $day_end_ux = mktime ( 23,59,59 , date("m",strtotime("sunday +1 week")),date("j",strtotime("sunday +1 week")),date("Y",strtotime("sunday +1 week")));
        break;
      case 'thisweekend':
        $day_start_ux = mktime ( 0,0,0 , date("m",strtotime("sunday -1 day")),date("j",strtotime("sunday -1 day")),date("Y",strtotime("sunday -1 day")));
        $day_end_ux = mktime ( 23,59,59 , date("m",strtotime("sunday")),date("j",strtotime("sunday")),date("Y",strtotime("sunday")));
        break;
      case 'nextweekend':
        $day_start_ux = mktime ( 0,0,0 , date("m",strtotime("sunday +1 week -1 day")),date("j",strtotime("sunday +1 week -1 day")),date("Y",strtotime("sunday +1 week -1 day")));
        $day_end_ux = mktime ( 23,59,59 , date("m",strtotime("sunday +1 week")),date("j",strtotime("sunday +1 week")),date("Y",strtotime("sunday +1 week")));
        break;

      default:
        die;
        break;
    }
    $day_start = date('Y-m-d H:i:s', $day_start_ux);
    $day_end = date('Y-m-d H:i:s', $day_end_ux);
    $querystring = "SELECT b FROM App\Entity\FresBooking b WHERE ";
    // Beginn der Buchung liegt zwischen Start und Ende
    $querystring .= "((b.itemstart >= :day_start and b.itemstart <= :day_end) or ";
    // Ende der Buchung liegt zwischen Start und Ende
    $querystring .= "(b.itemstop >= :day_start and b.itemstop <= :day_end) or"; 
    // Buchung beginnt vor Start und Buchung endet nach Ende
    $querystring .= "(b.itemstart < :day_start and b.itemstop > :day_end))"; 
    
    $querystring .= "and b.status <> 'storniert' and b.status <> 'flugzeug_geloescht' and b.status <> 'user_geloescht' and b.clientid = :clientID "; 
    if ($command == 'training' or $command == 'fi') $querystring .= "and (b.flightpurposeid = 2 or b.flightpurposeid = 5 or b.flightinstructor IS NOT NULL) "; 
    if ($command == 'own' or $command == 'own_history') 
    {
      $querystring .= " and b.createdbyuserid = :userid "; 
      $query = $em->createQuery($querystring)->setParameters(array('day_start' => $day_start, 'day_end' => $day_end, 'clientID' =>  $clientid, 'userid' => $userID));
    }
      else  $query = $em->createQuery($querystring)->setParameters(array('day_start' => $day_start, 'day_end' => $day_end, 'clientID' =>  $clientid));

    $query->setCacheable(true);
    $bookings = $query->getResult();
    
    if ($bookings) 
    {
      // Mehrtägige Objekte in mehrer eintägige Buchungen aufsplitten
      foreach ($bookings as $booking)
      {
        //$diff_days = $booking->getItemstart()->diff($booking->getItemstop())->days;
        $start = $booking->getItemstart();
        $end = $booking->getItemstop();
        $s_day = (int) $start->format('d');
        $s_month = (int) $start->format('m');
        $s_year = (int) $start->format('Y');

        $e_min = (int) $end->format('i');
        $e_hour = (int) $end->format('H');
        $e_day = (int) $end->format('d');
        $e_month = (int) $end->format('m');
        $e_year = (int) $end->format('Y');
        if ($s_day != $e_day or $s_month != $e_month or $s_year != $e_year)
        {
          // Es gibt mehrtägige Buchungen
          
          // mittlere Tage erstellen
          $diff_days = $booking->getItemstart()->diff($booking->getItemstop())->days;
          for ($i = 0; $i <= $diff_days-2; $i++) {
            // Buchung clonen
            $booking_between = clone $booking;
            
            $dt_start = new \DateTime($s_year . '-' . $s_month . '-' . ($s_day) . ' 0:0:0');
            $dt_ende = new \DateTime($s_year . '-' . $s_month . '-' . ($s_day) . ' 23:59:0');
            $booking_between->setItemStart($dt_start->add(date_interval_create_from_date_string($i+1 . ' days')));
            $booking_between->setItemStop($dt_ende->add(date_interval_create_from_date_string($i+1 . ' days')));
            // neue Buchung speichern
            $bookings[] = $booking_between;
          }
          
          // die erste Buchung (Start) um Mitternacht enden lassen
          $booking->setItemStop(new \DateTime($s_year . '-' . $s_month . '-' . $s_day . ' 23:59:0'));
          
          // Ende erstellen
          $booking_end = clone $booking;
          $booking_end->setItemStart(new \DateTime($e_year . '-' . $e_month . '-' . $e_day . ' 0:0:0'));
          $booking_end->setItemStop(new \DateTime($e_year . '-' . $e_month . '-' . $e_day . ' ' .  $e_hour . ':' . $e_min));
          
          $bookings[] = $booking_end;
        }
      }
      
      // Jetzt noch Elemente löschen, die vor dem Startdate beginnen
      // Dies kann passieren, wenn es mehrtägige Reservierungen gibt
      foreach ($bookings as $key => $booking)
      {
        if ($booking->getItemstart()->getTimestamp() < $day_start_ux) unset($bookings[$key]);
      }
      foreach ($bookings as $key => $booking)
      {
        if ($booking->getItemstop()->getTimestamp() > $day_end_ux) unset($bookings[$key]);
      }
     
      // Array sortieren
      switch ($command) {
        case 'planes':
          usort($bookings, 'self::_cmpAircraft');
          break; 
        case 'fi':
          usort($bookings, 'self::_cmpFlightinstructor');
          break; 
        case 'users':
          self::$em = $em;
          usort($bookings, 'self::_cmpUser');
          break; 
        case 'own_history':
          usort($bookings, 'self::_cmpStartDateDesc');
          break;    
        default:
          usort($bookings, 'self::_cmpStartDate');
          break;  
      }
      
      // Daten zusammenstellen
      foreach ($bookings as $booking)
      {
        $formatter = new \IntlDateFormatter('de_DE', \IntlDateFormatter::LONG, \IntlDateFormatter::NONE);
        $formatter->setPattern('eeee dd.MM.yyyy');
        $datestring = $formatter->format($booking->getItemstart());
        $bookingList[] = array( 'random' => mt_rand(), // random wird für den Formularnamen verwendet, da es mehrer Einträge zu einer Buchung geben kann
                                'bookingid' => $booking->getid(), 
                                'flugzeug' => Planes::GetPlaneNameAndKennung($em, $clientid, $booking->getAircraftid()),
                                'date' => $datestring,
                                'start' => date_format($booking->getItemstart(), 'H:i'),
                                'end' => date_format($booking->getItemstop(), 'H:i'),
                                'userid' => $booking->getCreatedbyuserid(),
                                'user' => Users::GetUserName($em, $clientid, $booking->getCreatedbyuserid()),
                                'flightinstructor' => Users::GetUserName($em, $clientid, $booking->getFlightinstructor()), 
                                'flightpurpose' => FlightPurposes::GetFlightpurpose($em, $booking->getflightpurposeid()), 
                                'isflighttraining' => FlightPurposes::IsSchulung($booking->getflightpurposeid()),
                                'description' => $booking->getdescription());
      }
    }
    return $bookingList;
  }
  
  public static function GetBookingObject ($em, $clientid, $id)
  {
    $booking = $em->getRepository('App\Entity\FresBooking')->findOneBy(array('clientid' => $clientid, 'id' => $id));
    return $booking;
  }
  
  protected static function GetBookingsForOneDayAndFIAsObjects ($em, $int_day, $int_month, $int_year, $fiid, $clientid)
  {
    // ermittelt alle Flüge eines Fluglehrers für einen bestimmten Flug
    // Ermittelt ebenfalls eigene Flüge des Fluglehrers, da er zu deer Zeit nicht als Fluglehrer aktiv sein kann
    // Soloflüge von Flugschülern werden nich berücksichtigt, da der Lehrer in der Zeit für andere Flüge zur Verfügung steht
    $day_start = date('Y-m-d H:i:s', mktime ( 0,0,0 ,$int_month ,$int_day, $int_year));
    $day_end = date('Y-m-d H:i:s', mktime ( 0,0,0 ,$int_month ,$int_day+1, $int_year));
    $querystring = "SELECT b FROM App\Entity\FresBooking b WHERE ";
    //Buchungen finden: Buchung startet vor und endet nach dem Tag der angezeigt werden soll 
    $querystring .= "((b.itemstart <= :day_start and b.itemstop >= :day_end) or "; 
    //Buchungen finden: Buchung startet an oder und endet genau an dem Tag der angezeigt werden soll 
    $querystring .= "(b.itemstart >= :day_start and b.itemstart <= :day_end) or (b.itemstop >= :day_start and b.itemstop <= :day_end)) and "; 
    $querystring .= "b.clientid = :clientID and (b.flightinstructor = :fiID or b.createdbyuserid = :fiID) and b.status <> 'storniert' and ";
    $querystring .= "b.status <> 'flugzeug_geloescht' and ";
    $querystring .= "b.status <> 'user_geloescht' and b.flightpurposeid <> :soloID ORDER BY b.itemstart";
    $query = $em->createQuery($querystring)->setParameters(array('day_start' => $day_start, 
                                                                 'day_end' => $day_end, 
                                                                 'fiID' => $fiid, 
                                                                 'soloID' => FlightPurposes::GetSoloID(),
                                                                 'clientID' =>  $clientid));
    $query->setCacheable(true);
    return $query->getResult();
  }
  
  protected static function GetBookingsForOneDayAsObjects ($em, $int_day, $int_month, $int_year, $clientid, $planeId)
  {
    
    $day_start = date('Y-m-d H:i:s', mktime ( 0,0,0 ,$int_month ,$int_day, $int_year));
    $day_end = date('Y-m-d H:i:s', mktime ( 0,0,0 ,$int_month ,$int_day+1, $int_year));
    $querystring = "SELECT b FROM App\Entity\FresBooking b WHERE ";
    //Buchungen finden: Buchung startet vor und endet nach dem Tag der angezeigt werden soll 
    $querystring .= "((b.itemstart <= :day_start and b.itemstop >= :day_end) or "; 
    //Buchungen finden: Buchung startet an oder und endet genau an dem Tag der angezeigt werden soll 
    $querystring .= "(b.itemstart >= :day_start and b.itemstart <= :day_end) or (b.itemstop >= :day_start and b.itemstop <= :day_end)) and "; 
    $querystring .= "b.clientid = :clientID and b.aircraftid = :planeID and b.status <> 'storniert' and b.status <> 'flugzeug_geloescht' and b.status <> 'user_geloescht' ORDER BY b.itemstart";
    $query = $em->createQuery($querystring)->setParameters(array('day_start' => $day_start, 
                                                                 'day_end' => $day_end, 
                                                                 'planeID' => $planeId, 
                                                                 'clientID' =>  $clientid));
    $query->setCacheable(true);
    return $query->getResult();
    
  }
  
  protected static function GetDayRange($booking, $int_year, $int_month, $day, $sHour, $sMinute, $eHour, $eMinute)
  {
    // ermittelt den Start und das Ende des Tages basierend auf einer Buchung und auf angegeben Start- und Endparametern
    date_default_timezone_set('Europe/Berlin');
    $daystart = new \DateTime();
    $daystart->setDate($int_year, $int_month, $day);
    $daystart->setTime($sHour, $sMinute, 0);
    $dayend = new \DateTime();
    $dayend->setDate($int_year, $int_month, $day);
    $dayend->setTime($eHour, $eMinute, 0);

    $start = clone $booking->getItemstart();
    //if ($start < $daystart) $start = $daystart;

    $stop = clone $booking->getItemstop();
    //if ($stop > $dayend) $stop = $dayend;    
    return array ($start, $stop);
    
  }
  

  public static function GetBookingsForAllPlanes ($em, $startdate, $int_duration, $clientid)
  {
    setlocale(LC_TIME, 'de_DE@euro', 'de_DE', 'deu_deu');
    
    $planes = $em->getRepository('App\Entity\FresAircraft')->findBy(array('clientid' => $clientid));
    if ($planes) 
    {
      foreach ($planes as $plane)
      {
        $current_date = clone $startdate;
        for ($i = 1; $i <= $int_duration; $i++) 
        {
          $time = $current_date->getTimestamp();
          $int_month = (int) date("n",$time);
          $int_year = (int) date("Y",$time);
          $int_day = (int) date("j",$time);
          
          $tooltip = "";
          $bookings = self::GetBookingsForOneDayAsObjects ($em, $int_day, $int_month, $int_year, $clientid, $plane->getId());
          //$color = 'frei';
          // In dieser Variable soll die Buchungszeit für einen Tag aufsummiert werden, die für die Fargebung genutzt wird
          $buchungsdauerProTag = 0;
          if ($bookings) 
          {
            //$color = 'reserviert';
            foreach ($bookings as $booking)
            {
              // mehrtägige Buchungen richtig berücksichtigen
              
              // Zunächst die Buchungszeit für die Farbe in der Montasübersicht berechnen
              // der Tag startet hier in Abhängigkeit der Flugplatzöffnungszeiten und von Sunrise und Sunset
              $temp_date = new \DateTime();
              $temp_date->setDate($int_year, $int_month, $int_day);
              $temp_date->setTime(0, 0, 0);
              $ds = TimeFunctions::GetDayStart($temp_date);
              $de = TimeFunctions::GetDayEnd($temp_date);
              
              $ds_de = Bookings::GetDayRange($booking, $int_year, $int_month, $int_day, $ds[0], $ds[1], $de[0], $de[1]);
                    
              $start = $ds_de[0];
              $stop = $ds_de[1];
              
              // Mehrtägige Buchungen berücksichtigen
              if ($start->format('Y-m-d') != $stop->format('Y-m-d'))
              {
                $daystart = new \DateTime();
                $daystart->setDate($int_year, $int_month, $int_day);
                $daystart->setTime($ds[0], $ds[1]);
                $dayend = new \DateTime();
                $dayend->setDate($int_year, $int_month, $int_day);
                $dayend->setTime($de[0], $de[1]);
                if ($temp_date->format('Y-m-d') == $start->format('Y-m-d'))
                {
                  // Buchung beginnt am angezeigten Datum
                  $buchungsdauerProTag += $dayend->getTimeStamp() - $start->getTimeStamp();
                }
                if ($temp_date->format('Y-m-d') == $stop->format('Y-m-d'))
                {
                  // Buchung endet am angezeigten Datum
                  $buchungsdauerProTag += $stop->getTimeStamp() - $daystart->getTimeStamp();
                }
                if ($temp_date->format('Y-m-d') != $start->format('Y-m-d') && $temp_date->format('Y-m-d') != $stop->format('Y-m-d'))
                {
                  // Buchung startet und endet nicht am angezeigten Datum
                  $buchungsdauerProTag += $dayend->getTimeStamp() - $daystart->getTimeStamp();
                }
              }  
              else 
              {
                // Buchungszeit zur Buchungsdauer für den Tag aufaddieren
                $buchungsdauerProTag += $stop->getTimeStamp() - $start->getTimeStamp();
              }
              
              // Jetzt die Anzeige für den Mouseover ermitteln
              // Hier gilt der Tag von 0:00 bis 23:59 Uhr
              $ds_de = Bookings::GetDayRange($booking, $int_year, $int_month, $int_day, 0, 0, 23, 59);
              $start = $ds_de[0];
              $stop = $ds_de[1];
                 
              // Fluglehrer mit zur Schulungsart aufnehmen
              $flightpurpose = FlightPurposes::GetFlightpurpose($em, $booking->getflightpurposeid());
              $isFlightTraining = FlightPurposes::IsSchulung($booking->getflightpurposeid());
              if ($isFlightTraining)
              {
                $flightpurpose = $flightpurpose . " / " . Users::GetUserName($em, $booking->getClientid(), $booking->getFlightinstructor());
              }
              
              $timestr = "";
              // Mehrtägige Buchungen berücksichtigen
              if ($start->format('Y-m-d') != $stop->format('Y-m-d'))
              {
                // Wenn es eine mehrtägige Buchunbg ist dann das komplette Datum anzeigen
                $timestr = '<font color="blue">' . date_format($start, 'd.m.Y H:i') . " - " . date_format($stop, 'd.m.Y H:i') . '</font>';
              }
              else
              {
                // Bei eintägigen Buchungen genügt Datum und Uhrzeit
                $timestr = date_format($start, 'H:i') . "-" . date_format($stop, 'H:i');
              }
              
              $tooltip .= $timestr
                       . " " . Users::GetUserName($em, $clientid, $booking->getCreatedbyuserid()) 
                       . " (" . $flightpurpose . ") "
                       . $booking->getdescription() . "<br>";
            }
          }
          $day = date('d-m-Y', mktime ( 0,0,0 ,$int_month ,$int_day, $int_year));
          // Wenn Tooltip gefüllt ist dann Flugzeugkennung und Datum voranstellen
          $date = new \DateTime();
          $date->setDate($int_year, $int_month, $int_day);
          $formatter = new \IntlDateFormatter('de_DE', \IntlDateFormatter::LONG, \IntlDateFormatter::NONE);
          $formatter->setPattern('eeee dd.MM.yyyy');
          $datestring = $formatter->format($date);
          // Wenn Tooltip gefüllt ist dann Flugzeugkennung und Datum voranstellen
          if ($tooltip != "") $tooltip = "<b>" . $plane->getKennung() . " " . $datestring . "</b><br>" . $tooltip;
          $tooltip = str_replace(array("\r\n", "\r"), "", $tooltip);
          
          // Fliegbare Tageszeit von Sonnenaufgang bis Sonnenuntergang berechnen
          $daylight = TimeFunctions::GetDaylight($int_month ,$int_day, $int_year);
          // Prozentuale Reservierungszeit basierend auf der Fliegbaren Zeit errechnen
          $prozentualeNutzung = (int) (100 / $daylight * $buchungsdauerProTag);
          
          // Vorbereitung für die Farbbestimmung
          if($prozentualeNutzung > 100) $prozentualeNutzung = 100;
          if($prozentualeNutzung < 0) $prozentualeNutzung = 0;
          $color_value = (int) ($prozentualeNutzung / 10);
          if ($prozentualeNutzung > 0 && $color_value == 0) $color_value = 1;
          
          // Farben je nach Buchungszeit festlegen
          switch ($color_value) {
            case 0: $color = 'leer'; break;
            case 1: $color = 'wenig'; break;
            case 2: $color = 'wenig'; break;
            case 3: $color = 'mittel'; break;
            case 4: $color = 'mittel'; break;
            case 5: $color = 'mittel'; break;
            case 6: $color = 'voll'; break;
            case 7: $color = 'voll'; break;
            case 8: $color = 'voll'; break;
            case 9: $color = 'sehrvoll'; break;
            case 10: $color = 'ausgebucht'; break;
          }
             
          $bookingList[] = array('plane' => $plane->getId(), 'day' => $int_day, 'bookingdate' => $day, 'color' => $color, 'tooltip' => $tooltip);
          $current_date->modify('+1 day');
        }
      }
    }
    if (isset($bookingList)) return $bookingList;
      else return NULL;
  }
  
  public static function GetBookingsForPlaneAndDate ($em, $int_day, $int_month, $int_year, $clientid, $planeId)
  {
    $bookingList = null;
    $bookings = self::GetBookingsForOneDayAsObjects ($em, $int_day, $int_month, $int_year, $clientid, $planeId);
    if ($bookings) 
    {
      $start = new \DateTime();
      $end = new \DateTime();
      foreach ($bookings as $booking)
      {
        $start->setTimeStamp($booking->getItemstart()->getTimeStamp());
        $end->setTimeStamp($booking->getItemstop()->getTimeStamp());
        
        if ($booking->getItemstart()->getTimeStamp() < mktime ( 6,0,0 ,$int_month ,$int_day, $int_year))
        {
          $start->setTimeStamp(mktime (6, 0, 0 ,$int_month ,$int_day, $int_year));
        }
        if ($booking->getItemstop()->getTimeStamp() > mktime ( 21,30,0 ,$int_month ,$int_day, $int_year))
        {
          $end->setTimeStamp(mktime (21, 30, 0 ,$int_month ,$int_day, $int_year)); 
        }
       
        $isFlightTraining = FlightPurposes::IsSchulung($booking->getflightpurposeid());
        if ($isFlightTraining) $flightinstructor = Users::GetUserName($em, $booking->getClientid(), $booking->getFlightinstructor());
          else $flightinstructor = '';
         
          
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
      }
    }
    return $bookingList;
  }
  
  public static function GetBookingTimes ($int_day, $int_month, $int_year)
  {
    for ($i = 360; $i <= 1230; $i += 30) 
    {
      $item_start = date('H : i', mktime (0,$i,0 ,$int_month, $int_day, $int_year));
      $bookingTimes[] = array('time' => $item_start, 'Y-m-d H:i');
    }
    return $bookingTimes;
  }
  
  public static function CountAllBookingsForAPlane ($em, $clientid, $planeID)
  {
    $day_start = date('Y-m-d H:i:s', mktime ( 0,0,0 , date("m"), date("j"), date("Y")));
    $querystring = "SELECT COUNT(a.id) FROM App\Entity\FresBooking a WHERE a.clientid = :clientID and a.aircraftid = :planeID and a.itemstart < :day_start and a.status <> 'storniert' and a.status <> 'flugzeug_geloescht' and a.status <> 'user_geloescht'";
    $query = $em->createQuery($querystring)->setParameters(array('clientID' =>  $clientid, 'planeID' => $planeID, 'day_start' => $day_start));
    $past = $query->getSingleScalarResult();
    
    $querystring = "SELECT COUNT(a.id) FROM App\Entity\FresBooking a WHERE a.clientid = :clientID and a.aircraftid = :planeID and a.itemstart >= :day_start and a.status <> 'storniert' and a.status <> 'flugzeug_geloescht' and a.status <> 'user_geloescht'";
    $query = $em->createQuery($querystring)->setParameters(array('clientID' =>  $clientid, 'planeID' => $planeID, 'day_start' => $day_start));
    $future = $query->getSingleScalarResult();
    
    return array ('past' => $past, 'future' => $future);
  }
  
  public static function CountAllBookingsForAUser ($em, $clientid, $userID)
  {
    $day_start = date('Y-m-d H:i:s', mktime ( 0,0,0 , date("m"), date("j"), date("Y")));
    $querystring = "SELECT COUNT(a.id) FROM App\Entity\FresBooking a WHERE a.clientid = :clientID and a.createdbyuserid = :userid and a.itemstart < :day_start and a.status <> 'storniert' and a.status <> 'flugzeug_geloescht' and a.status <> 'user_geloescht'";
    $query = $em->createQuery($querystring)->setParameters(array('clientID' =>  $clientid, 'userid' => $userID, 'day_start' => $day_start));
    $past = $query->getSingleScalarResult();
    
    $querystring = "SELECT COUNT(a.id) FROM App\Entity\FresBooking a WHERE a.clientid = :clientID and a.createdbyuserid = :userid and a.itemstart >= :day_start and a.status <> 'storniert' and a.status <> 'flugzeug_geloescht' and a.status <> 'user_geloescht'";
    $query = $em->createQuery($querystring)->setParameters(array('clientID' =>  $clientid, 'userid' => $userID, 'day_start' => $day_start));
    $future = $query->getSingleScalarResult();
    
    return array ('past' => $past, 'future' => $future);
  }
  
  public static function DeleteBooking ($em, $clientid, $id)
  {
    $booking = $em->getRepository('App\Entity\FresBooking')->findOneBy(array('clientid' => $clientid, 'id' => $id));
    if ($booking)
    {
      $booking->setStatus('storniert');
      $em->persist($booking);
      $em->flush();
    }
  }
  
  public static function DeleteAllBookingsForAPlane ($em, $clientid, $planeID)
  {
    /*
    $querystring = "DELETE App\Entity\FresBooking a WHERE a.clientid = :clientID and a.aircraftid = :planeID";
    $query = $em->createQuery($querystring)->setParameters(array('clientID' => $clientid, 'planeID' => $planeID));
    $query->execute();
    */
    $querystring = "UPDATE App\Entity\FresBooking a SET a.status = 'flugzeug_geloescht' WHERE a.clientid = :clientID and a.aircraftid = :planeID";
    $query = $em->createQuery($querystring)->setParameters(array('clientID' => $clientid, 'planeID' => $planeID));
    $query->execute();
  }
  
  public static function DeleteAllBookingsForAUser ($em, $clientid, $userID)
  {
    //$querystring = "DELETE App\Entity\FresBooking a WHERE a.clientid = :clientID and a.createdbyuserid = :userID";
    //$query = $em->createQuery($querystring)->setParameters(array('clientID' => $clientid, 'userID' => $userID));
    //$query->execute();
    $querystring = "UPDATE App\Entity\FresBooking a SET a.status = 'user_geloescht' WHERE a.clientid = :clientID and a.createdbyuserid = :userID";
    $query = $em->createQuery($querystring)->setParameters(array('clientID' => $clientid, 'userID' => $userID));
    $query->execute();
  }
  
  public static function GetBookingContent ($booking, $em, $user, $prefix = '')
  {
    setlocale(LC_TIME, 'de_DE.UTF-8', 'de_DE@euro', 'de_DE', 'de', 'ge');
    if ($booking)
    {
      $owner = Users::GetUserObject($em, $booking->getClientid(), $booking->getCreatedbyuserid());
      if ($owner)
      {
        $telhome = $owner->getPhoneNumberHome();
        $teloffice = $owner->getPhoneNumberOffice();
        $telmobile = $owner->getPhoneNumberMobile();
      }
      else 
      {
        $telhome = ''; $teloffice = ''; $telmobile = '';
      }
      
      $emailInfoIntern = $booking->getEmailinfoi();
      //$emailInfoIntern = str_replace(',', ' ', $booking->getEmailinfoi());
      $emailInfoExtern = $booking->getEmailinfoe();
      //$emailInfoExtern = str_replace(',', ' ', $booking->getEmailinfoe());
      // Changedate könnte leer sein, wenn die Buchung noch nie geändert wurde
      $changedate = $booking->getChangeddate();
      if (!empty($changedate)) $changedate = date_format($changedate, 'd.m.Y H:i');
      
      if (Bookings::IsAllowedtoChangeBooking ($em, $user, Bookings::GetBookingObject ($em, $booking->getClientid(), $booking->getId()))) $modify = TRUE;
        else $modify = FALSE; 
      
      $formatter = new \IntlDateFormatter('de_DE', \IntlDateFormatter::LONG, \IntlDateFormatter::NONE);
      $formatter->setPattern('eeee dd.MM.yyyy HH:mm');
      $datestart = $formatter->format($booking->getItemstart());
      $dateend = $formatter->format($booking->getItemstop());
      
      $bookingList = array ($prefix . 'id' => $booking->getId(),
                            $prefix . 'flugzeug' => Planes::GetPlaneNameAndKennung($em, $booking->getClientid(), $booking->getAircraftid()),
                            $prefix . 'flightinstructor' => Users::GetUserName($em, $booking->getClientid(), $booking->getFlightinstructor()),
                            $prefix . 'airfield' => Airfields::GetAirfield($em, $booking->getAirfieldid()),
                            $prefix . 'flightpurpose' => FlightPurposes::GetFlightpurpose($em, $booking->getflightpurposeid()), 
                            $prefix . 'ReservedForUser' => Users::GetUserName($em, $booking->getClientid(), $booking->getCreatedbyuserid()),
                            $prefix . 'ReservedAt' => date_format($booking->getCreateddate(), 'd.m.Y H:i'),
                            $prefix . 'ChangedFromUser' => Users::GetUserName($em, $booking->getClientid(), $booking->getChangedbyuserid()),
                            $prefix . 'ChangedAt' => $changedate,
                            $prefix . 'start' => $datestart,
                            $prefix . 'end' => $dateend,
                            $prefix . 'telhome' => $telhome,
                            $prefix . 'teloffice' => $teloffice,
                            $prefix . 'telmobile' => $telmobile,
                            $prefix . 'mail' => Users::GetUserMailaddress($em, $booking->getClientid(), $booking->getCreatedbyuserid()),
                            $prefix . 'description' => $booking->getDescription(),
                            $prefix . 'EmailInfoIntern' => $emailInfoIntern,
                            $prefix . 'EmailInfoExtern' => $emailInfoExtern,
                            $prefix . 'modify' => $modify);
    }    
    return $bookingList;
  }
  
  public static function GetBookingDetails ($em, $clientid, $id, $user)
  {
    
    $booking = $em->getRepository('App\Entity\FresBooking')->findOneBy(array('clientid' => $clientid, 'id' => $id));
    if ($booking)
    {
      return self::GetBookingContent($booking, $em, $user);
    }
    return NULL;
  }
  
  public static function SendBookingsInfoMail($em, $user, $twig, $newbooking, $oldbooking, $mailer, $parameter)
  {
    $clientId = '';
    
    if ($newbooking && $oldbooking) {
      $type = 'Änderung einer Reservierung'; 
      $clientId = $newbooking->getClientid();
      $datanew = Bookings::GetBookingContent($newbooking, $em, $user, 'primary_');
      $dataold = Bookings::GetBookingContent($oldbooking, $em, $user, 'secondary_');
    }
    if ($newbooking && !$oldbooking) {
      $type = 'Neue Reservierung'; 
      $clientId = $newbooking->getClientid();
      $datanew = Bookings::GetBookingContent($newbooking, $em, $user, 'primary_');
      $dataold = array();
    }
    if (!$newbooking && $oldbooking) {
      $type = 'Stornierung einer Resevierung'; 
      $clientId = $oldbooking->getClientid();
      $datanew = array();
      $dataold = Bookings::GetBookingContent($oldbooking, $em, $user, 'primary_');
    }
    
    // Ermittelte > Daten zusammenführen
    $data = array_merge($datanew, $dataold);
    $data = array_merge($data, array('type' => $type));
    
    // Ermittelte > Daten zusammenführen
    $data = array_merge($datanew, $dataold);
    $data = array_merge($data, array('type' => $type));
    
    // Absender der Mail festlegen, zunächst für die ReplyTo Adresse
    Users::GetUserReplyToMailaddress($user, $sender_mail, $sender_name);
    // Und nun nochmal um die den Absender selber zu informieren
    Users::GetUserReplyToMailaddress($user, $inform_sender_mail, $sender_name, Users::const_Buchungsmail);
    
    // Mailadressaten festlegen
    $mailIntern = array (); $mailExtern = array (); $adminMails = array(); $mailNewOwner = array(); $mailOldOwner = array();
    // Alle Administratoren informieren
    $adminMails = Users::GetAllAdminMailaddresses($em, $clientId, Users::const_Buchungsmail);
    
    // Alle internen Mailadresseen ermitteln
    if (isset($datanew['primary_' . 'EmailInfoIntern']) && trim($datanew['primary_' . 'EmailInfoIntern'], ' ,')) 
      $mailIntern = explode(",", trim($datanew['primary_' . 'EmailInfoIntern'], ' ,'));
    // Alle externen Mailadressen ermitteln
    if (isset($datanew['primary_' . 'EmailInfoExtern']) && trim($datanew['primary_' . 'EmailInfoExtern'], ' ,')) 
      $mailExtern = explode(",", trim($datanew['primary_' . 'EmailInfoExtern'], ' ,'));
    
    // alten und neuen Nutzer informieren
    if ($newbooking) $mailNewOwner = Users::GetUserMailaddress($em, $newbooking->getClientid(), $newbooking->getCreatedbyuserid(), Users::const_Buchungsmail);
      else $mailNewOwner = '';
    if ($oldbooking) $mailOldOwner = Users::GetUserMailaddress($em, $oldbooking->getClientid(), $oldbooking->getCreatedbyuserid(), Users::const_Buchungsmail);
      else $mailOldOwner = '';  
      
    // alten und neuen Fluglehrer informieren
    if ($newbooking && $newbooking->getFlightinstructor() != NULL) $mailNewFI = Users::GetUserMailaddress($em, $newbooking->getClientid(), $newbooking->getFlightinstructor(), Users::const_Buchungsmail);
      else $mailNewFI = '';
    if ($oldbooking && $oldbooking->getFlightinstructor() != NULL) $mailOldFI = Users::GetUserMailaddress($em, $oldbooking->getClientid(), $oldbooking->getFlightinstructor(), Users::const_Buchungsmail);
      else $mailOldFI = '';    
    
    // Mail-Arrays zusammenführen
    $mails = array_merge($adminMails, $mailIntern);
    $mails = array_merge($mails, $mailExtern);
    // Der oder die Absender ehalten selbst eine Kopie der Mail
    $mails = array_merge($mails, array ($inform_sender_mail));
    
    $mails = array_merge($mails, array($mailNewOwner));
    $mails = array_merge($mails, array($mailOldOwner));
    $mails = array_merge($mails, array($mailNewFI));
    $mails = array_merge($mails, array($mailOldFI));
    // Doppelte Array-Einträge löschen
    $mails = array_unique($mails);
    
    //Mails versenden
    foreach ($mails as $mail) 
    {
      if (Users::IsMailAdressValid($mail))
      {
        $message = (new Email()) 
          ->subject($type . ' ' . $parameter['program_version'])
          ->html($twig->render('emails/bookingmail.html.twig', $data))
          ->replyTo(new Address($sender_mail, $sender_name))
          ->from($parameter['mail_from'])
          ->to($mail);
        try {
         $mailer->send($message);
        }
        catch (\Exception $e) {
          // 09.09.22 - hier muss noch etwas codiert werden
        }
      }
    }
  }
}