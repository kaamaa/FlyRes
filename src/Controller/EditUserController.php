<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
//use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use App\ViewHelper;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use App\Entities\Users;
use App\Entities\Bookings;
use App\Entity\FresAccounts;
use App\Entities\Functions;
use Symfony\Component\Form\FormError;
use APY\DataGridBundle\Grid\Source\Entity;
use APY\DataGridBundle\Grid\Action\RowAction;
use APY\DataGridBundle\Grid\Column\ActionsColumn;
use Doctrine\ORM\EntityRepository;
use APY\DataGridBundle\Grid\Source\Vector;
use APY\DataGridBundle\Grid\Column;
use APY\DataGridBundle\Grid\Grid;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Security\Core\User\UserInterface;
use Doctrine\ORM\EntityManagerInterface;

class EditUserController extends AbstractController
{
  public static $em;
  
  public function BuildForm($em, $user, $loggedin_user)
  { 
    // Auswahl ob der Nutzer Mails erhalten möchte
    $mail_choice = array('nein' => 0, 'ja' => 1);
    if (Users::isAdmin($em, $user))
    {
      // Adminstratoren haben die Möglichkeit auch fremde Reservierungen / Lizenzen zu erhalten
      $mail_choice = array('keine' => 0, 'nur eigene' => 1, 'alle' => 2);
    } 
    $user_auth = $loggedin_user;
    
    $fb = $this->createFormBuilder($user); 
    
    $fb
        ->add('id', TextType::class, array ('attr' => array('readonly' => true)))
        ->add('firstname', TextType::class)
        ->add('lastname', TextType::class)  
        ->add('username', TextType::class)  
        ->add('password', PasswordType::class, array ('mapped' => false)) 
        ->add('password_check', PasswordType::class, array ('mapped' => false))
        ->add('email', TextType::class)   
        ->add('phone_number_home', TextType::class)   
        ->add('phone_number_office', TextType::class)    
        ->add('phone_number_mobile', TextType::class)
        ->add('getbookingmails', ChoiceType::class, array ('choices' => $mail_choice))
        ->add('getlicencemails', ChoiceType::class, array ('choices' => $mail_choice))
        ->add('status', HiddenType::class); 
        
        if (Users::isFlightinstructor($em, $user->getId())) // nicht is_granted weil FI auc in der Hierarchie der Admin ist
        { 
          //$fb->add('fiallwaysavailable', 'checkbox'); 
          $fb->add('fiallwaysavailable', ChoiceType::class, array ('choices' => Users::GetFlightinstructorAvailabilities($em, $user->getId(), $user_auth)));
          $fb->add('fiparallelbookings', CheckboxType::class);
          $fb->add('fibookableifonrequest', CheckboxType::class);
        
        }
        
        if ($this->isGranted('ROLE_SYSTEM_ADMIN'))
        { 
          // Nur Rechte bis zum Systemadministrator dürfen vergeben werden (priority < 6), 
          // es sei denn der Nutzer ist selber Global Administrator
          $functionlimit = 6;
          if ($this->isGranted('ROLE_GLOBAL_ADMIN')) $functionlimit = 7;
          
          $fb->add('islocked', CheckboxType::class);  
          $fb->add('function', EntityType::class, array(
              'class' => 'App\Entity\FresFunction',
              'choice_label' => 'function',
              'expanded' => true,
              'multiple' => true,
              'query_builder' => function(EntityRepository $er) use ($functionlimit)
              {
                return $er->createQueryBuilder('u')
                        ->add('orderBy', 'u.priority ASC')
                        ->where('u.priority < ' . $functionlimit);
              }
              ));  
           
        }
          
    return $fb->getForm();
  }

  public function ShowForm($form, $user, EntityManagerInterface $em, $allowDelete = true)
  {
    // Anzahl der Buchungen für den Nutzer ermitteln
    $data = Bookings::CountAllBookingsForAUser($em, $user->getClientid(), $user->getId());
    // Basisdaten für das Formual ermittekn
    $data = array_merge(array('form' => $form->createView()), $data);
    // Soll die Schaltfläche zum Löschen angezeigt werden?
    $data = array_merge(array('allowdelete' => $allowDelete), $data);
    // Soll die Schaltfläche für doppelte Buchungen für Fluglehrer angezeigt werden?
    $data = array_merge(array('isFi' => Users::isFlightinstructor($em, $user->getId())), $data);
    return $this->render('edituser/edituser.html.twig', $data); 
  }
 
