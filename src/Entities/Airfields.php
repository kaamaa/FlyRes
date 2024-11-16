<?php

namespace App\Entities;

class Airfields
{
  public static function GetAllAirportsForListbox ($em)
  {
    $querystring = "SELECT b FROM App\Entity\FresAirfield b ORDER BY b.airfield ASC";
    $query = $em->createQuery($querystring);
    $query->setCacheable(true);
    $airports = $query->getResult();
    foreach ($airports as $airport) {
      $airportlist[$airport->getairfield() . ' (' . $airport->getkennung() . ')'] = $airport->getId();
    }
    return $airportlist;
  }
  
  public static function GetAirfield ($em, $id)
  {
    $airfield = $em->getRepository('App\Entity\FresAirfield')->findOneByid($id);
    if ($airfield) return $airfield->getkennung() . ' ' . $airfield->getairfield();
      else return "Flugplatz nicht gefunden";
  }
  
}