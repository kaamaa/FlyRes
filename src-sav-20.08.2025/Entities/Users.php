<?php

namespace App\Entities;

use Symfony\Component\Form\FormError;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;
use App\Security\User\WebserviceUser;
use App\Entities\Clients;
use App\ViewHelper;
use App\Logging;

class Users
{
  const const_NoMailCheck = "0";
  const const_Buchungsmail = "1";
  const const_Lizenzmail = "2";
  
  protected static function RecieveBookingMails($user)
  {
    // Wenn der Nutzer angegebene hat, dass er 
    // nur Mails für eigene Buchungen bekommen möchte (getGetbookingmails == 1) oder,dass
    // er alle Mails bekommen möchte (getGetbookingmails == 2) 
    if ($user->getGetbookingmails() == 1 || $user->getGetbookingmails() == 2) return TRUE;
      else return FALSE;
  }
  
  protected static function ReceiveLicenceMails($user)
  {
    // Wenn der Nutzer angegebene hat, dass er 
    // nur Mails für eigene Buchungen bekommen möchte (getGetbookingmails == 1) oder,dass
    // er alle Mails bekommen möchte (getGetbookingmails == 2) 
    if ($user->getGetlicencemails() == 1 || $user->getGetlicencemails() == 2) return TRUE;
      else return FALSE;
  }
  
  protected static function RecieveAllBookingMails($user)
  {
    if ($user->getGetbookingmails() == 2) return TRUE;
      else return FALSE;
  }
  
  protected static function ReceiveAllLicenceMails($user)
  {
    if ($user->getGetlicencemails() == 2) return TRUE;
      else return FALSE;
  }
  
  public static function UserWantsToReceiveMailsForMailtype($user, $mailtype)
  {
    if ($mailtype == self::const_Buchungsmail && self::RecieveBookingMails($user)) return TRUE;
    if ($mailtype == self::const_Lizenzmail && self::ReceiveLicenceMails($user)) return TRUE;
    if ($mailtype == self::const_NoMailCheck) return TRUE;
    return FALSE;
  }
  
  public static function UserWantsToReceiveAllMailsForMailtype($user, $mailtype)
  {
    if ($mailtype == self::const_Buchungsmail && self::RecieveAllBookingMails($user)) return TRUE;
    if ($mailtype == self::const_Lizenzmail && self::ReceiveAllLicenceMails($user)) return TRUE;
    if ($mailtype == self::const_NoMailCheck) return TRUE;
    return FALSE;
  }
  
  public static function UserWantsToReceiveMailsForMailtypeByID($em, $clientid, $userid, $mailtype)
  {
    $user = Users::GetUserObject($em, $clientid, $userid);
    if ($user)
    {  
      return Users::UserWantsToReceiveMailsForMailtype($user, $mailtype);
    }
    else return FALSE;
  }
  
  public static function IsPasswordOK (&$form, $pass1, $pass2, $pass_old)
  {
    // Passwort überprüfen
    $haserrors = FALSE;
    
    $pass1 = trim($pass1);
    $pass2 = trim($pass2);
    $pass_old = trim($pass_old);
    
    if (isset($pass1) && strlen($pass1) > 0)
    {  
      // Das Passwort wurde editiert
      if ($pass1 != $pass2) {
        $form->addError(new FormError('Die Passwörter sind nicht identisch'));
        $haserrors = TRUE;
      }  

      if ($pass1 == $pass2 && $pass_old != $pass1)
      { 
        // Das Passwort wurde geändert
        if (strlen($pass1) < 5) {
          $form->addError(new FormError('Das Passwort muss mindestens 5 Zeichen lang sein'));
          $haserrors = TRUE;
        }  
      }
    }  
    
    return $haserrors;  
  }
  
  public static function CreateNewPassword ($user, UserPasswordEncoderInterface $passwordEncoder, $pass)
  {
    if (isset($pass))
    {  
      // Das Passwort wurde  geändert daher Passwort codieren
      $encoded = $passwordEncoder->encodePassword($user, trim($pass), '');
      return $encoded;
    }  
    return NULL;  
  }
  
  public static function DeleteUser ($em, $clientid, $id)
  {
    $user = $em->getRepository('App\Entity\FresAccounts')->findOneBy(array('clientid' => $clientid, 'id' => $id));
    if ($user)
    {
      Bookings::DeleteAllBookingsForAUser($em, $clientid, $id);
      $user->setStatus('geloescht');
      // Wenn der Nutzer gelöscht wird muss der Username zur Anmedlung für neue Nutzer wieder freigegben werden
      // Daher wird er umbenannt in 'geloescht_' . $datum . '_' . $username;
      $timestamp = time();
      $datum = date("d.m.Y_H:i",$timestamp);
      $username = $user->getUsername();
      $username = 'geloescht_' . $datum . '_' . $username;
      $rest = substr($username, 0, 80); // Auf maximale Feldlänge von 80 kürzen
      $user->setUsername($rest);
      
      $em->persist($user);
      $em->flush();
    }
  }
  
