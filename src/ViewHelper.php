<?php

namespace App;

use DateTime;

/**
 * Kleiner View-Helfer. Nach Entfernen des klassischen Frontends ist nur noch
 * die Datums-Pruefung uebrig (vom modernen Frontend genutzt). Die frueheren
 * Kalender-Navigations- und SessionData-/Booking-Helfer waren klassik-only
 * und wurden entfernt.
 */
class ViewHelper
{
  public static function IsDateCorrect ($date)
  {
    if ($date == false) return false;
    // Wenn das Datum nicht gültig ist, wurde es durch Symfony bereits auf Null gesetzt
    if (isset($date) && ($date instanceof DateTime))
    {
      // Falls nicht null, trotzdem nochmals mit checkdate überprüfen
      return checkdate($date->format('m'), $date->format('d'), $date->format('Y'));
    }
    return FALSE;
  }
}