  public function MyDataAction(Request $request, UserInterface $loggedin_user)
  {
    // Einstiegspunkt um die eigenen Daten zu editieren
    $sd = ViewHelper::GetSessionDataObject($request->getSession());
    $sd->SetBookingDetailBackRoute('weeksview');
    
    $user = $loggedin_user;
    if ($user) $sd->SetUserID($user->getId());
    ViewHelper::StoreSessionDataObject($request->getSession(), $sd);
    
    return $this->forward('App\Controller\EditUserController::EditAction', array('allowDelete'  => FALSE));
  }
  
  public function GlobalWithIDAction(Request $request, $id)
  {
    // Einstiegspunkt für die Benutzerliste, bei der die ID des zu bearbeiteten Nutzers übergeben wird
    if ($this->isGranted('ROLE_ADMIN'))
    { 
      $sd = ViewHelper::GetSessionDataObject($request->getSession());
      $sd->SetUserID($id);
      ViewHelper::StoreSessionDataObject($request->getSession(), $sd);
      return $this->forward('App\Controller\EditUserController::EditAction');
    }
    die('unerlaubter Zugriff!');
  }
  
  public function GlobalAction(Request $request)
  {
    // Das ist der Einstiegspunkt aus dem Formulain dem der Nutzer bearbeitet werden kann. Je nach verwendeter Schaltfläche
    // wird die entsprechende Aktion ausgelöst
    $sd = ViewHelper::GetSessionDataObject($request->getSession());
    if($request->get('Speichern')) return $this->forward('App\Controller\EditUserController::SaveAction');
    if($request->get('Löschen')) return $this->forward('App\Controller\EditUserController::DeleteAction');
    if($request->get('Zurück')) return $this->redirect($sd->GetBookingDetailBackRoute());
   
    return $this->forward('App\Controller\EditUserController::EditAction');
  }

  public function DeleteAction(Request $request, UserInterface $loggedin_user, EntityManagerInterface $em)
  {
    $sd = ViewHelper::GetSessionDataObject($request->getSession());
    $userid = $sd->GetUserID();
    
    Users::DeleteUser($em, $loggedin_user->getClientid(), $userid);
    
    if ($userid != 0) 
    {
      // Nutzer lesen
      $user = Users::GetUserObject($em, $loggedin_user->getClientid(), $userid);
    }
    
    return $this->redirect($sd->GetBookingDetailBackRoute());
  }

  public function EditAction(Request $request, UserInterface $loggedin_user, EntityManagerInterface $em, $allowDelete = true)
  {
    $em->getConnection()->exec('SET NAMES "UTF8"');

    $sd = ViewHelper::GetSessionDataObject($request->getSession());
    $userid = $sd->GetUserID();
    if ($userid != 0) 
    {
      $user = Users::GetUserObject($em, $loggedin_user->getClientid(), $userid);
      
      // Editieren von fremden Nutzern nur durch Admistratoren zulassen
      if ($user != $loggedin_user && !$this->isGranted('ROLE_ADMIN'))
        die('unerlaubter Zugriff!');
      
      $form = $this->BuildForm($em, $user, $loggedin_user);
      $response = $this->ShowForm($form, $user, $em, $allowDelete);
      $response->setExpires(new \DateTime());
      return $response;
    } 
    else die('Fehler: Aktueller User nicht gefunden!');
  }
  
  protected function CreateUser(Request $request, UserInterface $loggedin_user)
  {
    $user = new FresAccounts(); 
    $user->setClientid($loggedin_user->getClientid());
    // Flugschüler soll standardmäßig bei neuen Usern vorbelegt sein
    //$user->setFunction(2);
    $user->setStatus(0);
    $user->setGetbookingmails(true);
    $user->setGetlicencemails(true);
    return $user;
  }

