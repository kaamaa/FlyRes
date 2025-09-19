<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use App\ViewHelper;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use App\Entities\Planes;
use App\Entities\Users;
use App\Entities\Bookings;
use App\Entity\FresAircraft;
use APY\DataGridBundle\Grid\Source\Entity;
use APY\DataGridBundle\Grid\Action\RowAction;
use APY\DataGridBundle\Grid\Column\ActionsColumn;
use APY\DataGridBundle\Grid\Grid;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Security\Core\User\UserInterface;
use Doctrine\ORM\EntityManagerInterface;

class EditAircraftController extends AbstractController
{
  //private $grid;

  //public function __construct(APY\DataGridBundle\Grid\Grid $grid)
  //{
  //    $this->grid = $grid;
  //}
  
  public function BuildForm($em, $aircraft)
  {  
    $status_choice = array('aktv' => 0, 'inaktiv' => Planes::const_inactive);
      $form = $this->createFormBuilder($aircraft)
        ->add('id', TextType::class, array ('attr' => array('readonly' => true,)))
        ->add('aircraft', TextType::class)
        ->add('kennung', TextType::class)
        ->add('aircrafttype', ChoiceType::class, array ('choices' => Planes::GetAllAircraftTypes($em, $aircraft->getClientid())))
        ->add('adminids', ChoiceType::class, array ('choices' => Users::GetAllUsersForListbox($em, $aircraft->getClientid()), 'multiple' => true))
        ->add('advancebooking', TextType::class)    
        //->add('status', 'hidden')
        ->add('status', ChoiceType::class, array ('choices' => $status_choice))
        ->getForm();
        
    return $form;
  }

  public function ShowForm($form, $aircraft, EntityManagerInterface $em, $allowDelete = true)
  {
    // Anzahl der Buchungen für das Flugzeug ermitteln
    $data = Bookings::CountAllBookingsForAPlane($em, $aircraft->getClientid(), $aircraft->getId());
    // Basisdaten für das Formual ermitteln
    $data = array_merge(array('form' => $form->createView()), $data);
    // Soll die Schaltfläche zum Löschen angezeigt werden?
    $data = array_merge(array('allowdelete' => $allowDelete), $data);
    return $this->render('editaircraft/editaircraft.html.twig', $data);
  }
  
  public function GlobalWithIDAction(Request $request, $id)
  {
    // Einstiegspunkt für die Flugzeugliste, bei der die ID des zu bearbeiteten Nutzers übergeben wird
    if ($this->isGranted('ROLE_ADMIN'))
    { 
      $sd = ViewHelper::GetSessionDataObject($request->getSession());
      $sd->SetPlaneID($id);
      ViewHelper::StoreSessionDataObject($request->getSession(), $sd);
      return $this->forward('App\Controller\EditAircraftController::EditAction');
    }
    die('unerlaubter Zugriff!');
  }
  
  public function GlobalAction(Request $request)
  {
    if ($this->isGranted('ROLE_ADMIN')) 
    {
      // Das ist der Einstiegspunkt aus dem Formular aus dem das Flugzeug bearbeitet werden kann. Je nach verwendeter Schaltfläche
      // wird die entsprechende Aktion ausgelöst
      $sd = ViewHelper::GetSessionDataObject($request->getSession());
      if($request->get('Speichern')) return $this->forward('App\Controller\EditAircraftController::SaveAction');
      if($request->get('Löschen')) return $this->forward('App\Controller\EditAircraftController::DeleteAction');
      if($request->get('Zurück')) return $this->redirect($sd->GetBookingDetailBackRoute());

      return $this->forward('App\Controller\EditAircraftController::EditAction');
    }
    die('unerlaubter Zugriff!');
  }

  public function NewAction(Request $request, UserInterface $loggedin_user, EntityManagerInterface $em)
  {
    $em->getConnection()->exec('SET NAMES "UTF8"');
    $sd = ViewHelper::GetSessionDataObject($request->getSession());
    $sd->SetPlaneID(0);
    ViewHelper::StoreSessionDataObject($request->getSession(), $sd);
    
    $aircraft = new FresAircraft();
    $aircraft->setClientid($loggedin_user->getClientid());
    $aircraft->setStatus(0);
    
    $form = $this->BuildForm($em, $aircraft);
    return $this->ShowForm($form, $aircraft, FALSE);
  }
  