  public static function GetUserName ($em, $clientid, $id)
  {
    if (!empty($id))
    {  
      $user = $em->getRepository('App\Entity\FresAccounts')->findOneBy(array('clientid' => $clientid, 'id' => $id));
      if ($user) return trim($user->getfirstname() . ' ' . $user->getlastname());
        else return "Nutzer nicht gefunden";
    }
    else return '';
  }
  
  public static function GetUserMailaddress ($em, $clientid, $userid, $mailcheck = Users::const_NoMailCheck)
  {
    if (!empty($userid))
    {  
      $user = Users::GetUserObject($em, $clientid, $userid);
      if ($user)
      {
        if (Users::UserWantsToReceiveMailsForMailtype($user, $mailcheck))
          return $user->getEmail();
      }  
      return '';
    }
    else return '';
  }
  
  public static function GetUserReplyToMailaddress ($user, &$user_mail, &$user_name, $checktype = Users::const_NoMailCheck)
  {
    // Absender der Mail festlegen
    // Vorbelegung der leeren Variablen
    $user_mail = '';
    $user_name = '';
    if ($user)
    {
    if ($checktype == Users::const_NoMailCheck || Users::UserWantsToReceiveMailsForMailtype($user, $checktype))
      {
        if (Users::IsMailAdressValid($user->getEmail()))
        {
          $user_mail = $user->getEmail();
          $user_name = $user->getFirstname() . ' ' . $user->getLastname();
          return;
        }
        else 
        {
          $user_mail = 'info@flugschule-worms.de';
          $user_name = $user->getFirstname() . ' ' . $user->getLastname() . ' (hat keine gültige Mailadresse hinterlegt)';
          return;
        }
      }
      // der Nutzer möchte keine Mails
      else return;
    } 
  }
  
  public static function GetAllAdminMailaddresses ($em, $clientid, $mailtype)
  {
    $userlist = array();
    
    $querystring = "
      SELECT a, f FROM App\Entity\FresAccounts a 
      INNER JOIN a.function f
      WHERE 
       (f.id = 3 OR f.id = 6 OR f.id = 7) AND
       a.clientid = :clientID AND 
       a.status <> 'geloescht'";    
     
    $query = $em->createQuery($querystring)->setParameters(array('clientID' =>  $clientid));
    $query->setCacheable(true);
    $users = $query->getResult();
    
    foreach ($users as $user) 
    {
      if (Users::UserWantsToReceiveAllMailsForMailtype($user, $mailtype))
      {
        // Mail wird nur hinzugefügt wenn User explizit alle Mails angefodert hat
        if (trim($user->getEmail()) != '') $userlist[$user->getId()] = $user->getEmail();
      }
    }
    return $userlist;
  }
  
  public static function GetAllUsersForMailListbox ($em, $clientid, $mailtype)
  {
    $userlist = array();
    $querystring = "SELECT b FROM App\Entity\FresAccounts b WHERE b.clientid = :clientID and b.status <> 'geloescht' ORDER BY b.lastname ASC";
    $query = $em->createQuery($querystring)->setParameters(array('clientID' =>  $clientid));
    $query->setCacheable(true);
    $users = $query->getResult();
    foreach ($users as $user) 
    {
      if (Users::UserWantsToReceiveMailsForMailtype($user, $mailtype))
      {
        if (trim($user->getEmail()) == '') $mail = ' (ohne Mailadresse)'; else $mail = ''; 
      }
      else $mail = ' (ohne Mailadresse)'; 
      $userlist[$user->getlastname() . ', ' . $user->getfirstname() . $mail] =  $user->getId();
    }
    return $userlist;
  }
  
  public static function IsMailAdressValid ($mail)
  {
    if ($mail && $mail != '')
    {
      // ebenfalls prüfen, ob die Domain existiert
      $host = explode('@', trim($mail));  
      if (!filter_var(trim($mail), FILTER_VALIDATE_EMAIL) || !isset($host[1]) || !checkdnsrr($host[1])) 
      {
        return FALSE;
      }
      else return TRUE;
    }
    else return FALSE;
  }
  
  public static function IsMailListValid ($mails)
  {
    if ($mails)
    {  
      $arr_emailinfoi = explode ("," , $mails );
      if (count($arr_emailinfoi) > 0)
      {
        for ( $y = 0 ; $y < count($arr_emailinfoi) ; $y++ )
        {
          if (!Users::IsMailAdressValid($arr_emailinfoi[$y])) return FALSE;
        }  
      }
    }
    return TRUE;
  }
  
