<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use App\ViewHelper;
use Symfony\Component\HttpFoundation\Request;
use App\Entities\Licenses;
use App\Entities\Users;
use APY\DataGridBundle\Grid\Source\Entity;
use APY\DataGridBundle\Grid\Action\RowAction;
use APY\DataGridBundle\Grid\Column\ActionsColumn;
use APY\DataGridBundle\Grid\Grid;
use Symfony\Component\Security\Core\User\UserInterface;

use Symfony\Component\Form\FormError;
use App\Entity\FresUserlicences;
use Symfony\Component\Mailer\MailerInterface;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;

use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;


class LicenceController extends AbstractController
{
  const LicenceDateFormat1 = 'dd.MM.yyyy';  // Darstellung für DateTime->createFromFormat
  const VaildUntil_Null = "01.01.0000";
  const VaildUntil_Null_1 = "00.00.0000";
  
  public static function ChangeValidUntil_NotNull()
  {
    $datetime = new DateTime();
    $newDate = $datetime->createFromFormat('d.m.Y', LicenceController::VaildUntil_Null);  
    return $newDate;
  }
      
  public static function ChangeValidUntil_Null($validuntil)
  {
    // Wenn in der Datenbank ein Datetime mit Null belegt ist kann danach nicht gefilter werden
    // Daher setzen wir bei VaildUntil 01.01.0000 als Datum statt NUll ein
    // Dise Funktion tauscht vor der Editierung odervor dem Senden der Mail 01.01.0000 durch NULL aus
    if($validuntil == NULL) return NULL;
    $string = date_format($validuntil, 'd.m.Y');
    if ($string == LicenceController::VaildUntil_Null || $string == LicenceController::VaildUntil_Null_1) 
      return Null;
    return $validuntil;
  }
  
  public function BuildForm($em, $userLicence)
  {  
    $userLicence->setValiduntil($this->ChangeValidUntil_Null($userLicence->getValiduntil()));
    
    $builder = $this->createFormBuilder($userLicence)
        ->add('clientid', HiddenType::class) 
        ->add('accountid', HiddenType::class) 
        ->add('licenceid', ChoiceType::class, array ('choices' => Licenses::GetAllLicenceTypes($em))) 
        ->add('description', TextareaType::class, array('mapped' => false, 'data' => $userLicence->getLicence()->getDescription(),
                                                'attr' => array('readonly' => true, 'cols' => '60', 'rows' => '5')))   
        ->add('validunlimited', CheckboxType::class)       
        ->add('validuntil', DateTimeType::class, array('format' => LicenceController::LicenceDateFormat1, 'widget' => 'single_text', 'html5' => false))    
        ->add('comment', TextareaType::class, array('attr' => array('cols' => '60', 'rows' => '3')))
        ->add('wahrheitsgemaess', CheckboxType::class, array('mapped' => false))
        ->add('status', HiddenType::class)   ;
    
    $form = $builder->getForm();  
    return $form;
  }
  
  public function ShowForm($form, $allowDelete = true)
  {
    $data = array('form' => $form->createView());
    // Soll die Schaltfläche zum Löschen angezeigt werden?
    $data = array_merge(array('allowdelete' => $allowDelete), $data);
    return $this->render('editlicence/editlicence.html.twig', $data);
  }

  public function GlobalWithIDAction(Request $request, $id, UserInterface $loggedin_user, EntityManagerInterface $em)
  {
    // Einstiegspunkt für die Lizenzliste, bei der die ID des zu bearbeiteten Lizenz übergeben wird
    $em->getConnection()->exec('SET NAMES "UTF8"');
    // aktuellen Nutzer ermittelen
    if ($loggedin_user)
    {  
      // zu bearbeitende Lizenz ermitteln
      $userLicence = Licenses::GetUserLicenceObject($em, $loggedin_user->getClientid(), (int) $id);
      if ($userLicence != null)
      {
        // Edtieren nur zulassen, wenn der Nutzer ein Admin ist oder die Lizenz zum Nutzer gehört
        if ($this->isGranted('ROLE_ADMIN') or ($userLicence->getAccountid() ==  $loggedin_user->getId()))
        {
          $sd = ViewHelper::GetSessionDataObject($request->getSession());
          $sd->SetUserLicenceID($id);
          ViewHelper::StoreSessionDataObject($request->getSession(), $sd);
          return $this->forward('App\Controller\LicenceController::EditAction');
        } else die ("Fehler, keine Berechtigung zum Aendern der Lizenz");  
      } else die ("Fehler, die Lizenz existiert nicht");
    } else die ("Fehler, der Nutzer existiert nicht");
  }

