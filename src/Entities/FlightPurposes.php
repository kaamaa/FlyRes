<?php

namespace App\Entities;

class FlightPurposes
{
    
  public static function GetFlightPuposeArray($em)
  {
    $flightpurposelist = array();
    $flightpurposes = $em->getRepository('App\Entity\FresFlightpurpose')->findAll();
    if ($flightpurposes) 
    {
      foreach ($flightpurposes as $flightpurpose) 
      {
        $flightpurposelist[$flightpurpose->getFlightPurpose()] = $flightpurpose->getId();
      }
    }
    return $flightpurposelist;
  }
  
  public static function IsSolo($flightPupose)
  {
    if ($flightPupose == 5) return TRUE;
     else return FALSE;
  }
  
  public static function GetSoloID()
  {
    // Für SQL-Statements wird der Wert ausgegeben
    return 5;
  }
  
  public static function IsTrainingWithFI($flightPupose)
  {
    if ($flightPupose == 2) return TRUE;
     else return FALSE;
  }
  
  public static function IsSchulung($flightPupose)
  {
    if (self::IsSolo($flightPupose) || self::IsTrainingWithFI($flightPupose)) return TRUE;
     else return FALSE;
  }
  
  public static function GetFlightpurpose ($em, $id)
  {
    $pupose = $em->getRepository('App\Entity\FresFlightpurpose')->findOneByid($id);
    if ($pupose) return $pupose->getFlightPurpose();
      else return "Flugart nicht gefunden";
  }
}