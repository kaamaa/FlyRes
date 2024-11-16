<?php

namespace App\Entities;

use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Address;
use App\Controller\LicenceController;

class Licenses
{
  public static function GetUserLicenceObject ($em, $clientid, $id)
  {
    $querystring = "SELECT b FROM App\Entity\FresUserlicences b WHERE b.clientid = :clientID and b.id = :Id and b.status <> 'geloescht'";
    $query = $em->createQuery($querystring)->setParameters(array('clientID' =>  $clientid, 'Id' => $id));
    try 
    {
      $licence = $query->getSingleResult();
    } 
    catch (\Doctrine\Orm\NoResultException $e) 
    {
      $licence = null;
    }
    catch (Exception $e) {
      $licence = null;
    }
    return $licence;
  }  
  
  public static function DeleteLicence ($em, $clientid, $id)
  {
    $licence = $em->getRepository('App\Entity\FresUserlicences')->findOneBy(array('clientid' => $clientid, 'id' => $id));
    if ($licence)
    {
      $licence->setStatus('geloescht');
      if ($licence->getValidunlimited() == true)
        $licence->setValiduntil (LicenceController::ChangeValidUntil_NotNull());
      $em->persist($licence);
      $em->flush();
    }
  }
  
  public static function PPL_Required_but_CR_Invalid ($em, $accountID, $aircraftTypeid, $reservationdate)
  {
    // Zunächst überprüfen, ob für das Flugzeug ein Class Rating SEP oder TMG benötigt
    $cr = null;
    $licentypes = Licenses::GetAllLicenceTypeObjectForAPlane($em, $aircraftTypeid);
    foreach($licentypes as $licentype)
    { 
      // Welches Class Rating benötigt das Flugzeug? (14 - SEP, 13 - TMG)
      switch ($licentype->getID()) 
      {
        case 13: // TMG
          $cr = 13;
          break 2;
        case 14: // SEP
          $cr = 14;
          break 2;
      }
    }
    if ($cr != null)
    {
      // Ein Class Rating wird benötigt
      // Nun prüfen, ob der Pilot eine PPL Lizenz hat, 21 steht für die PPL Lizenz)
      if (Licenses::IsUserLicenceValidForReservationDate($em, $accountID, 21, $reservationdate))
      {
        // Ist das benötigte Class Rating gültig? 
        if (Licenses::IsUserLicenceValidForReservationDate($em, $accountID, $cr, $reservationdate))
        {
          // PPL ist vorhanden und Class Rating ist gültig
          return false;
        } 
        // PPL ist vorhanden und Class Rating ist ungültig
        return true;
      }
      // Kein PPL vorhanden 
      return false;
    }
    // Kein PPL für das Flugzeug benötigt 
    return false;
  }
  
  public static function CheckIfLicencesAreValid ($em, $clientid, $accountID, $aircraftTypeid, $reservationdate, $isSchulung)
  {
    // Diese Funktion prüft, ob für den geplanten Flug alle erfoderlichen Lizenzen für den Flugzeigtypen vorhanden sind
    // Wenn alle Lizenzen vorhanden sind gibt die Funktion Null zurück.
    // Wenn nicht alle Lizenzen vorhanden sind gibt die Fuktion eine Fehlermeldung als String zurück
    
    // Werden für das Flugzeug überhaupt Lizenzen angefordert
    if (Licenses::AircraftTypeRequestLicences($em, $aircraftTypeid))
    { 
      // Alle Lizenztypen für den Flugzeugtyp ermitteln
      $licentypes = Licenses::GetAllLicenceTypeObjectForAPlane($em, $aircraftTypeid);
      $categorie_ary = null;
      // Die Lizenzen nach Kategorie gruppieren, do dass gleiche Kategorien direkt hintereinader im Array sind
      foreach($licentypes as $licentype)
      { 
        $categorie_ary[$licentype->getCategoryID()][] = $licentype;
      }
      // Lister der fehlenden Lizenzen initialisieren
      $fehler_ary = NULL;
      // Jetzt jede Kategoriegruppe bearbeiten (z.B. Lizenz, Medical....)
      foreach($categorie_ary as $categories)
      {
        // Den Namen der Kategorie ermitteln über Zugriff auf das erste Element und in einem String merken
        reset($categories);
        $error_str = current($categories)->getCategoryname();
        // Jetzt durch alle gefodrerten Lizenzen der Kategorie durchlaufen
        foreach($categories as $licence)
        {  
          // Besitzt der Nutzer diese Lizenz oder Berechtigung?
          if (Licenses::IsUserLicenceValidForReservationDate($em, $accountID, $licence->getId(), $reservationdate))
          {
            // Eine gültige Lizenz gefunden, daher den Fehlersting auf '' setzen
            $error_str = '';
            // Eine gültige Lizenz pro Kategorie reicht, aus der Schleife aussteigen
            break;
          }
        }
        // Wenn keine Lizenz aus der Kategorie vorhanden ist diese Kategorie zur Fehlerliste hinzufügen
        if ($error_str != '') $fehler_ary[] = $error_str; 
      }
      
      // Keine Fehler gefunden?
      if ($fehler_ary == NULL) return Null;
      
      if ($isSchulung && Licenses::PPL_Required_but_CR_Invalid($em, $accountID, $aircraftTypeid, $reservationdate))
      {
        $err = 'Ein Schulflug ist nicht möglich, da am Tag des Fluges das für den PPL (EASA-FCL) erfolderliche Class Rating ungültig ist - eine Befähigungsüberprüfung ist erfoderlich.';
        if (($key = array_search('Class Rating', $fehler_ary)) !== false) unset($fehler_ary[$key]);
        if (count($fehler_ary) > 0)
        {
          $err = $err . ' Folgende weitere Berechtigungen sind nicht gültig: (' . implode(", ", $fehler_ary) . ')';
        }
        return $err;
      }
      
      if ($isSchulung)
      {
        if (in_array('Medical', $fehler_ary)) return 'Die Reservierung kann nicht erfolgen, da am Tag des Fluges für den Flugschüler kein gültiges Medical vorliegt';
          else return Null;
      }
      
      if (count($fehler_ary) > 0)
      {
        return 'Die Reservierung kann nicht erfolgen, da am Tag des Fluges für den Piloten folgende Berechtigungen nicht gültig sind: (' . implode(", ", $fehler_ary) . ')';
      }
      else return NULL; 
    }
    return NULL;
  } 
  