  public function NewLicenceWithIDAction(Request $request, $id, UserInterface $loggedin_user, EntityManagerInterface $em)
  {
    // Einstiegspunkt für das Erstellen einer neuen Lizenz, bei der die ID des Nutzers übergeben wird
    $em->getConnection()->exec('SET NAMES "UTF8"');
    if ($this->isGranted('ROLE_ADMIN'))
    { 
      //$em = $this->getDoctrine()->getManager();
      $sd = ViewHelper::GetSessionDataObject($request->getSession());
      $sd->SetUserLicenceID($id);
      ViewHelper::StoreSessionDataObject($request->getSession(), $sd);

      $response = $this->forward('App\Controller\LicenceController::NewUserLicenceAction', array('accountID'  => $id));
      
      $response->setExpires(new \DateTime());
      return $response;
    }
    die('unerlaubter Zugriff!');  
  }
  
  public function GlobalAction(Request $request)
  {
    // Das ist der Einstiegspunkt aus dem Formular aus dem das Flugzeug bearbeitet werden kann. Je nach verwendeter Schaltfläche
    // wird die entsprechende Aktion ausgelöst
    $sd = ViewHelper::GetSessionDataObject($request->getSession());
    if($request->get('Speichern')) return $this->forward('App\Controller\LicenceController::SaveAction');
    if($request->get('Löschen')) return $this->forward('App\Controller\LicenceController::DeleteAction');
    if($request->get('Zurück')) return $this->redirect($sd->GetBookingDetailBackRoute());
    // Am Feld licence_id_onchnage wird erkannt, dass OnChange in der Listbox eingegeben wurde 
    if($request->get('licence_id_onchnage')) return $this->forward('App\Controller\LicenceController::LicenceChangedAction');
    
    return $this->forward('App\Controller\LicenceController::EditAction');
  }

  protected function CreateNewLicenceObject(Request $request, $loggedin_user, EntityManagerInterface $em, $accountID = null)
  {
    $em->getConnection()->exec('SET NAMES "UTF8"');

    $userLicence = new FresUserlicences();
    $userLicence->setClientid($loggedin_user->getClientid());
    $userLicence->setLicenceid(1);
    $userLicence->setLicence(Licenses::GetLicenceTypeObject($em, 1));
    $userLicence->setStatus(0); // Lizenz als nicht gelöscht markieren
    
    if ($accountID != null)
    {
      $userLicence->setAccountid($accountID);
    }
    else
    {
      if ($loggedin_user)
      {  
        $userLicence->setAccountid($loggedin_user->getId());
      }
      else die('Die eigene Benutzerkennung konnte nicht emittelt werden. Der Vorgang wurde abgebrochen');
    }
    return $userLicence;
  }

  public function NewUserLicenceAction(Request $request, UserInterface $loggedin_user, EntityManagerInterface $em, $accountID = null)
  {
    // TODO: Mit NewAction (unten) zusammenfassen
   
    $sd = ViewHelper::GetSessionDataObject($request->getSession());
    $sd->SetUserLicenceID(0);
    ViewHelper::StoreSessionDataObject($request->getSession(), $sd);
    
    $userLicence = $this->CreateNewLicenceObject($request, $loggedin_user, $accountID);
    $form = $this->BuildForm($em, $userLicence);
    $response = $this->ShowForm($form, FALSE);
    $response->setExpires(new \DateTime());
    return $response;
  }