  public function NewAction(Request $request, UserInterface $loggedin_user, EntityManagerInterface $em)
  {
    $em->getConnection()->exec('SET NAMES "UTF8"');
    $sd = ViewHelper::GetSessionDataObject($request->getSession());
    $sd->SetUserID(0);
    ViewHelper::StoreSessionDataObject($request->getSession(), $sd);
    
    $user = $this->CreateUser($request, $loggedin_user);
    
    $form = $this->BuildForm($em, $user, $loggedin_user);
    $response = $this->ShowForm($form, $user, $em, FALSE);
    $response->setExpires(new \DateTime());
    return $response;
  }

  public function SaveAction(Request $request, UserInterface $loggedin_user, EntityManagerInterface $em, UserPasswordHasherInterface $passwordEncoder)
  {
    // Wird ausgefrufen, wenn der Save-Button gedrückt wurde
    $em->getConnection()->exec('SET NAMES "UTF8"');
    $sd = ViewHelper::GetSessionDataObject($request->getSession());
    
    // UserID des bisherigen Datensatzes aus dem Sessionobjekt holen
    $userid = $sd->GetUserID();
    if ($userid != 0) {
      // Datensatz lesen
      $user = Users::GetUserObject($em, $loggedin_user->getClientid(), $userid);
    } else {
      // neuen Datensatz erzeugen
      $user = $this->CreateUser($request, $loggedin_user);
    }
   
    // Formular erzeugen und an den Request anbinden
    $form = $this->BuildForm($em, $user, $loggedin_user);
    
    //$form->bind($request);
    $form->handleRequest($request);
    
    // Prüfen on es den User schon gibt wenn ein neuer Datensatz erzeugt wurde
    if ($userid == 0 && Users::GetUserObjectByName($em, $form["username"]->getData(), $loggedin_user->getClientid()) != null)
      $form->addError(new FormError('Diesen Nutzernamen gibt es bereits'));

    // Prüfen ob die Mailadresse gültig ist
    $mail = trim($form["email"]->getData());
    if (empty($mail) or !Users::IsMailListValid($mail))
      $form->addError(new FormError('Die Mailadresse ist nicht gültig'));      
      
    // Passwort prüfen
    $pass = trim($form["password"]->getData());
    $hasErrors = Users::IsPasswordOK($form, $pass, $form["password_check"]->getData(), $user->getPassword());
    
    if ($form->isValid() && !$hasErrors) 
    {
      if (isset($pass) && strlen($pass) > 0) {
        $user->setPassword(Users::CreateNewPassword($loggedin_user, $passwordEncoder, $pass));
      }
   
      /*
      if ($user->GetId() == $this->get('security.token_storage')->getToken()->getUser()->getId() &&
          $user->getUsername() != $this->get('security.token_storage')->getToken()->getUser()->getUsername())
      {
        // Der Nutzername wurde verändert
        $suser = $this->get('security.token_storage')->getToken()->getUser();
        $suser->setUsername($user->getUsername());
      }
      */
      $current = $this->getUser();
      if ($current instanceof \App\Entity\User && 
          $user->getId() === $current->getId() && 
          $user->getUsername() !== $current->getUsername()) 
      {
        // Der Nutzername wurde verändert
        $current->setUsername($user->getUsername());
      }
      
      // Buchung ist vollständig und wird gespeichert
      $user = $form->getData();
      
      $em->persist($user);
      $em->flush();
      
      return $this->redirect($sd->GetBookingDetailBackRoute());
    }
    return $this->ShowForm($form, $user, $em);
  }

