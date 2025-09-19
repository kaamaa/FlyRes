<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use App\ViewHelper;
use Symfony\Component\HttpFoundation\Request;
use App\Entity\FresFIAvailability;
use App\Entities\Users;
use App\Entities\FIAvailability;
use App\SessionData;
//use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Security\Core\User\UserInterface;
use APY\DataGridBundle\Grid\Source\Entity;
use APY\DataGridBundle\Grid\Action\RowAction;
use APY\DataGridBundle\Grid\Column\ActionsColumn;
use APY\DataGridBundle\Grid\Grid;
use Symfony\Component\Form\FormError;
use Doctrine\ORM\EntityManagerInterface;

class FIAvailabilityController extends AbstractController
{
  const BookingDateFormat1 = 'dd.MM.yyyy HH:mm'; // Darstellung für Formulare
  //const BookingDateFormat2 = 'd.m.Y H:i';  // Darstellung für DateTime->createFromFormat
  
  public function BuildForm($em, $request, $availability)
  {  
    $builder = $this->createFormBuilder($availability);
    if (ViewHelper::GetFIAvailabiltyCommand($request) == 'my')
    {
      $builder->add('flightinstructor', HiddenType::class);
    }
    else 
    {
      $builder->add('flightinstructor', ChoiceType::class, array ('choices' => Users::GetAllFlightinstructorsForListbox($em, $availability->getClientid()), 
                    'required' => false, 'placeholder' => '(kein Fluglehrer)', 'empty_data'  => null));
    }
    $builder->add('itemstart', DateTimeType::class, array('html5' => false, 'format' => FIAvailabilityController::BookingDateFormat1, 'widget' => 'single_text')); 
    $builder->add('itemstop', DateTimeType::class, array('html5' => false, 'format' => FIAvailabilityController::BookingDateFormat1, 'widget' => 'single_text'));
    $builder->add('typ', ChoiceType::class, array ('choices' => FIAvailability::GetAllAvailabilityStates($em)));  
    $builder->add('comment', TextType::class, array('label' => 'Kommentar', 'required' => false, 'attr' => array('size' => 30)));
    $form = $builder->getForm();
    return $form;
  }

  public function NewAction(Request $request, UserInterface $loggedin_user, EntityManagerInterface $em, string $command)
  {
    $em->getConnection()->exec('SET NAMES "UTF8"');

    ViewHelper::SetFIAvailabiltyCommand($request, $command);
    
    // Neue Buchungen erhalten zunächt die ID 0. Beim Speichern wird von der Datenbank die finale ID vergeben
    $sd = ViewHelper::GetSessionDataObject($request->getSession());
    $sd->SetAvailabilityID(0);
    ViewHelper::StoreSessionDataObject($request->getSession(), $sd);
    //ViewHelper::SetBookingID($request, 0);
    // new Booking
    $availability = new FresFIAvailability();
    $availability->setClientid($loggedin_user->getClientid());
    
    // Das Datum für die Buchung wird aus den Sessioninformationen zusammengebaut. 
    // Wenn z.B. bereits auf einen Tag geclicked wurde, dann ist dieser für eine neue Buchung vorbelegt
    $date_start = $sd->GetDateTime(SessionData::fi);
    $date_start->setTime(9,0);
    $availability->setItemstart($date_start);
    $date_end = $sd->GetDateTime(SessionData::fi);
    $date_end->setTime(20,30);
    $availability->setItemstop($date_end);
    $user = $loggedin_user;
    $userID = $user->getId();
    $availability->setFlightinstructor($userID);
    
    $form = $this->BuildForm($em, $request, $availability);
    $response = $this->render('fiavailability/fiavailability.html.twig', array('form' => $form->createView(), 'command' => $command));  
    $response->setExpires(new \DateTime());
    return $response;
  }