  public function NewAction(Request $request, UserInterface $loggedin_user, EntityManagerInterface $em)
  {
    $em->getConnection()->exec('SET NAMES "UTF8"');
    $sd = ViewHelper::GetSessionDataObject($request->getSession());
    $sd->SetUserLicenceID(0);
    ViewHelper::StoreSessionDataObject($request->getSession(), $sd);
    
    $userLicence = $this->CreateNewLicenceObject($request, $loggedin_user);
    $form = $this->BuildForm($em, $userLicence);
    $response = $this->ShowForm($form, FALSE);
    $response->setExpires(new \DateTime());
    return $response;
  }

  public function DeleteAction(MailerInterface $mailer,  Request $request, UserInterface $loggedin_user, EntityManagerInterface $em)
  {
    $em->getConnection()->exec('SET NAMES "UTF8"');
    $sd = ViewHelper::GetSessionDataObject($request->getSession());
    $licenceID = $sd->GetUserLicenceID();
    $sd->SetUserLicenceID(0);
    ViewHelper::StoreSessionDataObject($request->getSession(), $sd);
    
    $user = $loggedin_user;
    
    // Die Lizenz aus der Datenbank laden und eine Info über die Löschung per Mail verschicken
    $userLicence_old = Licenses::GetUserLicenceObject($em, $loggedin_user->getClientid(), $licenceID);  
    
    $parameter['program_version'] = $this->getParameter('program_version');
    $parameter['mail_from'] = $this->getParameter('mail_from');
    $twig = $this->container->get('twig');
    
    Licenses::SendLicenceInfoMail($em, $user, $twig, NULL, $userLicence_old, $mailer, $parameter);

    Licenses::DeleteLicence($em, $loggedin_user->getClientid(), $licenceID);
    return $this->redirect($sd->GetBookingDetailBackRoute());
  }

  public function SaveAction(MailerInterface $mailer, Request $request, UserInterface $loggedin_user, )
  {
    $em->getConnection()->exec('SET NAMES "UTF8"');
    $sd = ViewHelper::GetSessionDataObject($request->getSession());
    $licenceID = $sd->GetUserLicenceID();
    
    if ($licenceID != 0)
    {
      $userLicence = Licenses::GetUserLicenceObject($em, $loggedin_user->getClientid(), $licenceID);
      // Kopie des Objektes für den Mailversand erzeugen (Vergleich Alt gegen Neu)
      $userLicence_old = clone $userLicence;
    }
    else {
      $userLicence = $this->CreateNewLicenceObject($request, $loggedin_user);
      $userLicence_old = NULL;
    }
    
    $form = $this->BuildForm($em, $userLicence);
    $form->handleRequest($request);
    //$form->bind($request);
    
    $validUnlimitedCheckBox = $form->get('validunlimited')->getData();
    $validuntil = $form->get('validuntil')->getData();
    if ($validUnlimitedCheckBox  == FALSE && !ViewHelper::IsDateCorrect($validuntil))    
      $form->addError(new FormError("Bitte geben sie eine Gültigkeit der Lizenz ein"));
    
    $myCheckBox = $form->get('wahrheitsgemaess')->getData();
    if ($myCheckBox == FALSE) $form->addError(new FormError("Bitte bestätigen Sie, dass alle Angaben wahrheitsgemäß erfolgt sind"));
    if (Licenses::LicenceTypeExistsForUser($em, $userLicence->getId(), $userLicence->getAccountid(), $userLicence->getLicenceid()))
      $form->addError(new FormError("Dieser Lizenztyp existiert bereits in ihrer Lizenzliste. Bitte ändern den bereits vorhandenen Lizenztyp."));        
    
    if ($form->isValid()) 
    {
      // Lizenz ist vollständig und wird gespeichert
      $userLicence = $form->getData();
      
      if ($userLicence->getComment() == Null) $userLicence->setComment ('');
      
      if ($licenceID == 0)
      {  
        // es wurde ein neues Objekt erzeugt, das nur gespeichert werden kann, wenn die
        // One to One Beziehungen zu User und Licence zugehörige Daten beinhalten
        // Wraum das so ist weiß ich auch nicht, aber nur so funktioniert das Speichern
        $user = Users::GetUserObject ($em, $userLicence->getClientid(), $userLicence->getAccountid());
        $userLicence->setUser($user);
        $userLicence->setLicence(Licenses::GetLicenceTypeObject($em, $userLicence->getLicenceid()));
      } 
      
      if ($userLicence->getValidunlimited() == true)
        $userLicence->setValiduntil ($this->ChangeValidUntil_NotNull ());
    
      $em->persist($userLicence);
      $em->flush();
      
      $user = $loggedin_user;
      
      // Nachricht über die Änderung per Mail versenden
              
      $parameter['program_version'] = $this->getParameter('program_version');
      $parameter['mail_from'] = $this->getParameter('mail_from');
      $twig = $this->container->get('twig'); 
      
      Licenses::SendLicenceInfoMail($em, $user, $twig, $userLicence, $userLicence_old, $mailer, $parameter);

      return $this->redirect($sd->GetBookingDetailBackRoute());
    }
    return $this->ShowForm($form);
  }