  public function DeleteAction(Request $request, UserInterface $loggedin_user, EntityManagerInterface $em)
  {
    $em->getConnection()->exec('SET NAMES "UTF8"');
    $sd = ViewHelper::GetSessionDataObject($request->getSession());
    $aircraftid = $sd->GetPlaneID();
    $sd->SetPlaneID(0);
    ViewHelper::StoreSessionDataObject($request->getSession(), $sd);

    Planes::DeletePlane($em, $loggedin_user->getClientid(), $aircraftid);
    return $this->redirect('weeksview');
  }

  public function SaveAction(Request $request, UserInterface $loggedin_user)
  {
    
    $sd = ViewHelper::GetSessionDataObject($request->getSession());
    $aircraftid = $sd->GetPlaneID();
    
    if ($aircraftid != 0) $aircraft = Planes::GetPlaneObject($em, $loggedin_user->getClientid(), $aircraftid, true);
    else {
      $aircraft = new FresAircraft(); 
      $aircraft->setClientid($loggedin_user->getClientid());
    }
    $form = $this->BuildForm($em, $aircraft);
    //$form->bind($request);
    $form->handleRequest($request);

    if ($form->isValid()) 
    {
      // Flugzeug ist vollständig und wird gespeichert
      $aircraft = $form->getData();
      // Derzeit werden keine Administratoren für Flugzeuge geführt
      $aircraft->SetAdminids(null);
      $em->persist($aircraft);
      $em->flush();

      return $this->redirect($sd->GetBookingDetailBackRoute());
    }
    //$form = $this->BuildForm($em, $aircraft);
    return $this->ShowForm($form, $aircraft);
  }

  public function EditAction(Request $request, UserInterface $loggedin_user, EntityManagerInterface $em, $allowDelete = true)
  {
    $em->getConnection()->exec('SET NAMES "UTF8"');
    $sd = ViewHelper::GetSessionDataObject($request->getSession());
    $aircraftid = $sd->GetPlaneID();
    if ($aircraftid != 0) $aircraft = Planes::GetPlaneObject($em, $loggedin_user->getClientid(), $aircraftid, true);
    
    $form = $this->BuildForm($em, $aircraft);
    return $this->ShowForm($form, $aircraft, $allowDelete);  
 }
  
  public function GridAction(RequestStack $requestStack, grid $grid, UserInterface $loggedin_user)
  {
    //$grid = $this->grid;
    $request = $requestStack->getCurrentRequest();
    $sd = ViewHelper::GetSessionDataObject($request->getSession());
    $str = $request->attributes->get('_route');
    if (isset($str)) $sd->SetBookingDetailBackRoute($this->generateUrl($str));
    ViewHelper::StoreSessionDataObject($request->getSession(), $sd);
    
    // Creates a simple grid based on your entity (ORM)
    $source = new Entity('App\Entity\FresAircraft');

    // Attach the source to the grid
    $grid->setSource($source);
    
    // Datensätze die als gelöscht markiert sind nicht mit einlesen
    $grid->setPermanentFilters(array('status' => array('operator' => 'neq', 'from' => 'geloescht'),
                                     'clientid' => array('operator' => 'eq', 'from' => $loggedin_user->getClientid())));
    
    $grid->setNoDataMessage('Keine Daten gefunden!');
    
    $grid->setLimits(array(25, 50, 100, 200, 400));
    $grid->setDefaultLimit(50);
    
    // Create an Actions Column
    $actionsColumn = new ActionsColumn('work_column', '');
    $grid->addColumn($actionsColumn, 100);
    
    $rowAction = new RowAction('Bearbeiten', '_editaircraftwithid');
    $rowAction->setColumn('work_column');
    $rowAction->setRouteParameters(array('id'));
    $grid->addRowAction($rowAction);

    // Return the response of the grid to the template
    return $grid->getGridResponse('editaircraft/aircrafttable.html.twig');
  }
}
