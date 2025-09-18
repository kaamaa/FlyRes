<?php

namespace App;

use DateTime;
use App\SessionData;
use App\Logging;

use Symfony\Component\HttpKernel\Bundle\Bundle;

class ViewHelper
{
  public static function IsDateCorrect ($date)
  {
    if ($date == false) return false;
    // Wenn das Datum nicht gültig ist, wurde es durch Symfony bereits auf Null gesetzt
    if (isset($date) && ($date instanceof DateTime))
    {
      // Falles nicht null ist, trotzdem nochmals mit checkdate überprüfen
      return checkdate($date->format('m'), $date->format('d'), $date->format('Y'));
    }
    return FALSE;
  }
  
  public static function GetAllDaysOfWeeksForHeader($int_day, $int_month, $int_year, $duration)
  {
    // Hilfsfunktion für die Wochenansicht
    // Gibt den Wochentag (Mo, Di..), den Tag und den Monat (Jan,Feb..) sowie eine Markierung für 
    // Wochenenden zurück so dass im template Samstag und Sonntag anders gefärbt werden können
    $tage = array("So", "Mo", "Di", "Mi", "Do", "Fr", "Sa");
    $monate=array(1=>"Januar","Februar","März","April","Mai","Juni","Juli","August","September","Oktober","November","Dezember");
        
    $dates = array();

    $current_date = mktime ( 0,0,0 ,$int_month, $int_day, $int_year);
    $max_date = strtotime($duration, $current_date);
    
    while ($current_date < $max_date) 
    {
      
      switch(date('w', $current_date))
      {
          case 0 : 
          case 6 : $bgcolor = 'textWE'; break;
          default; $bgcolor = 'TextWT'; break;
      }
      $dates[] = array('wochentag' => $tage[date('w', $current_date)], 'tag' => date('d', $current_date), 
                       'monat' => mb_substr($monate[date('n', $current_date)], 0 , 3), 'color' => $bgcolor);
      $current_date = strtotime('+1 day', $current_date);
    }

    return $dates;
    
  }
  
  public static function GetAllMonthForHeader($int_year)
  {
    setlocale(LC_TIME, 'de_DE.UTF-8', 'de_DE@euro', 'de_DE', 'de', 'ge'); // deutsche Ausgabe der Monate erzwingen
    for ($i = 1; $i <= 12; $i++) 
    {
      $date = new \DateTime();
      $date->setTimestamp(mktime ( 0,0,0,$i ,1, $int_year));
      $formatter = new \IntlDateFormatter('de_DE', \IntlDateFormatter::LONG, \IntlDateFormatter::NONE);
      $formatter->setPattern('MM');
      $index = $formatter->format($date);
      $formatter->setPattern('MMM');
      $tag = $formatter->format($date);
      $formatter->setPattern('MMMM');
      $taglong = $formatter->format($date);
      $monate[] = array('index' => $index, // "01"
                        'tag' => $tag,  // "Jan"
                        'taglong' => $taglong); // "Januar"
    }
    return $monate;
  }
  
  public static function GetMaxDaysPerMonth($day, $month, $year)
  {
    return date("t",mktime(0, 0, 0, (int) $month, (int) $day, (int) $year));
  }

  public static function GetAllDaysOfMonthForHeader($int_month, $int_year)
  {
    // Maximal Anzahl der Tage pro Monat ermitteln
    $days_max = self::GetMaxDaysPerMonth(1, $int_month, $int_year);
    // Hintergundfarben für die Tabellenelemente festlegen
    for ( $day = 1 ; $day <= $days_max ; $day++ )
    {
      switch(date( "w", mktime ( 0,0,0,$int_month ,$day, $int_year) ))
      {
          case 0 : $bgcolor = 'sonntag'; break;
          case 6 : $bgcolor = 'samstag'; break;
          default; $bgcolor = 'wochentag'; break;
      }
      $monat[] = array('tag' => $day, 'color' => $bgcolor);
    }
    return $monat;
  }
  