  public static function DeleteAircraftType ($em, $clientid, $id)
  {
    $aircrafttype = $em->getRepository('App\Entity\FresAircrafttype')->findOneBy(array('clientid' => $clientid, 'id' => $id));
    if ($aircrafttype)
    {
      //$aircrafttype->setStatus('geloescht');
      //$em->persist($aircrafttype);
      $em->remove($aircrafttype);
      $em->flush();
      
      $querystring = "DELETE App\Entity\FresAircrafttype2licences a WHERE a.aircrafttypeid = :ID";
      $query = $em->createQuery($querystring)->setParameters(array('ID' => $id));
      $query->execute();
    }
  }
  
  public static function GetAllLicenceTypeObjectForAPlane ($em, $aircraftTypeid)
  {
    $qb = $em->createQueryBuilder();
    $qb->select('b')
       ->from('App\Entity\FresAircrafttype2licences', 'a')
       ->innerJoin('App\Entity\FresLicencetype', 'b', 'WITH', 'a.licenceid = b.id')
       ->where("a.aircrafttypeid = :aircrafttypeid")
       ->orderBy('b.categoryid', 'ASC');     

    $qb->setParameters(array('aircrafttypeid' => $aircraftTypeid));
    $query = $qb->getQuery();
    $query->setCacheable(true);
    $licencestypes = $query->getResult();
    return $licencestypes;
  }    
  
  public static function GetLicenceTypeObject ($em, $id)
  {
    $licenseTypeList = array ();
    $querystring = "SELECT b FROM App\Entity\FresLicencetype b WHERE b.id = :Id";
    $query = $em->createQuery($querystring)->setParameters(array('Id' => $id));
    $licencesType = $query->getSingleResult();
    return $licencesType;
  }  
  
  public static function IsUserLicenceValidForReservationDate ($em, $accountID, $licenceID, $reservationdate)
  {
    $querystring = "SELECT b FROM App\Entity\FresUserlicences b WHERE b.accountid = :accountID and b.licenceid = :licenceid and (b.validuntil >= :reservationdate or b.validunlimited <> 0) and (b.status <> 'geloescht' or b.status IS NULL)";
    $query = $em->createQuery($querystring)->setParameters(array('accountID' => $accountID, 'licenceid' => $licenceID, 'reservationdate' => $reservationdate));
    $query->setCacheable(true);
    $licences = $query->getResult();
    if($licences) return TRUE;
      else return FALSE;
  }  
  
  public static function LicenceTypeExistsForUser ($em, $id, $accountID, $licenceID)
  {
    if ($id == NULL) $id = -1; //Bei neu angelegten Lizenzen vorbelegen
    $querystring = "SELECT b FROM App\Entity\FresUserlicences b WHERE b.accountid = :accountID and b.licenceid = :licenceid and b.id <> :id and (b.status <> 'geloescht' or b.status IS NULL)";
    $query = $em->createQuery($querystring)->setParameters(array('id' => $id, 'accountID' => $accountID, 'licenceid' => $licenceID));
    $licences = $query->getResult();
    if($licences) return TRUE;
      else return FALSE;
  }  
  
  public static function GetAllLicenceTypes ($em)
  {
    $licenseTypeList = array ();
    $querystring = "SELECT b FROM App\Entity\FresLicencetype b order by b.categoryid asc, b.longname desc";
    $query = $em->createQuery($querystring);
    $query->setCacheable(true);
    $licencesTypes = $query->getResult();
    if ($licencesTypes) {
      foreach ($licencesTypes as $licencesType) {
        $licenseTypeList[$licencesType->getCategoryname() . ' ' . $licencesType->getLongname()] = $licencesType->getId();
      }
    }
    return $licenseTypeList;
  } 
  