  public function SaveAction(Request $request, UserInterface $loggedin_user, EntityManagerInterface $em)
  {
    $em->getConnection()->exec('SET NAMES "UTF8"');
    $sd = ViewHelper::GetSessionDataObject($request->getSession());

    $availabilityID = $sd->GetAvailabilityID();
    $user = $loggedin_user;
    $userID = $user->getId();

    if ($availabilityID <> 0)
    {  
      // Daten für Änderungen an einer bestehenden Verfügbarkeit ändern
      // Zunächste die ursprüngliche Verfügbarkeit aus der Datenbank laden
      $availability = FIAvailability::GetAvailabilityObject($em, $loggedin_user->getClientid(), $availabilityID);
      
    }
    else
    {
      // Daten für neue Buchung eintragen
      $availability = new FresFIAvailability();
      $availability->setFlightinstructor($userID);
    
      $availability->setStatus(0);
    }
    // ClientID setzen ist unabhängig davon, ob es sich um eine neue oder bestehende Buchung handelt
    $availability->setClientid($loggedin_user->getClientid());

    $form = $this->BuildForm($em, $request, $availability);
    $form->handleRequest($request);

    // Eingegebene Daten validieren
    
    // Das Datum kann nicht aus dem $form entnommen werden, weil beim Kopieren in das Formular bereits Korrekturen durchgeführt wurden
    //$ary = $request->request->get('form');
    $dateStart = $availability->getItemstart();
    $dateEnd = $availability->getItemstop();

    //$dateStart = \DateTime::createFromFormat(EditBookingController::BookingDateFormat2, $ary['itemstart']);
    //$dateEnd = \DateTime::createFromFormat(EditBookingController::BookingDateFormat2, $ary['itemstop']);
    
    if ($dateStart == false)
    {
      $form->addError(new FormError('Das Startdatum ist kein gültiges Datum'));
    }
    if ($dateEnd == false)
    {
      $form->addError(new FormError('Das Enddatum ist kein gültiges Datum'));
    }
    if ($availability->getItemstart() >= $availability->getItemstop())
    {
      $form->addError(new FormError('Das Ende der Eintragung muss später als der Start sein'));
    }
    
    if ($request->getMethod() == 'POST') 
    {
      // Das Datum der letzten neuen Verfügbarkeit in der Session ablegen, um bei der nächsten Eingabe 
      // dort wieder zu starten
      $sd->SetYear($availability->getItemstart()->format('Y'), SessionData::fi);
      $sd->SetMonth($availability->getItemstart()->format('m'), SessionData::fi);
      $sd->SetDay($availability->getItemstart()->format('d'), SessionData::fi);
      ViewHelper::StoreSessionDataObject($request->getSession(), $sd);
    } 
    
    //Überprüfen ob der Fluglehrer schon eine überlappenden Freischaltung hat
    $overlappingerror = FIAvailability::IsOverlapping($em, $availability);
      if ($overlappingerror != null)  $form->addError(new FormError($overlappingerror));
    
    if ($form->isValid()) 
    {
      // Buchung ist vollständig und wird gespeichert
      $availability = $form->getData();
      
      if ($availabilityID == 0)
      {  
        
      }
      // es wurde ein neues Objekt erzeugt, das nur gespeichert werden kann, wenn die
      // One to One Beziehungen zu User und Availability zugehörige Daten beinhalten
      // Wraum das so ist weiß ich auch nicht, aber nur so funktioniert das Speichern
      $typ = FIAvailability::GetAvailabilityStateObject ($em, $availability->getTyp());
      $availability->setTyp($typ);
      $user = Users::GetUserObject ($em, $availability->getClientid(), $availability->getFlightinstructor());
      $availability->setFlightinstructor($user);
      
      $em->persist($availability);
      $em->flush();

      $sd = ViewHelper::GetSessionDataObject($request->getSession());
      $sd->SetAvailabilityID($availability->getId());
      ViewHelper::StoreSessionDataObject($request->getSession(), $sd);

      // Die Buchung lesend anzeigen
      //return $this->forward('App\Controller\FIAvailabilityController::AvailabilityGridAction'); 
      // Forward führt im Grid zu einem Fehler
      $com = ViewHelper::GetFIAvailabiltyCommand($request);
      $response = $this->redirectToRoute('_fi_availabilitygrid', array('command' => $com));
      return $response;
    }
    return $this->render('fiavailability/fiavailability.html.twig', array('form' => $form->createView()));
  }

  public function EditAction(Request $request, UserInterface $loggedin_user, EntityManagerInterface $em, $id)
  {
    $em->getConnection()->exec('SET NAMES "UTF8"');

    $sd = ViewHelper::GetSessionDataObject($request->getSession());
    
    $availabilty = FIAvailability::GetAvailabilityObject($em, $loggedin_user->getClientid(), $id);
    
    $sd->SetAvailabilityID($id);
    ViewHelper::StoreSessionDataObject($request->getSession(), $sd);

    $form = $this->BuildForm($em, $request, $availabilty);
    $response = $this->render('fiavailability/fiavailability.html.twig', array('form' => $form->createView()));  
    $response->setExpires(new \DateTime());
    return $response;
  }

  public function DeleteAction(Request $request, UserInterface $loggedin_user, EntityManagerInterface $em, $id)
  {
    $sd = ViewHelper::GetSessionDataObject($request->getSession());
    
    FIAvailability::DeleteAvailability($em, $loggedin_user->getClientid(), $id);
    $com = ViewHelper::GetFIAvailabiltyCommand($request);
    
    $sd = ViewHelper::GetSessionDataObject($request->getSession());
    $sd->SetAvailabilityID(0);
    ViewHelper::StoreSessionDataObject($request->getSession(), $sd);
    
    $response = $this->redirectToRoute('_fi_availabilitygrid', array('command' => $com));
    return $response;
  }
  
