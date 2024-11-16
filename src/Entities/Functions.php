<?php

namespace App\Entities;


class Functions
{
  
  public static function GetMaxRoleForUserId ($em, $user)
  {
    // höchste Berechtigung für den übergeben User ermitteln
    // Zunächst alle Elemente ermitteln die zu dem Nutzer gehören
    if(!$user) return '';
    $functions = $em->getRepository('App\Entity\FresUser2Functions')->findByuserid($user->getId());
    $priority = 0;
    if ($functions) 
    {
      // Jetzt die höchste Priorität aus den Elementen ermitteln
      foreach ($functions as $function) 
      {
        $int = Functions::GetPriority($em, $function->getFunctionid());
        if ($int > $priority) $priority = $int;
      }
    }
    // Zum Abschuß die Rolle für die die höchste Priorität ermittelt wurde zurückgeben
    if ($priority != 0) return Functions::GetRoleforPriority ($em, $priority);
      else return '';
  }
  
  public static function GetAllRolesForUserId ($em, $userid)
  {
    // alle Berechtigungen für den übergeben User ermitteln
    // Zunächst alle Elemente ermitteln die zu dem Nutzer gehören
    $roles = array();
    $functions = $em->getRepository('App\Entity\FresUser2Functions')->findByuserid($userid);
    if ($functions) 
    {
      foreach ($functions as $function) 
      {
        $priority = Functions::GetPriority($em, $function->getFunctionid());
        $roles[] = Functions::GetRoleforPriority ($em, $priority);
      }
    }
    return $roles;
  }
      
  public static function GetFunctionsForUser ($em, $id)
  {
    $functionlist = array();
    $functions = $em->getRepository('App\Entity\FresUser2Functions')->findByuserid($id);
    if ($functions) 
    {
      foreach ($functions as $function) 
      {
        //$functionlist[$function->getFunctionid()] = Functions::GetFunctionName ($em, $function->getFunctionid());
        //$functionlist[Functions::GetFunctionName ($em, $function->getFunctionid())] = $function->getFunctionid();
        $functionlist[$function->getFunctionid()] = Functions::GetFunctionName ($em, $function->getFunctionid());
      }
    }
    return $functionlist;
  }

  public static function GetFunctionName ($em, $id)
  {
    $function = $em->getRepository('App\Entity\FresFunction')->findOneByid($id);
    if ($function) 
    {
      return $function->getFunction();
    }
    return '';
  }
  
  public static function GetRole ($em, $id)
  {
    $function = $em->getRepository('App\Entity\FresFunction')->findOneByid($id);
    if ($function) 
    {
      return $function->getRole();
    }
    return '';
  }
  
  public static function GetRoleForPriority ($em, $priority)
  {
    $function = $em->getRepository('App\Entity\FresFunction')->findOneBypriority($priority);
    if ($function) 
    {
      return $function->getRole();
    }
    return '';
  }
  
  public static function GetPriority ($em, $id)
  {
    // Die Function ermittelt die Priorität (Zahlenwert) einer Function basierend auf der Funktionsnummer
    $function = $em->getRepository('App\Entity\FresFunction')->findOneByid($id);
    if ($function) 
    {
      return $function->getPriority();
    }
    return '';
  }
  
  public static function GetAllFunctionNames ($em)
  {
    $functionlist = array();
    $functions = $em->getRepository('App\Entity\FresFunction')->findBy(array(), array('priority'=>'asc'));
    if ($functions) 
    {
      foreach ($functions as $function) 
      {
        //$functionlist[$function->getId()] = $function->getFunction();
        $functionlist[$function->getFunction()] = $function->getFunction();
      }
    }
    return $functionlist; 
  }
  
}