<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use App\Entity\ToolsCountry;
use App\Entities\Users;
use App\Repository\ToolsCountryRepository;
use App\Repository\ToolsAirportRepository;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use App\ViewHelper;
use App\SessionData;
use DateTimeZone;
use DateTime;

class Sunrise_SunsetController extends AbstractController
{ 
  const DateFormat = 'MM.yyyy';

  function parseTimezone($timezone) 
  {
    // Die Funktion zerlegt einen String im Muster 'UTC+4:30(+5:30DT)' in seine Bestandteile
    $pattern = '/UTC([+-]\d{1,2}(:\d{2})?)(\(([+-]\d{1,2}(:\d{2})?)DT\))?/';
    if (preg_match($pattern, $timezone, $matches)) {
        $offset = $matches[1];
        $dst = isset($matches[4]) ? $matches[4] : null;
        return [$offset, $dst];
    }
    return [null, null];
  }
  
  function calculateOffsetInMinutes($offset) 
  {
    $sign = $offset[0];
    $offset = substr($offset, 1);
    if (strpos($offset, ':') !== false) {
        list($hours, $minutes) = explode(':', $offset);
    } else {
        $hours = $offset;
        $minutes = 0;
    }
    $hours = (int)$hours;
    $minutes = (int)$minutes;
    $totalMinutes = $hours * 60 + $minutes;
    return $sign === '-' ? -$totalMinutes : $totalMinutes;
  }
  
  function getTimezoneOffsets($timezone) 
  {
    // Die Funktion berechnet die Standard- und Sommerzeit-Offsets in Minuten
    list($offset, $dst) = $this->parseTimezone($timezone);
    if ($offset !== null) {
        $standardOffsetMinutes = $this->calculateOffsetInMinutes($offset);
        $dstOffsetMinutes = $dst ? $this->calculateOffsetInMinutes($dst) : null;
        $return = [
        'standard' => $standardOffsetMinutes,
        'daylight' => $dstOffsetMinutes !== null ? $dstOffsetMinutes : null,
    ];
        return $return;
    }
    return null;
  }

  protected function decimalToDMS($decimal, $isLatitude = true) 
  {
      // DErzeigt das Ausgabeformat für die Titelzeile der Tabelle
      // Bestimmen des Vorzeichens
      $sign = $decimal < 0 ? -1 : 1;
      $decimal = abs($decimal);
  
      // Berechnung der Grad, Minuten und Sekunden
      $degrees = floor($decimal);
      $minutes = floor(($decimal - $degrees) * 60);
      $seconds = ($decimal - $degrees - $minutes / 60) * 3600;
  
      // Wendet das Vorzeichen auf die Grad an - brauchen wir nicht, da wir N/S bzw. E/W verwenden
      //$degrees = $degrees * $sign;
  
      // Bestimmen der Himmelsrichtung
      if ($isLatitude) {
          $direction = $sign > 0 ? 'N' : 'S';
      } else {
          $direction = $sign > 0 ? 'E' : 'W';
      }
  
      return sprintf("%d° %d' %0.3f\" %s", $degrees, $minutes, $seconds, $direction);
  }
  

  public function convertToDecimal($s_coordinate) 
  {
    // Konvertiert die Koordinaten aus der Datenbank in das Format das in den Berechungen verwendet werden kann
    // Machnmal befinden sich "/" in den Koordinaten
    $coordinate = str_replace('/', '0', $s_coordinate);
    // Extrahiert das Himmelsrichtungszeichen
    $direction = substr($coordinate, 0, 1);

    // Extrahiert die Grad-, Minuten- und Sekundenkomponenten
    $length = strlen($coordinate);
    if ($length == 10) {
        // Längengerade (Longitude) haben in der Datenbank eine Länge von 10 Zeichen
        $degrees = substr($coordinate, 1, 3);
        $minutes = substr($coordinate, 4, 2);
        $seconds = substr($coordinate, 6, 2) . '.' . substr($coordinate, 8, 2);
        
    } else { // length == 9
        // Breitengerade (Latitude) haben in der Datebank eine Länge von 9 Zeichen
        $degrees = substr($coordinate, 1, 2);
        $minutes = substr($coordinate, 3, 2);
        $seconds = substr($coordinate, 5, 2) . '.' . substr($coordinate, 7, 2);
    }

    // Konvertiert die Komponenten in Dezimalgrad
    $decimal = $degrees + ($minutes / 60) + ($seconds / 3600);

    // Wendet das Vorzeichen entsprechend der Himmelsrichtung an
    if ($direction == 'S' || $direction == 'W') {
        $decimal *= -1;
    }

    return $decimal;
  }