  public static function AircraftTypeRequestLicences ($em, $aircrafttype)
  {
    $licencesTypes = NULL;
    $querystring = "SELECT b FROM App\Entity\FresAircrafttype2licences b where b.aircrafttypeid = :aircrafttype";
    $query = $em->createQuery($querystring)->setParameters(array('aircrafttype' => $aircrafttype));
    $query->setCacheable(true);
    $licencesTypes = $query->getResult();
    if ($licencesTypes != NULL) return true;
      else return false;
  } 
  
   
  public static function GetLicenceContent ($licence, $em, $user, $prefix = '')
  {
    setlocale(LC_TIME, 'de_DE.UTF-8', 'de_DE@euro', 'de_DE', 'de', 'ge');
    if ($licence)
    {
      $changedate = date_format(new \DateTime('now'), 'd.m.Y H:i');
      if ($licence->getValidunlimited()) $valunlim = 'Ja';
        else $valunlim = 'Nein';
      if ($licence->getValiduntil())
      {
        $formatter = new \IntlDateFormatter('de_DE', \IntlDateFormatter::LONG, \IntlDateFormatter::NONE);
        $formatter->setPattern('eeee dd.MM.yyyy');
        $validUntil = $formatter->format($licence->getValiduntil());
      }
        else ($validUntil = "");      
      
      $licenceList = array ($prefix . 'id' => $licence->getId(),
                            $prefix . 'Username' => Users::GetUserName($em, $licence->getClientid(), $licence->getAccountid()),
                            $prefix . 'ChangedBy' => Users::GetUserName($em, $user->getClientid(), $user->getId()),
                            $prefix . 'ChangedAt' => $changedate,
                            $prefix . 'Licence' => $licence->getLicence(),
                            $prefix . 'Unlimited' => $valunlim,
                            $prefix . 'ValidUntil' => $validUntil,
                            $prefix . 'description' => $licence->getComment());
    }    
    return $licenceList;
  }
  
  public static function SendLicenceInfoMail($em, $user, $twig, $newlicence, $oldlicence, $mailer, $parameter)
  {
    if ($newlicence != null)
      $newlicence->setValiduntil(LicenceController::ChangeValidUntil_Null($newlicence->getValiduntil()));
    if ($oldlicence != null)
      $oldlicence->setValiduntil(LicenceController::ChangeValidUntil_Null($oldlicence->getValiduntil()));
    
    $clientId = '';
    
    if ($newlicence && $oldlicence) {
      $type = 'Änderung einer Berechtigung'; 
      $clientId = $newlicence->getClientid();
      $datanew = Licenses::GetLicenceContent($newlicence, $em, $user, 'primary_');
      $dataold = Licenses::GetLicenceContent($oldlicence, $em, $user, 'secondary_');
    }
    if ($newlicence && !$oldlicence) {
      $type = 'Neue Berechtigung'; 
      $clientId = $newlicence->getClientid();
      $datanew = Licenses::GetLicenceContent($newlicence, $em, $user, 'primary_');
      $dataold = array();
    }
    if (!$newlicence && $oldlicence) {
      $type = 'Löschen einer Berechtigung'; 
      $clientId = $oldlicence->getClientid();
      $datanew = array();
      $dataold = Licenses::GetLicenceContent($oldlicence, $em, $user, 'primary_');
    }
    
    // Ermittelte Daten zusammenführen
    if ($datanew) 
    {
      $data = array_merge($datanew, $dataold); 
    }
    else
    {
      $data = $dataold; 
    }
    // Uncaught Error: array_merge(): Argument #1 must be of type array, null given
    $data = array_merge($data, array('type' => $type));
    
    // Absender der Mail festlegen, zunächst für die RepplyTo Adresse
    Users::GetUserReplyToMailaddress($user, $sender_mail, $sender_name);
    // Und nun nochmal um die den Absender selber zu informieren
    Users::GetUserReplyToMailaddress($user, $inform_sender_mail, $sender_name, Users::const_Lizenzmail);
    
    // Mailadressaten festlegen
    $mailIntern = array (); $mailExtern = array (); $adminMails = array(); $mailNewOwner = array(); $mailOldOwner = array();
    // Alle Administratoren informieren
    $adminMails = Users::GetAllAdminMailaddresses($em, $clientId, Users::const_Lizenzmail);
    
    // Der oder die Absender ehalten selbst eine Kopie der Mail
    $mails = array_merge($adminMails, array ($inform_sender_mail));
    // Doppelte Array-Einträge löschen
    $mails = array_unique($mails);
    
    //Mails versenden
    foreach ($mails as $mail) {
      if (Users::IsMailAdressValid($mail))
      {
        $message = (new Email())
          //->setContentType("text/html")    
          ->subject($type . ' ' . $parameter['program_version'])
          ->html($twig->render('emails/licencemail.html.twig', $data))
          ->replyTo(new Address($sender_mail, $sender_name))
          ->from($parameter['mail_from'])
          ->to($mail);
        
        $mailer->send($message);
      }
    }
  }
}