<?php

namespace App\Entities;

use App\Entities\Notes;

class Notes
{
  const const_geloescht = 'geloescht';
  
  public static function GetAllActiveNotesAsObject ($em)
  {
    $notes = array ();
    $today = date('Y-m-d H:i:s');
    $querystring = "SELECT b FROM App\Entity\FresNote b WHERE b.validuntil >= :valid_until and b.status <> '" . Notes::const_geloescht  . "'";
    $query = $em->createQuery($querystring)->setParameters(array('valid_until' => $today));
    $query->setCacheable(true);
    $notes = $query->getResult();
    return $notes;
  }
  
  public static function GetNoteObject ($em, $clientid, $id)
  {
    return $em->getRepository('App\Entity\FresNote')->findOneBy(array('clientid' => $clientid, 'id' => $id));
  }
  
  public static function DeleteNote ($em, $clientid, $id)
  {
    $querystring = "SELECT b FROM App\Entity\FresNote b WHERE b.clientid = :clientID and b.id = :id and b.status <> '" . Notes::const_geloescht . "'";
    $query = $em->createQuery($querystring)->setParameters(array('clientID' =>  $clientid, 'id' => $id));
    $note = $query->getSingleResult();
    if ($note)
    {
      $note->setStatus(Notes::const_geloescht);
      $em->persist($note);
      $em->flush();
    }
  }
  
}