<?php

namespace App\Entities;

// Die Funktion hat Zugriff auf die unterschiedlichen Mandanten (Clients)
class Clients
{
  public static function GetAllClientsForListbox ($em)
  {
    $clients = $em->getRepository('App\Entity\FresClient')->findAll();

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
}