  public function EditAction(Request $request, UserInterface $loggedin_user, EntityManagerInterface $em, $allowDelete = TRUE)
  {
    $em->getConnection()->exec('SET NAMES "UTF8"');
    $sd = ViewHelper::GetSessionDataObject($request->getSession());
    $licenceID = $sd->GetUserLicenceID();
   
    $userLicence = Licenses::GetUserLicenceObject($em, $loggedin_user->getClientid(), $licenceID);
    
    $form = $this->BuildForm($em, $userLicence);
    
    return $this->ShowForm($form, $allowDelete);  
   }

  public function LicenceChangedAction(Request $request, UserInterface $loggedin_user, EntityManagerInterface $em)
  {
    // Diese Funktion wird aufgerufen, wenn in der Auswahlbox für die Lizenz etwas geändert wird
    $em->getConnection()->exec('SET NAMES "UTF8"');
    $sd = ViewHelper::GetSessionDataObject($request->getSession());
    $licenceID = $sd->GetUserLicenceID();
   
    // Das alte Objekt laden
    if ($licenceID != 0) $userLicence = Licenses::GetUserLicenceObject($em, $loggedin_user->getClientid(), $licenceID);
    else $userLicence = $this->CreateNewLicenceObject($request, $loggedin_user);
    
    $form = $this->BuildForm($em, $userLicence);
    //$form->bind($request);
    $form->handleRequest($request);
    
    // Die Daten aus dem Formular laden
    $userLicence = $form->getData();
    // Der Lizenztyp hat sich geändert, daher die Beschreibung neu laden
    $userLicence->setLicence(Licenses::GetLicenceTypeObject($em, $userLicence->getLicenceid()));
    
    $form = $this->BuildForm($em, $userLicence);
 
    return $this->ShowForm($form, FALSE);  
  }

  public function GridWithIDAction(Request $request, EntityManagerInterface $em, $id)
  {
    $em->getConnection()->exec('SET NAMES "UTF8"');
    if ($this->isGranted('ROLE_ADMIN'))
    { 
      // Einstiegspunkt für die Lizenzliste, bei der die ID des zu bearbeiteten Lizenz übergeben wird
      //$em = $this->getDoctrine()->getManager();
      $sd = ViewHelper::GetSessionDataObject($request->getSession());
      $sd->SetUserID($id);
      ViewHelper::StoreSessionDataObject($request->getSession(), $sd);
      
      return $this->forward('App\Controller\LicenceController::GridAction', array('id' => $id)); // geht nicht, wegen eines Fehlers in Symfony
    }
    die('unerlaubter Zugriff!');  
  }
  
 
  protected static function GetRowFunction()
  {
    // gibt die Function zur Färbung der Gridzeilen zurück 
    return $func = function ($row)
    {
      $dategreen = new \DateTime;
      $dateamber = new \DateTime;
      $dategreen->setTimestamp(mktime(0, 0, 0, date("m")+12, date("d"), date("Y")));
      $dateamber->setTimestamp(mktime(0, 0, 0, date("m")+3, date("d"), date("Y")));

      if ($row->getField('validunlimited') !=  0){
          $row->setField('validuntil', '31.12.9999');
          $row->setColor('#01DF3A');
          return $row;  
      } 

      if ($row->getField('validuntil') >  $dategreen){
          $row->setColor('#01DF3A');
          return $row;  
      }    
      if ($row->getField('validuntil') >  $dateamber){
          $row->setColor('#FFBF00');
          return $row; 
      }
      $row->setColor('#FA5858');
      return $row; 
    };
  }