  public static function GetAllUsersForListboxByMailadress ($em, $clientid, $mails)
  {
    $userlist = array();
    $arr_emailinfoi = explode ("," , $mails );
    if (count($arr_emailinfoi) >0)
    {
    
      $querystring = "SELECT b FROM App\Entity\FresAccounts b WHERE b.clientid = :clientID and b.status <> 'geloescht' ORDER BY b.lastname ASC";
      $query = $em->createQuery($querystring)->setParameters(array('clientID' =>  $clientid));
      $query->setCacheable(true);
      $users = $query->getResult();

      foreach ($users as $user) 
      {
        for ( $y = 0 ; $y < count($arr_emailinfoi) ; $y++ )
        {
          if (trim($arr_emailinfoi[$y]) != '' && trim($arr_emailinfoi[$y]) == trim($user->getEmail())) 
          {
            $userlist[] = (string) $user->getId();
          }
        }  
      }
    }
    return $userlist;
  }
  
  public static function GetAllMailsadressesByUserlist ($em, $userlist, $clientid)
  {
    // Diese Funktion formatiert die Nutzerauswahl in eine EmailAdressliste um, die dann gespeichert wird
    $mailadresses = '';
    if ($userlist)
    {
      $querystring = "SELECT b FROM App\Entity\FresAccounts b WHERE b.clientid = :clientID and b.status <> 'geloescht'";
      $query = $em->createQuery($querystring)->setParameters(array('clientID' =>  $clientid));
      $query->setCacheable(true);
      $users = $query->getResult();

      foreach ($users as $user) 
      {
        if (in_array($user->getId(), $userlist)) 
        {        
          if (trim($user->getEmail() != '')) $mailadresses .= trim($user->getEmail()) . ',';
        }  
      }
    }
    //echo 'Mailadresses: ' . $mailadresses . '<br>';
    return $mailadresses;
  }
  
  public static function GetAllValidMailsadresses ($em, $clientid, $separator)
  {
    // Diese Funktion erzeugt eine Liste aller gültigen Mailadressen aus der Datenbank
    $mailadresses = '';

    $querystring = "SELECT b FROM App\Entity\FresAccounts b WHERE b.clientid = :clientID and b.status <> 'geloescht'";
    $query = $em->createQuery($querystring)->setParameters(array('clientID' =>  $clientid));
    $query->setCacheable(true);
    $users = $query->getResult();

    foreach ($users as $user) 
    {
      // gesperrte oder gelöschte Nutzer werden nicht mit aufgenommen
      if (!Users::isLocked($user) && !Users::isDeleted($user))
      {  
        $mail = trim($user->getEmail());
        if (Users::IsMailListValid($mail) && $mail != '') $mailadresses .= $mail . $separator;
      }  
    }
    return $mailadresses;
  }
  
  public static function GetUserNameForAlphabeticOrder ($em, $clientid, $id)
  {
    if (!empty($id))
    {  
      $user = $em->getRepository('App\Entity\FresAccounts')->findOneBy(array('clientid' => $clientid, 'id' => $id));
      if ($user) return  $user->getlastname() . ', ' . $user->getfirstname();
        else return "Nutzer nicht gefunden";
    }
    else return '';
  }
  
  public static function GetAllUsersForListbox ($em, $clientid)
  {
    $userlist = array();
    $querystring = "SELECT b FROM App\Entity\FresAccounts b WHERE b.clientid = :clientID and b.status <> 'geloescht' ORDER BY b.lastname ASC";
    $query = $em->createQuery($querystring)->setParameters(array('clientID' =>  $clientid));
    $query->setCacheable(true);
    $users = $query->getResult();
      foreach ($users as $user) 
      {
        $userlist[$user->getlastname() . ', ' . $user->getfirstname()] = $user->getId();
      }
      return $userlist;
  }
  
  public static function GetAllUsers ($em, $clientid)
  {
    
    $userlist = array();
    $querystring = "SELECT b FROM App\Entity\FresAccounts b WHERE b.clientid = :clientID and b.status <> 'geloescht' ORDER BY b.lastname ASC";
    $query = $em->createQuery($querystring)->setParameters(array('clientID' =>  $clientid));
    $query->setCacheable(true);
    $users = $query->getResult();
    foreach ($users as $user) 
    {
      $userlist[] = array(
                          'id' => $user->getId(),
                          'firstname' => $user->getFirstname(), 
                          'lastname' => $user->getLastname(),
                          'username' => $user->getUsername(),
                          'email' => $user->getEmail(),
                          'function' => Functions::GetFunctionsForUser ($em, $user->getId()),
                          'islocked' => $user->getIslocked(),
                          'phoneNumberHome' => $user->getPhoneNumberHome(),
                          'phoneNumberOffice' => $user->getPhoneNumberOffice(),
                          'phoneNumberMobile' => $user->getPhoneNumberMobile(),
                          'clientid' => $user->getClientid(),
                          'status' => $user->getStatus(),
                         );
    }
    return $userlist;
 
  }
  
