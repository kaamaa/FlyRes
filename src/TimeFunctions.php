<?php

namespace App;

class TimeFunctions
{
  // Zentrale deutsche Datumsnamen (1-indexiert: 1=Montag/Januar, wie ISO 'N'/'n').
  // Ersetzt die zuvor mehrfach kopierten Arrays in Controllern/Entities.
  public const WEEKDAYS       = [1 => 'Montag', 2 => 'Dienstag', 3 => 'Mittwoch', 4 => 'Donnerstag', 5 => 'Freitag', 6 => 'Samstag', 7 => 'Sonntag'];
  public const WEEKDAYS_SHORT = [1 => 'Mo', 2 => 'Di', 3 => 'Mi', 4 => 'Do', 5 => 'Fr', 6 => 'Sa', 7 => 'So'];
  public const MONTHS         = [1 => 'Januar', 2 => 'Februar', 3 => 'März', 4 => 'April', 5 => 'Mai', 6 => 'Juni', 7 => 'Juli', 8 => 'August', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Dezember'];

  const const_Wo_Lat = 49.6069; // Süd wäre ein negativer Wert
  const const_Wo_Long = 8.3683; // West wäre ein negativer Wert
  const const_Wo_Start_Hour = 9;
  const const_Wo_Start_Minute = 0;
  const const_Wo_End_Hour = 20;
  const const_Wo_End_Minute = 30;

  public static function GetDayStart ($date)
  {
    date_default_timezone_set('Europe/Berlin');
    $day = (int) $date->format('d');
    $month = (int) $date->format('m');
    $year = (int) $date->format('Y');

    $time = mktime ( 0,0,0 ,$month ,$day, $year);
    $suninfo = date_sun_info($time, TimeFunctions::const_Wo_Lat, TimeFunctions::const_Wo_Long);
    $sunrise = $suninfo['civil_twilight_begin'];

    // Jetzt auf halbe Stunden abrunden
    $sr_ts = floor ($sunrise / (60*30)) * (60*30);

    $sr_ts_daystart = mktime (TimeFunctions::const_Wo_Start_Hour, TimeFunctions::const_Wo_Start_Minute, 0 ,$month ,$day, $year);

    if ($sr_ts < $sr_ts_daystart) $sr_ts = $sr_ts_daystart;

    $hour = (int) date("H", $sr_ts);
    $minute = (int) date("i", $sr_ts);

    return array ($hour, $minute);
  }

  public static function GetDayEnd ($date)
  {
    date_default_timezone_set('Europe/Berlin');
    $day = (int) $date->format('d');
    $month = (int) $date->format('m');
    $year = (int) $date->format('Y');

    $time = mktime ( 0,0,0 ,$month ,$day, $year);
    $suninfo = date_sun_info($time, TimeFunctions::const_Wo_Lat, TimeFunctions::const_Wo_Long);
    $sunset = $suninfo['civil_twilight_end'];

    // Jetzt auf halbe Stunden aufrunden
    $sr_ts = ceil ($sunset / (60*30)) * (60*30);

    $sr_ts_dayend = mktime (TimeFunctions::const_Wo_End_Hour, TimeFunctions::const_Wo_End_Minute, 0 ,$month ,$day, $year);

    if ($sr_ts > $sr_ts_dayend) $sr_ts = $sr_ts_dayend;

    $hour = (int) date("H", $sr_ts);
    $minute = (int) date("i", $sr_ts);

    return array ($hour, $minute);

  }

  public static function GetDaylight ($int_month ,$int_day, $int_year)
  {
    date_default_timezone_set('Europe/Berlin');
    // Fliegbare Tageszeit von Sonnenaufgang bis Sonnenuntergang berechnen

    $time = mktime ( 0,0,0 ,$int_month ,$int_day, $int_year);
    $suninfo = date_sun_info($time, TimeFunctions::const_Wo_Lat, TimeFunctions::const_Wo_Long);
    $daystart = $suninfo['civil_twilight_begin'];

    $airport_start = mktime (TimeFunctions::const_Wo_Start_Hour, TimeFunctions::const_Wo_Start_Minute, 0 ,$int_month ,$int_day, $int_year);

    if ($daystart < $airport_start) $daystart = $airport_start;

    $dayend = $suninfo['civil_twilight_end'];

    $airport_close = mktime (TimeFunctions::const_Wo_End_Hour, TimeFunctions::const_Wo_End_Minute, 0 ,$int_month ,$int_day, $int_year);

    if ($dayend > $airport_close) $dayend = $airport_close;

    return $dayend - $daystart;
  }


}