  public function GridAction(Request $request, Grid $grid, UserInterface $loggedin_user, EntityManagerInterface $em, $id = NULL, $standalone = TRUE)
  {
    // Standalone = 0 zeigt an, dass die Lizenzen als Ergänzung zur Buchungsliste 
    // ohne Änderungsmöglichkeit angezeigt werden
    $em->getConnection()->exec('SET NAMES "UTF8"');
    
    // Route explizit setzen, damit das Grid nach einem Forward keine Fehlermeldung produziert (Workaround)
    $request->attributes->set('_route', '_licencetable');

    //$em = $this->getDoctrine()->getManager();

    $sd = ViewHelper::GetSessionDataObject($request->getSession());
    $str = $request->attributes->get('_route');
    
    if ($id != null) $substr = '/' . $id;
      else $substr = '';
    $route = $this->generateUrl($str) . $substr;
    if (isset($str)) $sd->SetBookingDetailBackRoute($route);
    
    
    // Diese Zeile ist wichtig, damit das Grid auch aus einem Template mit Render ausgegeben wird
    $grid->setRouteUrl($route);
    
    ViewHelper::StoreSessionDataObject($request->getSession(), $sd);
    
    // den User ermitteln entweder der aktuelle User
    if ($id == NULL) $user = $loggedin_user;
      // oder der User der über $id übergeben wurde
      else $user = Users::GetUserObject ($em, $loggedin_user->getClientid(), $id);
      
    // Creates a simple grid based on your entity (ORM)
    $source = new Entity('App\Entity\FresUserlicences');
    
    // Zeilen einfärben in Abhängigkeit der verbleibenden Gültigkeit der Lizenz einfärben
    $source->manipulateRow(self::GetRowFunction());
    
    // Attach the source to the grid
    $grid->setSource($source);
    
    // Datensätze die als gelöscht markiert sind nicht mit einlesen
    // Und nur die Daten des angemeldeten Nutzers anzeigen
    $userID = $user->getId();
 
    $grid->setPermanentFilters(array('status' => array('operator' => 'neq', 'from' => 'geloescht'),
                                     'accountid' => array('operator' => 'eq', 'from' => $userID),
                                     'clientid' => array('operator' => 'eq', 'from' => $loggedin_user->getClientid())));
    
    $grid->setDefaultOrder('licence.categoryid', 'ASC');
     
    // Filter bleibt immer gesetzt und wird im Session Objekt gespeichert
    $grid->setPersistence(true);
    
    $grid->setNoDataMessage('Keine Lizenzdaten gefunden!');
    $grid->setNoResultMessage('Keine Lizenzdaten gefunden!');
    
    $grid->setLimits(array(25, 50, 100, 200, 400));
    $grid->setDefaultLimit(50);
    
    $grid->hideColumns(array('user.firstname', 'user.lastname'));
    
    // Wenn Lizenzen additiv im unteren Bereich angezeigt werden sollen sie nicht änderbar sein
    if ($standalone != false)
    {
      // Zeile ohne Titel hinzufügen die dann für das Einfügen des Bearbeiten-Links verwendet wird
      $actionsColumn = new ActionsColumn('work_column', '');
      // ganz hinten einfügen
      $grid->addColumn($actionsColumn, 100);

      $rowAction = new RowAction('Bearbeiten', '_editlicencewithid');
      $rowAction->setColumn('work_column');
      $rowAction->setRouteParameters(array('id'));
      $grid->addRowAction($rowAction);
    }

    $userName = $user->getFirstname() . ' ' . $user->getLastname();
    // Return the response of the grid to the template
    
    if ($standalone == false) $response = $grid->getGridResponse('editlicence/licencetable_core.html.twig', array('name' => $userName));
      else $response = $grid->getGridResponse('editlicence/licencetable_standalone.html.twig', array('name' => $userName));
      
    $response->setExpires(new \DateTime());
    return $response;  
  }