  function getUtcOffsetFormat($timezone)
  {
      // Die Funktion gibt das Format für die UTC-Offset-Anzeige basierend auf der PHP Zeitzone zurück
      // (z.B. UTC+1(+2DT))  

      $dateTime = new DateTime();
      $timezoneObject = new DateTimeZone($timezone);
  
      // Standardzeit (ohne Sommerzeit)
      $transitions = $timezoneObject->getTransitions();
      $stdOffset = null;
      $dstOffset = null;
  
      foreach ($transitions as $transition) {
          if ($transition['isdst'] === false) {
              $stdOffset = $transition['offset'];
          } elseif ($transition['isdst'] === true) {
              $dstOffset = $transition['offset'];
          }
          if ($stdOffset !== null && $dstOffset !== null) {
              break;
          }
      }
  
      // Umwandlung der Sekunden in Stunden
      $stdOffsetHours = $stdOffset !== null ? $stdOffset / 3600 : 0;
      $dstOffsetHours = $dstOffset !== null ? $dstOffset / 3600 : $stdOffsetHours;
  
      $format = sprintf("(UTC%+d(%+dDT))", $stdOffsetHours, $dstOffsetHours);
  
      return $format;
  }

  
  function hasDst($timezone)
  {
    /**
     * Funktion zur Überprüfung, ob eine gegebene Zeitzone jemals Sommerzeit (DST) verwendet.
     *
     * @param string $timezone Die PHP-Zeitzone.
     * @return bool Gibt true zurück, wenn die Zeitzone jemals DST verwendet, andernfalls false.
     */

    // Erstelle ein DateTimeZone-Objekt für die angegebene Zeitzone.
    $timezoneObject = new DateTimeZone($timezone);

    // Hole alle Übergänge (Transitions) für die Zeitzone.
    $transitions = $timezoneObject->getTransitions();

    // Schleife durch alle Übergänge.
    foreach ($transitions as $transition) {
        // Überprüfe, ob der Übergang DST (Sommerzeit) verwendet.
        if ($transition['isdst']) {
            // Wenn DST gefunden wird, gebe true zurück.
            return true;
        }
    }

    // Wenn kein DST gefunden wird, gebe false zurück.
    return false;
  }


protected function generateMonthlyTable($date, $decimalLatitude, $decimalLongitude, $timezone, $offsets, $offsetstr) 
{
    // Setze die Locale-Einstellung auf Deutsch
    setlocale(LC_TIME, 'de_DE.UTF-8');
    
    // Erstelle ein DateTime-Objekt vom ersten Tag des Monats
    $date = new \DateTime($date->format('Y-m-01'));
    // Holen des letzten Tages im Monat
    $endOfMonth = (clone $date)->modify('last day of this month');

    $offsetstr = $this->getUtcOffsetFormat($timezone);
    
    // Erzeugen des HTML-Tabellen-Starts
    $html = '<style> .hp { padding-left: 5px; padding-right: 5px; } .th { text-align: center; } </style>';
    $html .= '<table border="1">';
    $html .= '<tr>';
    $html .= '<th class="th"colspan="2">Datum / Date</th>';  
    $html .= '<th class="th"colspan="2">UTC</th>';
    $html .= '<th class="th"colspan="6">MEZ/MESZ (UTC+1(+2DT))</th>';
    $html .= '<th class="th"colspan="3">Local ' . $offsetstr . '</th>';
    $html .= '</tr>';
  
    $html .= '<tr>';
    $html .= '<td class="hp"><strong>Date</strong></td>';
    $html .= '<td class="hp"><strong>Weekday</strong></td>';
    $html .= '<td class="hp"><strong>Sunrise</strong></td>';
    $html .= '<td class="hp"><strong>Sunset</strong></td>';
    $html .= '<td class="hp"><strong>Civil Dawn</strong></td>';
    $html .= '<td class="hp"><strong>Sunrise</strong></td>';
    $html .= '<td class="hp"><strong>Sunset</strong></td>';
    $html .= '<td class="hp"><strong>Civil Dusk</strong></td>';
    $html .= '<td class="hp"><strong>Day length</strong></td>';
    $html .= '<td class="hp"><strong>DST</strong></td>';
    $html .= '<td class="hp"><strong>Sunrise</strong></td>';
    $html .= '<td class="hp"><strong>Sunset</strong></td>';
    $html .= '<td class="hp"><strong>DST</strong></td>';
    $html .= '</tr>';
    
    // Schleife durch alle Tage des Monats
    while ($date <= $endOfMonth) 
    {
        // Bestimmen des Wochentags
        $weekdayEnglish = $date->format('l'); // Englisch
        $weekdayGerman = strftime('%A', $date->getTimestamp()); // Deutsch

        // Sonnenaufgangs- und Sonnenuntergangszeiten für UTC
        //$zenith = 90.5; // Das ist der richtige Wert, um mit den Wetterapps vergleichbar zu sein
        //$sunrise1 = date_sunrise($date->getTimestamp(), SUNFUNCS_RET_TIMESTAMP, $decimalLatitude, $decimalLongitude, $zenith, 0); 
        //$sunset1 = date_sunset($date->getTimestamp(), SUNFUNCS_RET_TIMESTAMP, $decimalLatitude, $decimalLongitude, $zenith, 0);

        $sunInfo = date_sun_info($date->getTimestamp(), $decimalLatitude, $decimalLongitude);
        $civilTwilightBegin1 = $sunInfo['civil_twilight_begin'];
        $civilTwilightEnd1 = $sunInfo['civil_twilight_end'];
        $sunrise1 = $sunInfo['sunrise'];
        $sunset1 = $sunInfo['sunset'];

        // MEZ / MESZ - Sommerzeit / Winterzeit
        $MEZdst = "";
        $MEZDate = clone $date;
        $berlinTimezone = new DateTimeZone('Europe/Berlin'); 
        $MEZDate->setTimezone($berlinTimezone);
        $isDST = $MEZDate->format('I'); // 1 für Sommerzeit, 0 für Winterzeit
        if ($this->hasDst('Europe/Berlin') )
        {  
          if ($isDST) {
            $MEZdst = "Summer time";
          } else {
            $MEZdst = "Winter time";
          }
        } else 
        { 
          $MEZdst = "N/A"; 
        }

        // Lokale Zeit
        $Locdst = "";
        $LocDate = clone $date;
        $LocTimezone = new DateTimeZone($timezone); 
        $LocDate->setTimezone($LocTimezone);
        $isDST = $LocDate->format('I'); // 1 für Sommerzeit, 0 für Winterzeit
        if ($this->hasDst($timezone) )
        {
          if ($isDST) {
            $Locdst = "Summer time";
          } else {
            $Locdst = "Winter time";
          }
        } else 
        { 
          $Locdst = "N/A"; 
        }

        if (($sunrise1 === false && $sunset1 === false) or ($sunrise1 === true && $sunset1 === true)) 
        {
          if ($sunrise1 === false && $sunset1 === false) 
          { 
            $sunriseUtc1 = 'Polar Night';
            $sunsetUtc1 = 'Polar Night';
            $civilTwilightBeginUtcPlus1 = 'Polar Night';
            $sunriseUtcPlus1 = 'Polar Night';
            $sunsetUtcPlus1 = 'Polar Night';
            $civilTwilightEndUtcPlus1 = 'Polar Night';
            $dayLength1 = 'Polar Night';
            $dst = '';
            $sunriseCustomStandard = 'Polar Night';
            $sunsetCustomStandard = 'Polar Night';
          }
          if ($sunrise1 === true && $sunset1 === true) 
          { 
            $sunriseUtc1 = 'Polar Day';
            $sunsetUtc1 = 'Polar Day';
            $civilTwilightBeginUtcPlus1 = 'Polar Day';
            $sunriseUtcPlus1 = 'Polar Day';
            $sunsetUtcPlus1 = 'Polar Day';
            $civilTwilightEndUtcPlus1 = 'Polar Day';
            $dayLength1 = 'Polar Day';
            $dst = '';
            $sunriseCustomStandard = 'Polar Day';
            $sunsetCustomStandard = 'Polar Day';
          }
        }
        else 
        {
          
          // Länge des Tages
          $dayLength1 = gmdate('H:i', $sunset1 - $sunrise1);

          // UTC 
          $sunriseUtc1 = date('H:i', $sunrise1);
          $sunsetUtc1 = date('H:i', $sunset1);
          
         
          // MEZ / MESZ 
          $MEZtimezoneOffset1 = $MEZDate->getOffset();
          $sunriseUtcPlus1 = date('H:i', $sunrise1 + $MEZtimezoneOffset1);
          $sunsetUtcPlus1 = date('H:i', $sunset1 + $MEZtimezoneOffset1);
          $civilTwilightBeginUtcPlus1 = date('H:i', $civilTwilightBegin1 + $MEZtimezoneOffset1);
          $civilTwilightEndUtcPlus1 = date('H:i', $civilTwilightEnd1 + $MEZtimezoneOffset1);

          // Lokalzeit
          $LocOffsetSeconds = $LocDate->getOffset();
          $sunriseCustomStandard = date('H:i', $sunrise1 + $LocOffsetSeconds);
          $sunsetCustomStandard = date('H:i', $sunset1 + $LocOffsetSeconds);

        }

        // Hintergrundfarbe für Wochenenden und das aktuelle Datum
        $currentDate = new \DateTime('now'); 
        if ($date->format('Y-m-d') == $currentDate->format('Y-m-d')) {
            $backgroundColor = 'style="background-color: #ffcccb;"'; // Rot für das aktuelle Datum
        } elseif ($date->format('N') >= 6) {
            $backgroundColor = 'style="background-color: #f0e68c;"'; // Gelb für Wochenenden
        } else {
            $backgroundColor = '';
        }
        
        $html .= '<tr ' . $backgroundColor . '>';
        $html .= '<td>' . $date->format('d.m.Y') . '</td>';
        $html .= '<td>' . $weekdayEnglish . '</td>';
        $html .= '<td>' . $sunriseUtc1 . '</td>';
        $html .= '<td>' . $sunsetUtc1 . '</td>';
        $html .= '<td>' . $civilTwilightBeginUtcPlus1 . '</td>';
        $html .= '<td>' . $sunriseUtcPlus1 . '</td>';
        $html .= '<td>' . $sunsetUtcPlus1 . '</td>';
        $html .= '<td>' . $civilTwilightEndUtcPlus1 . '</td>';
        $html .= '<td>' . $dayLength1 . '</td>';
        $html .= '<td>' . $MEZdst . '</td>';
        $html .= '<td>' . $sunriseCustomStandard . '</td>';
        $html .= '<td>' . $sunsetCustomStandard . '</td>';
        $html .= '<td>' . $Locdst . '</td>';
        $html .= '</tr>';
          
      // Einen Tag weitergehen
      $date->modify('+1 day');
      }

    $html .= '</tbody>';
    $html .= '</table>';

    return $html;
  }
  
