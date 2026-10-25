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
use App\Repository\ToolsAirportTimezoneMapRepository;
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

    // Der Grad-Anteil ist NICHT fest auf 2 (Breite) bzw. 3 Ziffern (Laenge) gepolstert -
    // z.B. deutsche Laengengrade wie "E13073500" (13,126 Grad) sind nur 9 statt 10 Zeichen
    // lang, weil die 3 Grad-Stellen nicht mit einer fuehrenden Null aufgefuellt wurden.
    // Die bisherige feste Laengenpruefung (==10 Laenge, sonst Breite mit 2 Grad-Stellen)
    // parste solche Faelle nur zufaellig richtig, wenn die Grad-Zahl genau 2-stellig war.
    // Robuster: von hinten parsen, da Minuten/Sekunden/Hundertstel immer je 2 Ziffern sind,
    // und alles davor (beliebig lang) die Gradzahl ist.
    $digits = substr($coordinate, 1);
    if (strlen($digits) < 7 || !ctype_digit($digits)) {
        return null;
    }
    $hundredths = substr($digits, -2);
    $seconds    = substr($digits, -4, 2);
    $minutes    = substr($digits, -6, 2);
    $degrees    = substr($digits, 0, -6);

    // Konvertiert die Komponenten in Dezimalgrad
    $decimal = (float) $degrees + ((float) $minutes / 60) + ((float) ($seconds . '.' . $hundredths) / 3600);

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
  protected function generateMonthlyData($date, $decimalLatitude, $decimalLongitude, $timezone, string $tzMode = 'local', array $weekdays = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So']): array
  {
    $date = new \DateTime($date->format('Y-m-01'));
    $endOfMonth = (clone $date)->modify('last day of this month');
    $wdShort = $weekdays;
    $today = (new \DateTime('now'))->format('Y-m-d');
    $tzName = $tzMode === 'mez' ? 'Europe/Berlin' : $timezone;

    // Vortag vorab berechnen (auch ueber den Monatsanfang hinweg): dessen Sonnenuntergang/
    // Daemmerungsende kann - in der Anzeige-Zeitzone - erst NACH Mitternacht liegen (z.B.
    // JFK in MESZ: Sonnenuntergang faellt auf ca. 00:30 "morgen") und muss dann als
    // Fortsetzung am Anfang des naechsten Tages sichtbar bleiben (siehe $carry* unten).
    $prevInfo = $this->computeDaySunInfo((clone $date)->modify('-1 day'), $decimalLatitude, $decimalLongitude, $tzMode, $tzName);

    $rows = [];
    while ($date <= $endOfMonth) {
      $info = $this->computeDaySunInfo($date, $decimalLatitude, $decimalLongitude, $tzMode, $tzName);

      $N = (int) $date->format('N'); // 1=Mo .. 7=So
      $row = $info + [
        'date'    => $date->format('d.m.'),
        'day'     => $date->format('d'),
        'wd'      => $wdShort[$N - 1],
        'weekend' => $N >= 6,
        'today'   => $date->format('Y-m-d') === $today,
      ];

      // Ragt der Sonnenuntergang/das Daemmerungsende des VORTAGS (in der Anzeige-Zeitzone)
      // ueber Mitternacht in den heutigen Tag hinein, muss dessen letzter Rest hier als
      // eigenes Segment am Tagesanfang gezeigt werden - sonst wirkt der fruehe Morgen
      // faelschlich komplett dunkel, obwohl es eigentlich noch Tageslicht/Daemmerung vom
      // Vorabend ist.
      if ($row['polar'] === null && $prevInfo['polar'] === null) {
        if (($prevInfo['ssMin'] ?? 0) > 1440) {
          $row['carrySunEnd'] = $prevInfo['ssMin'] - 1440;
        }
        if (($prevInfo['duskMin'] ?? 0) > 1440) {
          $row['carryTwiEnd'] = $prevInfo['duskMin'] - 1440;
        }
      }

      if ($row['today'] && $row['polar'] === null) {
        $off = $tzMode === 'utc' ? 0 : (new \DateTime())->setTimezone(new DateTimeZone($tzName))->getOffset();
        $row['nowMin'] = (int) (((time() + $off) % 86400 + 86400) % 86400 / 60);
      }

      $rows[] = $row;
      $prevInfo = $info;
      $date->modify('+1 day');
    }

    return $rows;
  }

  /**
   * Berechnet Sonnenaufgang/-untergang und buergerliche Daemmerung fuer EINEN Tag, als
   * Minuten seit Tagesbeginn (UTC-Tagesanker) in der gewaehlten Anzeige-Zeitzone - siehe
   * generateMonthlyData() fuer den Hintergrund zu Polarnacht/-tag und dem Datumsumbruch.
   *
   * @return array{polar: string|null, dawnMin?: int, srMin?: int, ssMin?: int, duskMin?: int, srStr?: string, ssStr?: string, lenStr?: string}
   */
  private function computeDaySunInfo(\DateTime $date, float $lat, float $lon, string $tzMode, string $tzName): array
  {
    $info = date_sun_info($date->getTimestamp(), $lat, $lon);
    $sr = $info['sunrise'];   $ss = $info['sunset'];
    $dawn = $info['civil_twilight_begin']; $dusk = $info['civil_twilight_end'];
    $dayStartTs = $date->getTimestamp();

    // Offset je Ereignis-Zeitpunkt bestimmt (nicht einmal pauschal fuer den Tagesanker),
    // damit die Sommerzeit-Umstellung immer exakt zum richtigen Zeitpunkt greift. Minuten
    // seit Tagesbeginn OHNE Modulo-Umbruch auf 0..1440: Bei weit oestlich/westlich der
    // Anzeige-Zeitzone liegenden Flugplaetzen (z.B. JFK in MESZ angezeigt) kann ein Ereignis
    // nach Offset-Anwendung auf den naechsten Kalendertag fallen. Mit Modulo wuerde das
    // faelschlich VOR den Sonnenaufgang dieses Tages zurueckfallen (negative Balkenbreite).
    // Ohne Modulo bleibt Daemmerung<=SA<=SU<=Daemmerung immer erhalten; der Tages-Container
    // (`overflow:hidden`) blendet den ueberstehenden Teil optisch aus - der Rest wird von
    // generateMonthlyData() als "carry"-Segment im naechsten Tag wieder sichtbar gemacht.
    $elapsed = static function ($ts) use ($tzMode, $tzName, $dayStartTs) {
      $off = $tzMode === 'utc' ? 0 : (new \DateTime('@' . $ts))->setTimezone(new DateTimeZone($tzName))->getOffset();
      return (int) (($ts + $off - $dayStartTs) / 60);
    };
    // true = Ereignis gilt bereits seit/bis zum Rand des Tages (z.B. Daemmerung haelt
    // durch), false = Ereignis tritt an diesem Tag gar nicht ein. date_sun_info() liefert
    // in beiden Faellen einen Bool statt eines Zeitstempels - vorher wurde der Bool
    // versehentlich als Unix-Zeitstempel missbraucht (true -> 1, false -> Parse-Fehler),
    // was an Tagen mit durchgehender Daemmerung (keine echte Polarnacht/-tag, aber z.B.
    // "helle Naechte" in Nordskandinavien/-kanada) zu kaputten (teils negativen)
    // Balkenbreiten fuehrte.
    $edge = static function ($value, int $ifTrue, int $ifFalse) use ($elapsed) {
      if ($value === true) return $ifTrue;
      if ($value === false) return $ifFalse;
      return $elapsed($value);
    };

    if ($dawn === false && $dusk === false) {
      // Keinerlei Daemmerung - durchgehend dunkel (echte Polarnacht).
      return ['polar' => 'night'];
    }
    if ($sr === true && $ss === true) {
      // Sonne sinkt nie unter -0,833 Grad - durchgehend hell (Polartag/Mitternachtssonne).
      return ['polar' => 'day'];
    }

    if ($sr === false) {
      // Sonne geht an diesem Tag gar nicht auf (bleibt unter -0,833 Grad), aber es gibt noch
      // eine Daemmerungsphase (buergerliche Daemmerung) um die flache Mittagshoehe der Sonne
      // - ein durchgehendes Daemmerungsband statt der sonst ueblichen drei Segmente, kein
      // separates Tageslicht-Segment.
      $dawnMin = $edge($dawn, 0, 0);
      $duskMin = $edge($dusk, 1440, 1440);

      return [
        'polar' => null,
        'dawnMin' => $dawnMin, 'srMin' => $duskMin, 'ssMin' => $duskMin, 'duskMin' => $duskMin,
        'noSunrise' => true,
      ];
    }

    $srMin = $elapsed($sr);
    $ssMin = $elapsed($ss);
    // Wenn die Daemmerung durchgaengig anhaelt (true), muss ihre Grenze mindestens bis zum
    // tatsaechlichen SA/SU reichen - sonst waere die feste Grenze (0 bzw. 1440) an Tagen/
    // Orten, an denen SA oder SU selbst schon auf den Nachbartag faellt (z.B. Flugplaetze
    // nahe den Polarkreisen mit sehr frueher/spaeter Sonnenauf-/untergangszeit), enger als
    // SA/SU selbst - und die Balkenbreite waere wieder negativ.
    $dawnMin = $edge($dawn, min(0, $srMin), 0);
    $duskMin = $edge($dusk, max(1440, $ssMin), 1440);
    // Letzte Absicherung: Faellt der Umrechnungs-Zeitpunkt von SA/SU und Daemmerung in der
    // Anzeige-Zeitzone auf verschiedene Seiten von DEREN eigener Sommerzeit-Umstellung (z.B.
    // SU noch vor, Daemmerungsende schon nach der Umstellung), kann die Uhrzeit der
    // Daemmerung trotz spaeterem Zeitpunkt "frueher" wirken (gleiche Mehrdeutigkeit wie beim
    // Uhren-Zurueckstellen). Reihenfolge erzwingen, statt eine negative Balkenbreite zu
    // riskieren.
    $dawnMin = min($dawnMin, $srMin);
    $duskMin = max($duskMin, $ssMin);

    $wrap = static fn (int $m): int => (($m % 1440) + 1440) % 1440;
    $srWrapped = $wrap($srMin);
    $ssWrapped = $wrap($ssMin);

    return [
      'polar' => null,
      'dawnMin' => $dawnMin, 'srMin' => $srMin, 'ssMin' => $ssMin, 'duskMin' => $duskMin,
      'srStr' => sprintf('%02d:%02d', intdiv($srWrapped, 60), $srWrapped % 60),
      'ssStr' => sprintf('%02d:%02d', intdiv($ssWrapped, 60), $ssWrapped % 60),
      'lenStr' => gmdate('H:i', (int) ($ss - $sr)),
    ];
  }

  /**
   * Oberflaechentexte des Sonnenzeiten-Tools auf Deutsch oder Englisch (erstes Tool mit
   * Sprachumschalter - Weight & Balance und die Flugplanung sollen spaeter folgen, siehe
   * CLAUDE.md/Projektnotizen; die Struktur hier (eine Methode, ein flaches Array) soll dafuer
   * als Vorlage dienen, kein eigenes Uebersetzungs-Framework fuer nur dieses eine Tool).
   */
  private function sunriseTranslations(string $lang): array
  {
    if ($lang === 'en') {
      return [
        'country' => 'Country', 'airport' => 'Airport', 'month' => 'Month',
        'tzmode' => 'Time format', 'language' => 'Language',
        'tz_local' => 'Local time (airport)', 'tz_mez' => 'CET/CEST (Germany)', 'tz_utc' => 'UTC',
        'months' => ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
        'weekdays' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
        'axis_sunrise' => 'Sunrise', 'axis_sunset' => 'Sunset', 'axis_length' => 'Length',
        'today' => 'today',
        'polar_day' => 'Polar day (24h daylight)', 'polar_night' => 'Polar night (24h dark)',
        'no_sunrise' => 'Twilight only, no sunrise',
        'legend_night' => 'Night', 'legend_twilight' => 'Civil twilight',
        'legend_daylight' => 'Daylight (sunrise to sunset)', 'legend_now' => 'now',
        'no_airports' => 'No airports available',
        'lat_long' => 'Latitude/Longitude', 'times_in' => 'Times in', 'local_time' => 'Local time',
      ];
    }

    return [
      'country' => 'Land', 'airport' => 'Flugplatz', 'month' => 'Monat',
      'tzmode' => 'Zeitform', 'language' => 'Sprache',
      'tz_local' => 'Ortszeit (Flugplatz)', 'tz_mez' => 'MEZ/MESZ (Deutschland)', 'tz_utc' => 'UTC',
      'months' => ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'],
      'weekdays' => ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'],
      'axis_sunrise' => 'Aufgang', 'axis_sunset' => 'Untergang', 'axis_length' => 'Länge',
      'today' => 'heute',
      'polar_day' => 'Polartag (durchgehend hell)', 'polar_night' => 'Polarnacht (durchgehend dunkel)',
      'no_sunrise' => 'Dämmerung, kein Sonnenaufgang',
      'legend_night' => 'Nacht', 'legend_twilight' => 'Bürgerliche Dämmerung',
      'legend_daylight' => 'Tageslicht (Sonnenauf- bis -untergang)', 'legend_now' => 'jetzt',
      'no_airports' => 'Keine Flugplätze verfügbar',
      'lat_long' => 'Breite/Länge', 'times_in' => 'Zeiten in', 'local_time' => 'Ortszeit',
    ];
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
    // Flugvorbereitungs-Werkzeug – fuer alle angemeldeten Piloten.
    // Oeffentlich zugaenglich (siehe security.yaml) – kein Login noetig.
    ini_set('memory_limit', '256M');
    // Setze die Standardzeitzone auf UTC 
    date_default_timezone_set('UTC');

    $form = $this->createFormBuilder()->getForm();
    $form->handleRequest($request);
    $data = $request->request->all('form');

    // Sonnenzeiten sind jahresunabhaengig -> aktuelles Jahr, nur der Monat wird gewaehlt.
    $year   = (int) (new \DateTime('now'))->format('Y');
    $tzMode = 'local';
    $lang   = 'de';
    if (empty($data))
    {
      $country = "Germany";
      $airport = "WORMS EDFV (GERMANY)";
      $country_code = ToolsCountryRepository::GetCountryCode($em, $country);
      $month = (int) (new \DateTime('now'))->format('n');
    }
    else
    {
      $country = $data['Country_Name'] ?? 'Germany';
      $country_code = ToolsCountryRepository::GetCountryCode($em, $country);
      $airport = $data['Airport_Name'] ?? '';
      $month  = max(1, min(12, (int) ($data['SRSSMonth'] ?? (new \DateTime('now'))->format('n'))));
      $tzModeRaw = $data['TimeZone_Mode'] ?? 'local';
      $tzMode = in_array($tzModeRaw, ['local', 'mez', 'utc'], true) ? $tzModeRaw : 'local';
      $langRaw = $data['Language'] ?? 'de';
      $lang = in_array($langRaw, ['de', 'en'], true) ? $langRaw : 'de';
    }
    $t = $this->sunriseTranslations($lang);
    $dateTime = (new \DateTime())->setDate($year, $month, 1)->setTime(12, 0, 0);

    $countrylist = ToolsCountryRepository::GetAllCountriesForListbox($em);
    $airportlist = ToolsAirportRepository::GetAllAirportsForListbox($em, $country_code);

    if (in_array($airport, $airportlist)) {
      $airportchoice = $airport;
    } else {
      $airportchoice = reset($airportlist);
      $airport = $airportchoice;
    }

    $monthChoices = array_combine($t['months'], range(1, 12));
    $tzChoices    = [$t['tz_local'] => 'local', $t['tz_mez'] => 'mez', $t['tz_utc'] => 'utc'];
    $langChoices  = ['Deutsch' => 'de', 'English' => 'en'];

    $form = $this->createFormBuilder()
    ->add('Country_Name', ChoiceType::class, array ('choices' => $countrylist,
          'required' => false, 'placeholder' => false, 'mapped' => false, 'data' => $country))
    ->add('Airport_Name', ChoiceType::class, array ('choices' => $airportlist,
          'required' => false, 'placeholder' => false, 'mapped' => false, 'data' => $airportchoice))
    ->add('SRSSMonth', ChoiceType::class, array ('choices' => $monthChoices,
          'required' => false, 'placeholder' => false, 'mapped' => false, 'data' => $month))
    ->add('TimeZone_Mode', ChoiceType::class, array ('choices' => $tzChoices,
          'required' => false, 'placeholder' => false, 'mapped' => false, 'data' => $tzMode))
    ->add('Language', ChoiceType::class, array ('choices' => $langChoices,
          'required' => false, 'placeholder' => false, 'mapped' => false, 'data' => $lang))
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
      // Laender mit mehreren Zeitzonen (USA, Russland, Kanada, Australien, ...) haben in
      // tools_countries nur EINE Zeitzone hinterlegt - das ist fuer die meisten Flugplaetze
      // dort falsch. Prioritaet der Quellen, von am genauesten zu am ungenauesten:
      // 1) tools_airports.Timezone - per Geo-Lookup aus sLat/sLong ermittelt (siehe
      //    app:update-airport-timezones), deckt praktisch jeden Flugplatz ab.
      // 2) tools_airport_timezone_map - Zuordnung ueber das rohe TIME-Feld (Schritt 1,
      //    nur fuer den Fall, dass (1) ausnahmsweise fehlt).
      // 3) tools_countries.Timezone - eine Zeitzone pro Land, letzter Rueckfall.
      $timezone = $firstElement->getTimezone()
        ?? ToolsAirportTimezoneMapRepository::GetTimezoneForAirport($em, $firstElement->getCountry(), $firstElement->getTime())
        ?? ToolsCountryRepository::GetTimeZone($em, $firstElement->getCountry());
      $days = $this->generateMonthlyData($dateTime, $decimalLatitude, $decimalLongitude, $timezone, $tzMode, $t['weekdays']);
      $title = $airport . ' · ' . $dateTime->format('m.Y');
      $tzLabel = $tzMode === 'utc' ? $t['tz_utc'] : ($tzMode === 'mez' ? $t['tz_mez'] : $t['local_time'] . ' ' . $timezone);
      $subtitle = $t['lat_long'] . ' ' . $this->decimalToDMS($decimalLatitude, true) . ' ' . $this->decimalToDMS($decimalLongitude, false)
                . ' · ' . $t['times_in'] . ' ' . $tzLabel;
    }
    else
    {
      $days = [];
      $title = $t['no_airports'];
      $subtitle = '';
    }
    return $this->render('modern/sunrise.html.twig', [
      'form' => $form->createView(), 'days' => $days, 'title' => $title, 'subtitle' => $subtitle, 't' => $t
    ]);
  }
}