  public function LicenceListAction(Request $request, Grid $grid, UserInterface $loggedin_user, $command = NULL)
  {
    // Diese Liste zeigt wahlweise alle Lizenzen oder alle abgelaufenen Lizenzen an
    if ($this->isGranted('ROLE_ADMIN'))
    { 
      $sd = ViewHelper::GetSessionDataObject($request->getSession());
      $route = $request->get('_route');
      if ($command != null) $route = $this->generateUrl($route, array('command' => $command));
        else $route = $this->generateUrl($route);
      if (isset($route)) $sd->SetBookingDetailBackRoute($route);
      ViewHelper::StoreSessionDataObject($request->getSession(), $sd);

      $em = $this->getDoctrine()->getManager();

      // Creates a simple grid based on your entity (ORM)
      $source = new Entity('App\Entity\FresUserlicences');

      // Zeilen einfärben in Abhängigkeit der verbleibenden Gültigkeit der Lizenz einfärben
      if ($command == NULL) $source->manipulateRow(self::GetRowFunction());

      // Attach the source to the grid
      $grid->setSource($source);
      $grid->showColumns('validunlimited');
      $grid->showColumns('validuntil');

      $grid->getColumn('user.firstname')->setFilterable(TRUE);
      $grid->getColumn('user.lastname')->setFilterable(TRUE);
      $grid->getColumn('licence.categoryname')->setFilterable(TRUE);
      $grid->getColumn('licence.longname')->setFilterable(TRUE);
      $grid->getColumn('validunlimited')->setFilterable(TRUE);
      $grid->getColumn('validuntil')->setFilterable(TRUE);

      // Datensätze die als gelöscht markiert sind nicht anzeigen

      $filter['status'] = array('operator' => 'neq', 'from' => 'geloescht');
      $filter['clientid'] = array('operator' => 'eq', 'from' => $loggedin_user->getClientid());
      if ($command != NULL)
      {
        $filter['validunlimited'] = array('operator' => 'neq', 'from' => '0');
        $filter['validuntil'] = array('operator' => 'lte', 'from' => date("Y-m-d"));
      }
      $grid->setPermanentFilters($filter);
      //$grid->getPersistence();

      $grid->setDefaultOrder('user.lastname', 'ASC');

      // Filter bleibt immer gesetzt und wird im Session Objekt gespeichert
      //$grid->setPersistence(true);  // Das funktiniert mit dem Wechsel der Nutzng der beiden Listen nicht

      $grid->setNoDataMessage('Keine Lizenzdaten gefunden!');
      $grid->setNoResultMessage('Keine Lizenzdaten gefunden!');

      $grid->setLimits(array(25, 50, 100, 200, 400));
      $grid->setDefaultLimit(400);

      $actionsColumn = new ActionsColumn('work_column', '');
      // ganz hinten einfügen
      $grid->addColumn($actionsColumn, 100);

      $rowAction = new RowAction('Bearbeiten', '_editlicencewithid');
      $rowAction->setColumn('work_column');
      $rowAction->setRouteParameters(array('id'));
      $grid->addRowAction($rowAction);

      $response = $grid->getGridResponse('editlicence/licencetable_standalone.html.twig', array('name' => ''));
      
      $response->setExpires(new \DateTime());
      return $response;  
    }  
  }
}



