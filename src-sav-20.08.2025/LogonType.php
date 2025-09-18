<?php

namespace App;

use \Symfony\Component\HttpFoundation\Session\Session;
use App\Logging;

class LogonType
{
  const session_key_autologin = 'symfony_autologin';
  const session_value_autologin = 'yes';
 
  public static function isInFrame (Session $session)
  {
    // Die Funktion ermittelt, ob FlyRes Standalone oder in einem iFrame läuft (innerhalb der Webseite Flugschule-worms.de)
    self::debug(__FUNCTION__, $session);
    if (isset($session))
    {  
      //Logging::writeMsg('strcmp: ' . strcmp($session->get(LogonType::session_key_autologin), LogonType::session_value_autologin));
      if (strcmp((string) $session->get(LogonType::session_key_autologin), (string) LogonType::session_value_autologin) == 0) return true;
    } else die('Session ist nicht definiert');
    return false;
  }
  
  public static function isStandalone (Session $session)
  {
    self::debug(__FUNCTION__, $session);
    return !self::isInFrame($session);
  }
  
  public static function defineStandalone (Session $session)
  {
    // legt im Session Object fest, das FlyRes Standalone abläuft
    $session->remove(LogonType::session_key_autologin);
    self::debug(__FUNCTION__, $session);
  }
  
  public static function defineInFrame (Session $session)
  {
    // legt im Session Object fest, das FlyRes in einem Frame abläuft
    $session->set(LogonType::session_key_autologin, LogonType::session_value_autologin);
    self::debug(__FUNCTION__, $session);
  }
  
  public static function remove (Session $session)
  {
    $session->remove(LogonType::session_key_autologin);
    self::debug(__FUNCTION__, $session);
  }
  
  public static function debug ($msg, Session $session)
  {
    // Debugfunktions
    return;
    if (!isset($session)) Logging::writeMsg('Session ist nicht definiert');
    Logging::writeMsg($msg . ' : ' . $session->get(LogonType::session_key_autologin));
  }
}      

?>