  public function GridAction(RequestStack $requestStack, grid $grid, UserInterface $loggedin_user, EntityManagerInterface $em)
  {
    $request = $requestStack->getCurrentRequest();
    self::$em = $em;
    
    $sd = ViewHelper::GetSessionDataObject($request->getSession());
    $str = $request->attributes->get('_route');
    if (isset($str)) $sd->SetBookingDetailBackRoute($this->generateUrl($str));
    ViewHelper::StoreSessionDataObject($request->getSession(), $sd);
      
    // Creates a simple grid based on your entity (ORM)
    //$source = new Entity('App\Entity\FresAccounts');
    
    $columns = array(
        new Column\NumberColumn(array('id' => 'id', 'field' => 'id', 'source' => true, 'primary' => true, 'title' => 'Kunden ID')),
        new Column\TextColumn(array('id' => 'firstname', 'field' => 'firstname', 'source' => true, 'title' => 'Vorname')),
        new Column\TextColumn(array('id' => 'lastname', 'field' => 'lastname', 'source' => true, 'title' => 'Nachname')),
        new Column\TextColumn(array('id' => 'username', 'field' => 'username', 'source' => true, 'title' => 'Nutzername')),
        new Column\TextColumn(array('id' => 'email', 'field' => 'email', 'source' => true, 'title' => 'Mailadresse')),
        new Column\ArrayColumn(array('id' => 'function', 'field' => 'function', 'source' => true, 'title' => 'Berechtigungen', 'filter' => 'select', 'selectFrom' => 'values', 'values' => Functions::GetAllFunctionNames($em))),
        new Column\BooleanColumn(array('id' => 'islocked', 'field' => 'islocked', 'source' => true, 'title' => 'Ist der Nutzer gesperrt?')),
        new Column\TextColumn(array('id' => 'phoneNumberHome', 'field' => 'phoneNumberHome', 'source' => true, 'title' => 'Telefonnummer Privat')),
        new Column\TextColumn(array('id' => 'phoneNumberOffice', 'field' => 'phoneNumberOffice', 'source' => true, 'title' => 'Telefonnummer Büro')),
        new Column\TextColumn(array('id' => 'phoneNumberMobile', 'field' => 'phoneNumberMobile', 'source' => true, 'title' => 'Telefonnummer Mobil')),
        new Column\NumberColumn(array('id' => 'clientid', 'field' => 'clientid', 'source' => true, 'visible' => false)),
        new Column\TextColumn(array('id' => 'status', 'field' => 'status', 'source' => true, 'visible' => false)),
    );
    
    $users = Users::GetAllUsers($em, $loggedin_user->getClientid());
    $source = new Vector($users, $columns);
    
    // Attach the source to the grid
    $grid->setSource($source);
    
    // Filter bleibt immer gesetzt und wird im Session Objekt gespeichert
    $grid->setPersistence(true);
    
    // Datensätze die als gelöscht markiert sind nicht mit einlesen
    $grid->setPermanentFilters(array('status' => array('operator' => 'neq', 'from' => 'geloescht'),
                                    'clientid' => array('operator' => 'eq', 'from' => $loggedin_user->getClientid())));
    
    $grid->setNoDataMessage('Keine Daten gefunden!');
    
    $grid->setLimits(array(25, 50, 100, 200, 400));
    $grid->setDefaultLimit(50);
    
    // Zeile ohne Titel hinzufügen die dann für das Einfügen des Bearbeiten-Links verwendet wird
    $actionsColumn = new ActionsColumn('work_column', '');
    // ganz hinten einfügen
    $grid->addColumn($actionsColumn, 100);
    
    $rowAction = new RowAction('Nutzer Bearbeiten', '_edituserwithid');
    $rowAction->setColumn('work_column');
    $rowAction->setRouteParameters(array('id'));
    $grid->addRowAction($rowAction);
    
    $rowAction1 = new RowAction('Lizenzen Bearbeiten', '_licencetablewithid');
    $rowAction1->setColumn('work_column');
    $rowAction1->setRouteParameters(array('id'));
    $grid->addRowAction($rowAction1);
    
    $rowAction2 = new RowAction('Neue Lizenzen', '_newlicencewithid');
    $rowAction2->setColumn('work_column');
    $rowAction2->setRouteParameters(array('id'));
    $grid->addRowAction($rowAction2);

    // Return the response of the grid to the template
    $response = $grid->getGridResponse('edituser/usertable.html.twig');
    $response->setExpires(new \DateTime());
    return $response;
  }
  
}