  public static function GetActualMonthForHeader ($str_month, $str_year)
  {
    $monate = self::GetAllMonthForHeader((int) $str_year);
    foreach ($monate as $monat) {
      if (strcmp($monat['index'], $str_month) == 0) return $monat['taglong'] . " " . $str_year;
    }
    return 'Fehler';
  }
  
  public static function GetNextMonthButtonTag ($int_day, $int_month, $int_year)
  {
    return date('mY', mktime ( 0,0,0 ,$int_month+1, 1, $int_year));
  }
  
  public static function GetPrevMonthButtonTag ($int_day, $int_month, $int_year)
  {
    return date('mY', mktime ( 0,0,0 ,$int_month-1, 1, $int_year));
  }
  
  public static function GetNextWeekButtonTag ($int_day, $int_month, $int_year)
  {
    $date = mktime ( 0,0,0 ,$int_month, $int_day, $int_year);
    $new_date = strtotime('+2 weeks', $date);
    return date('dmY', $new_date);
  }
  
  public static function GetPrevWeekButtonTag ($int_day, $int_month, $int_year)
  {
    $date = mktime ( 0,0,0 ,$int_month, $int_day, $int_year);
    $new_date = strtotime('-2 weeks', $date);
    return date('dmY', $new_date);
  }
  
  public static function GetNextDayButtonTag ($int_day, $int_month, $int_year)
  {
    return date('d-m-Y', mktime ( 0,0,0 ,$int_month, $int_day+1, $int_year));
  }
  
  public static function GetPrevDayButtonTag ($int_day, $int_month, $int_year)
  {
    return date('d-m-Y', mktime ( 0,0,0 ,$int_month, $int_day-1, $int_year));
  } 
  
  public static function GetBookingID($request)
  {
    $sd = ViewHelper::GetSessionDataObject($request->getSession());
    
    if($request->request->has('ts')) 
    {
      // BookingID aus dem Request ermitteln
      $bookingID = intval($request->get('ts'));
      $sd->SetBookingID($bookingID);
      ViewHelper::StoreSessionDataObject($request->getSession(), $sd);
    }
    else
    {
      // BookingID aus dem Sessionstore ermitteln
      $bookingID = $sd->GetBookingID();
    }
    return $bookingID;
  }
  
  public static function SetBookingID($request, $bookingID)
  {
    $sd = ViewHelper::GetSessionDataObject($request->getSession());
    $sd->SetBookingID($bookingID);
    ViewHelper::StoreSessionDataObject($request->getSession(), $sd);
  } 
  
  public static function SetBookingData($request, $booking)
  {
    if ($booking)
    {
      $sd = ViewHelper::GetSessionDataObject($request->getSession());
      if($sd)
      {
        $sd->SetBookingID($booking->getId());
        $sd->SetDate($booking->getItemstart(), SessionData::day);
        $sd->SetPlaneID($booking->getAircraftid());

        ViewHelper::StoreSessionDataObject($request->getSession(), $sd);
      }
    }
  } 
  
  public static function GetSessionDataObject($session)
  {
    $sd = unserialize ($session->get('SessionDataObject'));
    if (!SessionData::IsValid($sd)) 
    {
      $sd = new SessionData (date("Y"), date("m"), date("j"));
      ViewHelper::StoreSessionDataObject($session, $sd);
    }
    return $sd; 
  }
  
  public static function StoreSessionDataObject($session, $sd)
  {
    $session->set('SessionDataObject', serialize($sd));
  }
  
  public static function GetClientId($request)
  {
    $sd = ViewHelper::GetSessionDataObject($request->getSession());
    return $sd->GetClientID();
  }
  
  public static function GetFIAvailabiltyCommand($request)
  {
    $sd = ViewHelper::GetSessionDataObject($request->getSession());
    if($sd) 
    {
       return $sd->GetFIAvailabiltyCommand(); 
    }
    return null;
  }
  
  public static function SetFIAvailabiltyCommand($request, string $command)
  {
    $sd = ViewHelper::GetSessionDataObject($request->getSession());
    $sd->SetFIAvailabiltyCommand($command);
    ViewHelper::StoreSessionDataObject($request->getSession(), $sd);
  } 
  
}