  public function ViewAction(Request $request)
  {
    ini_set('memory_limit', '256M');
    // Setze die Standardzeitzone auf UTC 
    date_default_timezone_set('UTC');

    $em = $this->getDoctrine()->getManager();
    $form = $this->createFormBuilder()->getForm();
    $form->handleRequest($request);
    $data = $request->request->all('form');
    if (empty($data))
    {
      $country = "Germany";
      $airport = "WORMS EDFV (GERMANY)";
      $country_code = ToolsCountryRepository::GetCountryCode($em, $country);
      $dateTime = new \DateTime('now');
    }
    else
    {
      $country = $data['Country_Name'];
      $country_code = ToolsCountryRepository::GetCountryCode($em, $country);
      $airport = $data['Airport_Name'];
      $date = $data['SRSSDate'];
      $dateTime = \DateTime::createFromFormat('m.Y', $date);
    }
    
    $countrylist = ToolsCountryRepository::GetAllCountriesForListbox($em);
    $airportlist = ToolsAirportRepository::GetAllAirportsForListbox($em, $country_code);

    if (in_array($airport, $airportlist)) {
      $airportchoice = $airport;
    } else {
      $airportchoice = reset($airportlist);
      $airport = $airportchoice;
    }
    
    $form = $this->createFormBuilder()
    ->add('Country_Name', ChoiceType::class, array ('choices' => $countrylist, 
          'required' => false, 'mapped' => false, 'data' => $country))
    ->add('Airport_Name', ChoiceType::class, array ('choices' => $airportlist, 
          'required' => false, 'mapped' => false, 'data' => $airportchoice))
    ->add('SRSSDate', DateTimeType::class, array('html5' => false, 'format' => Sunrise_SunsetController::DateFormat, 
          'widget' => 'single_text', 'mapped' => false, 'data' => $dateTime))    
              
    ->getForm();
    
    if (!empty($airportlist)) 
    {
        
        
      $airport_obj = ToolsAirportRepository::findCoordinatesByAirportName($em, $airport);
      $firstElement = reset($airport_obj);

      $offsets = $this->getTimezoneOffsets($firstElement->getTime());
      if ($firstElement->getTime() != null) 
      {
        $offsetstr = "(" . $firstElement->getTime() . ")";
      } else {  
        $offsetstr = "(N/A)";
      }
    
      $decimalLatitude = $this->convertToDecimal($firstElement->getsLat());
      $decimalLongitude = $this->convertToDecimal($firstElement->getsLong());
      $timezone = ToolsCountryRepository::GetTimeZone($em, $firstElement->getCountry());
      $htmlTable = $this->generateMonthlyTable($dateTime, $decimalLatitude, $decimalLongitude, $timezone, $offsets, $offsetstr);
      $title = "Sunrise and Sunset for " . $airport . " in the Month " . $dateTime->format('m.Y');
      $title .= "</br> Latitute/Breitengrad & Longitude/Längengrad: " . $this->decimalToDMS($decimalLatitude, true) . " " . $this->decimalToDMS($decimalLongitude, false);
    }
    else
    {
      $htmlTable = "";
      $title = "No Airports available";
    }
    return $this->render('sunrise_sunset/view.html.twig', [ 
      'form' => $form->createView(), 'htmlTable' => $htmlTable, 'title' => $title
    ]);
  }
}
