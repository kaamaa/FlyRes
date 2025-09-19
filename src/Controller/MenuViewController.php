<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use App\Entities\Users;
use Symfony\Component\HttpFoundation\Session\Session;
use App\LogonType;
use App\Logging;
use Doctrine\ORM\EntityManagerInterface;

class MenuViewController extends AbstractController
{
  protected function GetMenu (Session $session)
  {
    if ($this->isGranted('ROLE_PILOT'))
    {  
      $submenuListBuchungen[] = array ('name' => '14 Tage', 'link' => '_weeksview');
      $submenuListBuchungen[] = array ('name' => 'Monatsansicht', 'link' => '_monthview');
      $submenuListBuchungen[] = array ('name' => 'divider', 'link' => '');
      $submenuListBuchungen[] = array ('name' => 'meine Buchungen', 'link' => '_generalview', 'command' => 'own');
      $submenuListBuchungen[] = array ('name' => 'meine Buchungshistorie', 'link' => '_generalview', 'command' => 'own_history');
      $submenuListBuchungen[] = array ('name' => 'alle Buchungen', 'link' => '_generalview', 'command' => 'date');
      $submenuListBuchungen[] = array ('name' => 'heute', 'link' => '_generalview', 'command' => 'today');
      $submenuListBuchungen[] = array ('name' => 'morgen', 'link' => '_generalview', 'command' => 'tomorrow');
      $submenuListBuchungen[] = array ('name' => 'diese Woche', 'link' => '_generalview', 'command' => 'thisweek');
      $submenuListBuchungen[] = array ('name' => 'nächste Woche', 'link' => '_generalview', 'command' => 'weekafter');
      $submenuListBuchungen[] = array ('name' => 'dieses Wochenende', 'link' => '_generalview', 'command' => 'thisweekend');
      $submenuListBuchungen[] = array ('name' => 'nächstes Wochenende', 'link' => '_generalview', 'command' => 'nextweekend');
      $submenuListBuchungen[] = array ('name' => 'divider', 'link' => '');
      $submenuListBuchungen[] = array ('name' => 'nach Fluglehrer', 'link' => '_generalview', 'command' => 'fi');
      $submenuListBuchungen[] = array ('name' => 'nach Flugzeugen', 'link' => '_generalview', 'command' => 'planes');
      $submenuListBuchungen[] = array ('name' => 'nach Nutzern', 'link' => '_generalview', 'command' => 'users');
      $submenuListBuchungen[] = array ('name' => 'nur Schulung', 'link' => '_generalview', 'command' => 'training');

      $submenuListUser[] = array ('name' => 'Meine Daten verwalten', 'link' => '_mydata');
      $submenuListUser[] = array ('name' => 'divider', 'link' => '');
      $submenuListUser[] = array ('name' => 'Meine Lizenzen verwalten', 'link' => '_licencetable');
      $submenuListUser[] = array ('name' => 'Neue eigene Lizenz', 'link' => '_newlicence');
      $submenuListUser[] = array ('name' => 'divider', 'link' => '');
      $submenuListUser[] = array ('name' => 'Neuer Pinnwandeintrag', 'link' => '_newnote');
      
      $submenuListUser[] = array ('name' => 'Meine Pinnwandeinträge', 'link' => '_notesgrid', 'command' => 'my');
      if ($this->isGranted('ROLE_SYSTEM_ADMIN'))
      {
        $submenuListUser[] = array ('name' => 'Alle Pinnwandeinträge', 'link' => '_notesgrid', 'command' => 'all');
      }
    }
    if ($this->isGranted('ROLE_ADMIN'))
    {
      $submenuListUser[] = array ('name' => 'divider', 'link' => '');
      $submenuListUser[] = array ('name' => 'Lizenzen aller Nutzer', 'link' => '_listlicences');
      $submenuListUser[] = array ('name' => 'abgelaufene Lizenzen', 'link' => '_expiredlicences', 'command' => date('d.m.Y'));
    }
    if ($this->isGranted('ROLE_SYSTEM_ADMIN'))
    {
      $submenuListUser[] = array ('name' => 'divider', 'link' => '');
      $submenuListUser[] = array ('name' => 'Nutzerdaten verwalten', 'link' => '_usertable');
      $submenuListUser[] = array ('name' => 'Neuen Nutzer anlegen', 'link' => '_newuser');
      $submenuListUser[] = array ('name' => 'divider', 'link' => '');
      $submenuListUser[] = array ('name' => 'Mailverteiler erzeugen', 'link' => '_getusermail');
    }  
    
    if ($this->isGranted('ROLE_SYSTEM_ADMIN'))
    {
      $submenuListAircraft[] = array ('name' => 'Flugzeuge verwalten', 'link' => '_aircrafttable');
      $submenuListAircraft[] = array ('name' => 'Neues Flugzeug anlegen', 'link' => '_newaircraft');
      $submenuListAircraft[] = array ('name' => 'divider', 'link' => '');
      $submenuListAircraft[] = array ('name' => 'Flugzeugtypen verwalten', 'link' => '_aircrafttypetable');
      $submenuListAircraft[] = array ('name' => 'Neuer Flugzeugtyp', 'link' => '_newaircrafttype');
    }
    
    if ($this->isGranted('ROLE_GLOBAL_ADMIN'))
    {
      $submenuListAircraft[] = array ('name' => 'divider', 'link' => '');
      $submenuListAircraft[] = array ('name' => 'Lizentypen verwalten', 'link' => '_licencetypetable');
    $submenuListAircraft[] = array ('name' => 'Neuer Lizenztyp', 'link' => '_newlicencetype');
    }  
    
    if ($this->isGranted('ROLE_PILOT'))
    {  
      $submenuBooking[] = array ('name' => 'Neu Reservieren', 'link' => '_newbooking');
      $submenuBooking[] = array ('name' => 'divider', 'link' => '');
      $submenuBooking[] = array ('name' => 'Verfügbarkeit Fluglehrer', 'link' => '_fi_availabilitygrid', 'command' => 'view');
    }
    if ($this->isGranted('ROLE_FI'))
    {
      $submenuBooking[] = array ('name' => 'divider', 'link' => '');
      $submenuBooking[] = array ('name' => 'Eigene Verfügbarkeit neu', 'link' => '_fi_newavailability', 'command' => 'my');
      $submenuBooking[] = array ('name' => 'Eigene Verfügbarkeiten bearbeiten', 'link' => '_fi_availabilitygrid', 'command' => 'my');
    }
    
    if ($this->isGranted('ROLE_SYSTEM_ADMIN'))
    {
      $submenuBooking[] = array ('name' => 'divider', 'link' => '');
      $submenuBooking[] = array ('name' => 'Neue Verfügbarkeiten eingeben', 'link' => '_fi_newavailability', 'command' => 'all');
      $submenuBooking[] = array ('name' => 'Alle Verfügbarkeiten bearbeiten', 'link' => '_fi_availabilitygrid', 'command' => 'all');
    }
    
    if ($this->isGranted('ROLE_PILOT'))
    {  
      $menuList[] = array ('name' => 'Buchungen ansehen', 'link' => '#', 'submenu' => $submenuListBuchungen);
      $menuList[] = array ('name' => 'Reservieren', 'link' => '#', 'submenu' => $submenuBooking);

      $menuList[] = array ('name' => 'Nutzerdaten', 'link' => '#', 'submenu' => $submenuListUser);
    }
      
    if ($this->isGranted('ROLE_SYSTEM_ADMIN'))
    {  
      $menuList[] = array ('name' => 'Flugzeuge verwalten', 'link' => '#', 'submenu' => $submenuListAircraft);
    }  
    
    // Nur wenn Flyres nicht in einem IFrame läuft, wird der Button abmelden angezeigt. Sonst läuft das Abmelden 
    // über die Rahmenseite (Joomla basierter Frame)
    if (LogonType::isStandalone($session))
    {        
      $menuList[] = array ('name' => 'Abmelden', 'link' => 'app_logout');
    }
    
    return $menuList;
  }
    
    public function ViewAction(Request $request)
    {
      $session = $request->getSession();
      
      $menu = $this->GetMenu ($session);

      return $this->render('menuview/menuview.html.twig', array('menu' => $menu));
     
    }
}
