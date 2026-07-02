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
use Doctrine\ORM\EntityManagerInterface;
use DateTimeZone;
use DateTime;

class Sunrise_SunsetController extends AbstractController
{ 
  const DateFormat = 'MM.yyyy';

  
  function parseTimezone($timezone) 
  /**
   * Parses a timezone string to extract standard and daylight saving time offsets.
   *
   * @param string $timezone The timezone string in the format 'UTC+4:30(+5:30DT)'
   * @return array An array containing the standard offset and daylight saving time offset (if present)
   *               First element is the standard offset, second is the DST offset or null
   */
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
  /**
   * Calculates the timezone offset in minutes from a given offset string.
   *
   * @param string $offset The timezone offset string (e.g. '+4:30' or '-2')
   * @return int The total offset in minutes, with sign preserved
   */
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


  
  /**
   * Liefert die Tagesdaten eines Monats als strukturiertes Array fuer die
   * grafische Tageslicht-Darstellung: Sonnenaufgang/-untergang und buergerliche
   * Daemmerung als Minuten des Tages in der ORTSZEIT des Flugplatzes (inkl.
   * Sommerzeit), plus Wochenende/Heute/Polartag-Kennzeichnung.
   *
   * @return array<int, array<string,mixed>>
   */
  protected function generateMonthlyData($date, $decimalLatitude, $decimalLongitude, $timezone): array
  {
    $date = new \DateTime($date->format('Y-m-01'));
    $endOfMonth = (clone $date)->modify('last day of this month');
    $wdShort = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
    $today = (new \DateTime('now'))->format('Y-m-d');

    $rows = [];
    while ($date <= $endOfMonth) {
      $info = date_sun_info($date->getTimestamp(), $decimalLatitude, $decimalLongitude);
      $sr = $info['sunrise'];   $ss = $info['sunset'];
      $dawn = $info['civil_twilight_begin']; $dusk = $info['civil_twilight_end'];

      // Ortszeit-Offset des Flugplatzes fuer diesen Tag (beruecksichtigt Sommerzeit).
      $locDate = clone $date;
      $locDate->setTimezone(new DateTimeZone($timezone));
      $off = $locDate->getOffset();
      $mod = static fn ($ts) => (int) (((($ts + $off) % 86400) + 86400) % 86400 / 60);

      $N = (int) $date->format('N'); // 1=Mo .. 7=So
      $row = [
        'date'    => $date->format('d.m.'),
        'day'     => $date->format('d'),
        'wd'      => $wdShort[$N - 1],
        'weekend' => $N >= 6,
        'today'   => $date->format('Y-m-d') === $today,
        'polar'   => null,
      ];

      if ($sr === false && $ss === false) {
        $row['polar'] = 'night';
      } elseif ($sr === true && $ss === true) {
        $row['polar'] = 'day';
      } else {
        $row['dawnMin'] = $mod($dawn);
        $row['srMin']   = $mod($sr);
        $row['ssMin']   = $mod($ss);
        $row['duskMin'] = $mod($dusk);
        $row['srStr']   = sprintf('%02d:%02d', intdiv($row['srMin'], 60), $row['srMin'] % 60);
        $row['ssStr']   = sprintf('%02d:%02d', intdiv($row['ssMin'], 60), $row['ssMin'] % 60);
        $row['lenStr']  = gmdate('H:i', (int) ($ss - $sr));
      }
      if ($row['today'] && $row['polar'] === null) {
        $row['nowMin'] = $mod(time());
      }

      $rows[] = $row;
      $date->modify('+1 day');
    }

    return $rows;
  }

  /**
   * Handles the view action for sunrise and sunset information.
   *
   * This method processes user requests to display sunrise, sunset, and twilight times
   * for a selected airport and date range. It creates a form for country and airport selection,
   * retrieves astronomical data, and generates an HTML table with the results.
   *
   * @param Request $request The HTTP request containing form data
   * @return string HTML table with sunrise and sunset information
   */
  public function webView(Request $request, EntityManagerInterface $em)
  {
    // Nur fuer Global-System-Administratoren (mandantenuebergreifendes Werkzeug).
    $this->denyAccessUnlessGranted('ROLE_GLOBAL_ADMIN');
    ini_set('memory_limit', '256M');
    // Setze die Standardzeitzone auf UTC 
    date_default_timezone_set('UTC');

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
      $days = $this->generateMonthlyData($dateTime, $decimalLatitude, $decimalLongitude, $timezone);
      $title = $airport . ' · ' . $dateTime->format('m.Y');
      $subtitle = 'Breite/Länge ' . $this->decimalToDMS($decimalLatitude, true) . ' ' . $this->decimalToDMS($decimalLongitude, false)
                . ' · Ortszeit ' . $timezone;
    }
    else
    {
      $days = [];
      $title = 'Keine Flugplätze verfügbar';
      $subtitle = '';
    }
    return $this->render('modern/sunrise.html.twig', [
      'form' => $form->createView(), 'days' => $days, 'title' => $title, 'subtitle' => $subtitle
    ]);
  }
}