  public static function GetAllFlightinstructorsAsObject ($em, $clientid)
  {
    $resultlist = array();
    
    $querystring = "
      SELECT a, f FROM App\Entity\FresAccounts a 
      INNER JOIN a.function f
      WHERE f.id = 1 AND
       a.clientid = :clientID AND 
       a.status <> 'geloescht'
       ORDER BY a.lastname ASC";    
     
    $query = $em->createQuery($querystring)->setParameters(array('clientID' =>  $clientid));
    $query->setCacheable(true);
    $resultlist = $query->getResult();
    return $resultlist;
  }
  
  public static function GetAllFlightinstructorsForListbox ($em, $clientid)
  {
    
    $userlist = array();
    $users = Users::GetAllFlightinstructorsAsObject($em, $clientid);
    foreach ($users as $user) {
      $userlist[$user->getfirstname() . ' ' . $user->getlastname()] = $user->getId();
    }
    return $userlist;
  }
  
  // Hier muss nochmals die Mandantenfähigkeit geklärt werden
  public static function GetUserObject ($em, $clientid, $id)
  {
    return $em->getRepository('App\Entity\FresAccounts')->findOneBy(array('clientid' => $clientid, 'id' => $id));
  }
  
  // Hier muss nochmals die Mandantenfähigkeit geklärt werden
  public static function GetUserObject2 ($em, $id)
  {
    return $em->getRepository('App\Entity\FresAccounts')->findOneBy(array('id' => $id));
  }
  
  public static function GetUserObjectByName ($em, $username, $clientid)
  {
    $user = $em->getRepository('App\Entity\FresAccounts')->findOneBy(array('username' => $username, 'clientid' => $clientid));
    return $user;    
  }
  
  public static function isAdmin($em, $user)
  {
    $roles = Functions::GetMaxRoleForUserId($em, $user);
    if (in_array($roles, array('ROLE_ADMIN', 'ROLE_SYSTEM_ADMIN', 'ROLE_GLOBAL_ADMIN'))) return TRUE;
      else return FALSE;
  } 
  
  public static function isFlightinstructor($em, $userid)
  {
    // Die Rolle Fluglehrer muss explizit gesetzt sein und wird nicht über die Rollen-Hierarchy vergeben
    $roles = Functions::GetAllRolesForUserId($em, $userid);
    if (in_array('ROLE_FI', $roles)) return TRUE;
      else return FALSE;
  } 
  
  public static function AllowDoubleBookingsforFlightinstructor($em, $userid)
  {
    if (Users::isFlightinstructor($em, $userid))
    {
      $user = Users::GetUserObject2($em, $userid);
      if($user->getFiparallelbookings() == TRUE) return TRUE;
    }
    return FALSE;
  } 
  
  public static function GetFlightinstructorAvailabilities($em, $userid)
  {
    return array('Niemanden' => 0, 'Jeden' => 1, 'mich selbst' => 2, 'Administratoren' => 3);
  }
  
  public static function IsFlightinstructorAlwaysAvailable($em, $userid, $user_auth = NULL)
  {
    if (Users::isFlightinstructor($em, $userid))
    {
      $user = Users::GetUserObject2($em, $userid);
      if($user->getFiallwaysavailable() == 1) return TRUE;
      if ($user_auth != NULL)
      {
        if($user->getFiallwaysavailable() == 2 && $userid == $user_auth->getId()) return TRUE;
        if($user->getFiallwaysavailable() == 3 && Users::isAdmin($em, $user_auth)) return TRUE;
      }
    }
    return FALSE;
  } 
  
  public static function IsFlightinstructorBookableOnRequest($em, $userid)
  {
    if (Users::isFlightinstructor($em, $userid))
    {
      $user = Users::GetUserObject2($em, $userid);
      if($user->getFibookableifonrequest() == 1) return true;
        else return false;
    }  
    return FALSE;
  } 
  
  public static function isLocked ($user)
  {
    if($user->getIslocked() == 1) return TRUE;
      else return FALSE;
  }
  
  public static function isDeleted ($user)
  {
    if (strcmp($user->getStatus(), 'geloescht') == 0) return TRUE;
      else return FALSE;
  }
  
  
  public static function GetuserByClientName_Username ($em, $clientname, $username)
  {
    // Start Autologin
    $user = Users::GetUserObjectByName($em, $username, Clients::GetClientIdByName ($em, $clientname));
    if ($user)
    {  
      //Todo - brauchen wir den Check auch woanders
      if (!Users::isDeleted($user) && !Users::isLocked($user))
      {
        return $user;
      }
    }
    return FALSE;
  }
  
}