  protected static function GetRowFunction()
  {
    // gibt die Function zur Färbung der Gridzeilen zurück 
    return $func = function ($row)
    {
     
      if ($row->getField('typ.id') ==  2)
      {
        // auf Anfrage"
        $row->setColor('#F5B041');
      }
      if ($row->getField('typ.id') ==  3)
      {
        // nicht verfügbar
        $row->setColor('#EC7063');
      }
      if ($row->getField('typ.id') ==  1)
      {
        // verfügbar
        $row->setColor('#7DCEA0');
      }
      return $row;
         
      };

  }
  
  public function AvailabilityGridAction(Request $request, Grid $grid, UserInterface $loggedin_user, string $command = null)
  {
    // Diese Liste zeigt wahlweise alle Verfügabrkeiten oder nur meine Verfügbarkeiten an
    setlocale(LC_TIME, 'de_DE', 'de_DE.UTF-8');
    
    ViewHelper::SetFIAvailabiltyCommand($request, $command);
    
    // Creates a simple grid based on your entity (ORM)
    $source = new Entity('App\Entity\FresFIAvailability');
    
    // Setzen der Farben für die einzelnen Verfügbarkeitstypen
    $source->manipulateRow(self::GetRowFunction());

    // Attach the source to the grid
    $grid->setSource($source);
    
    $date = new \DateTime('today');
    $date->modify('-1 day');
   
    // Filter auf Datetime funktioniert nur, wenn in der Entity unter Grid der Typ "Date" angegeben wurde
    // Filterwerte müssen immer als String übergeben werden
    // Datetime muss im Format "Y-m-d H:i:s" übergeben werden
    // Prüfung in APY/Datagridbundle Column.php Line 500 public function setData($data)
    $filter = array('status'    => array('operator' => 'neq', 'from' => FIAvailability::const_geloescht),
                    'itemstop' => array('operator' => 'gte', 'from' => $date->format('Y-m-d H:i:s')),
                    'clientid'  => array('operator' => 'eq',  'from' => (string) $loggedin_user->getClientid()));
    
    if (ViewHelper::GetFIAvailabiltyCommand($request) == 'my')
    {
      $user = $loggedin_user;
      $userID = $user->getId();
      $filter['flightinstructor.id'] = array('operator' => 'eq', 'from' => (string) $userID);
    }
   
    $grid->setPermanentFilters($filter);
    $grid->setNoDataMessage('Keine Einträge vorhanden');
    $grid->setNoResultMessage('Keine Einträge vorhanden');
    $grid->setLimits(array(25, 50, 100, 200, 400));
    $grid->setDefaultLimit(400);

    $com = ViewHelper::GetFIAvailabiltyCommand($request);
    if ($com == 'all')
    {
      $grid->setDefaultOrder('flightinstructor.id', 'asc');
    }
    if ($com == 'my' || $com == 'view')
    {
      $grid->setDefaultOrder('itemstart', 'asc');
    }
    // Spalte für Filter "zurücksetzen" einfügen
    $actionsColumn = new ActionsColumn('work_column', '');
    // ganz hinten einfügen
    $grid->addColumn($actionsColumn, 100);

    if ($com == 'my' || $com == 'all')
    {
      $rowAction = new RowAction('Bearbeiten', '_fi_editavailability');
      $rowAction->setColumn('work_column');
      $rowAction->setRouteParameters(array('id'));
      $grid->addRowAction($rowAction);

      $rowAction = new RowAction('Löschen', '_fi_deleteavailability');
      $rowAction->setColumn('work_column');
      $rowAction->setRouteParameters(array('id'));
      $grid->addRowAction($rowAction);
    }

    $titel = 'Meine Verfügbarkeiten';
    if ($com == 'my') { $titel = 'Meine Verfügbarkeiten'; }
    if ($com == 'all') { $titel = 'Alle Verfügbarkeiten'; }

    $response = $grid->getGridResponse('fiavailability/availabilitygrid.html.twig', array('titel' => $titel)); 
    $response->setExpires(new \DateTime());
    return $response;
  } 
  
  /*
   * DateTimeColumn.php ab Zeile 90
  public function getDisplayedValue($value)
  {
      if (!empty($value)) {
          $dateTime = $this->getDatetime($value, new \DateTimeZone($this->getTimezone()));

          if (isset($this->format)) {
              if ($this->format == "l d.m.Y G:i") 
              {
                $formatter = new \IntlDateFormatter('de_DE', \IntlDateFormatter::LONG, \IntlDateFormatter::NONE);
                $formatter->setPattern('eeee dd.MM.yyyy HH:mm');
                $value = $formatter->format($dateTime);
              }
              else
              {
                $value = $dateTime->format($this->format);
              }
          } else {
              try {
                  $transformer = new DateTimeToLocalizedStringTransformer(null, $this->getTimezone(), $this->dateFormat, $this->timeFormat);
                  $value = $transformer->transform($dateTime);
              } catch (\Exception $e) {
                  $value = $dateTime->format($this->fallbackFormat);
              }
          }

          if (array_key_exists((string) $value, $this->values)) {
              $value = $this->values[$value];
          }

          return $value;
      }

      return '';
  }
  * 
  */

}
