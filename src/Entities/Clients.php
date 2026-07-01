<?php

namespace App\Entities;

// Die Funktion hat Zugriff auf die unterschiedlichen Mandanten (Clients)
class Clients
{
  public static function GetAllClientsForListbox ($em)
  {
    // Nur AKTIVE Mandanten im Login anbieten – deaktivierte ausblenden.
    $clients = $em->getRepository('App\Entity\FresClient')->findBy(['active' => true], ['name' => 'ASC']);

    if ($clients) {
      foreach ($clients as $client) {
        $clientlist[] = array('id' => $client->getId(), 'name' => $client->getName());
      }
    }
    return $clientlist;
  } 
  
  public static function GetClientIdByName ($em, $clientName)
  {
    $client = $em->getRepository('App\Entity\FresClient')->findOneByName($clientName);
    if ($client) return $client->getId();
    else return 0;
  }

  // Vorausschau-Tage fuer "Naechste freie Termine" (pro Mandant konfigurierbar).
  // Faellt auf 14 zurueck und wird auf 1..120 begrenzt (Schutz vor sehr langen
  // Suchlaeufen, v. a. im Nur-Fluglehrer-Modus ohne Vorausbuchungs-Kappung).
  public static function GetNextslotsDays ($em, $clientid)
  {
    $client = $em->getRepository('App\Entity\FresClient')->find($clientid);
    $days = $client ? (int) $client->getNextslotsDays() : 14;
    if ($days <= 0) $days = 14;
    if ($days > 120) $days = 120;
    return $days;
  }
}