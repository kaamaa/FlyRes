<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use App\ViewHelper;
use Symfony\Component\HttpFoundation\Request;
use App\Entities\Planes;
use App\Entities\Licenses;use App\Entities\Users;
use App\Entity\FresAircrafttype;
use Doctrine\ORM\EntityRepository;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Security\Core\User\UserInterface;
use Doctrine\ORM\EntityManagerInterface;

use APY\DataGridBundle\Grid\Source\Entity;
use APY\DataGridBundle\Grid\Action\RowAction;
use APY\DataGridBundle\Grid\Column\ActionsColumn;
use APY\DataGridBundle\Grid\Grid;

class EditAircraftTypeController extends AbstractController
{
  public function BuildForm($em, $aircrafttype)
  {  
    $form = $this->createFormBuilder($aircrafttype)
        ->add('id', IntegerType::class, array ('attr' => array('readonly' => true,)))
        ->add('shortname', TextType::class)
        ->add('longname', TextType::class)
        ->add('licencetypes', EntityType::class, array(
              'class' => 'App\Entity\FresLicencetype',
              'expanded' => true,
              'multiple' => true,
              'query_builder' => function(EntityRepository $er) 
              {
                return $er->createQueryBuilder('u')->add('orderBy', 'u.categoryid ASC');
              }
              ))    
        ->add('status', HiddenType::class)    
        ->getForm();
        
    return $form;
  }
  
  public function ShowForm($form, $allowDelete = true)
  {
    // Basisdaten für das Formular ermitteln
    $data = array('form' => $form->createView());
    // Soll die Schaltfläche zum Löschen angezeigt werden?
    $data = array_merge(array('allowdelete' => $allowDelete), $data);
    return $this->render('editaircrafttype/editaircrafttype.html.twig', $data);
  }

  public function GlobalWithIDAction(Request $request, EntityManagerInterface $em, $id)
  {
    // Einstiegspunkt für die Flugzeugtypen, bei der die ID des zu bearbeiteten Flugzegstypen übergeben wird
    if ($this->isGranted('ROLE_ADMIN'))
    { 
      $sd = ViewHelper::GetSessionDataObject($request->getSession());
      $sd->SetAircraftTypeID($id);
      ViewHelper::StoreSessionDataObject($request->getSession(), $sd);
      return $this->forward('App\Controller\EditAircraftTypeController::EditAction');
    }
    die('unerlaubter Zugriff!');
  }
  
  public function GlobalAction(Request $request)
  {
    // Das ist der Einstiegspunkt aus dem Formular aus dem das Flugzeugtyp bearbeitet werden kann. Je nach verwendeter Schaltfläche
    // wird die entsprechende Aktion ausgelöst
    $sd = ViewHelper::GetSessionDataObject($request->getSession());
    if($request->get('Speichern')) return $this->forward('App\Controller\EditAircraftTypeController::SaveAction');
    if($request->get('Löschen')) return $this->forward('App\Controller\EditAircraftTypeController::DeleteAction');
    if($request->get('Zurück')) return $this->redirect($sd->GetBookingDetailBackRoute());
   
    return $this->forward('App\Controller\EditAircraftTypeController::EditAction');
  }

  public function NewAction(Request $request, UserInterface $loggedin_user, EntityManagerInterface $em)
  {
    
    $sd = ViewHelper::GetSessionDataObject($request->getSession());
    $sd->SetAircraftTypeID(0);
    ViewHelper::StoreSessionDataObject($request->getSession(), $sd);
    
    $aircrafttype = new FresAircrafttype();
    $aircrafttype->setClientid($loggedin_user->getClientid());
    $aircrafttype->setStatus(0);
    
    $form = $this->BuildForm($em, $aircrafttype);
    return $this->ShowForm($form, FALSE);
  }
  
  public function DeleteAction(Request $request, UserInterface $loggedin_user, EntityManagerInterface $em)
  {
    $sd = ViewHelper::GetSessionDataObject($request->getSession());
    $aircrafttypeid = $sd->GetAircraftTypeID();
    $sd->SetAircraftTypeID(0);
    ViewHelper::StoreSessionDataObject($request->getSession(), $sd);

    Licenses::DeleteAircraftType($em, $loggedin_user->getClientid(), $aircrafttypeid);
    return $this->redirect($sd->GetBookingDetailBackRoute());
  }

  public function SaveAction(Request $request, UserInterface $loggedin_user, EntityManagerInterface $em)
  {
    $sd = ViewHelper::GetSessionDataObject($request->getSession());
    $aircrafttypeid = $sd->GetAircraftTypeID();
    
    if ($aircrafttypeid != 0) $aircrafttype = Planes::GetAircraftTypeObject($em, $aircrafttypeid, $loggedin_user->getClientid());
    else {
      $aircrafttype = new FresAircrafttype(); 
      $aircrafttype->setClientid($loggedin_user->getClientid());
    }
    $form = $this->BuildForm($em, $aircrafttype);
    $form->handleRequest($request);

    if ($form->isValid()) 
    {
      // Flugzeugtyp ist vollständig und wird gespeichert
      $aircrafttype = $form->getData();
      
      // Jetzt alle zugehörigen Lizentypen ändern
      foreach ($aircrafttype->getLicencetypes() as $licencetype) 
      {
        $em->persist($licencetype);
      }

      $em->persist($aircrafttype);
      $em->flush();

      return $this->redirect($sd->GetBookingDetailBackRoute());
    }
    //$form = $this->BuildForm($em, $aircrafttype);
    return $this->ShowForm($form, $aircrafttype);
  }

  public function EditAction(Request $request, UserInterface $loggedin_user, EntityManagerInterface $em, $allowDelete = true)
  {
    $sd = ViewHelper::GetSessionDataObject($request->getSession());
    $aircrafttypeid = $sd->GetAircraftTypeID();
    if ($aircrafttypeid != 0) $aircrafttype = Planes::GetAircraftTypeObject($em, $aircrafttypeid, $loggedin_user->getClientid());
    
    $form = $this->BuildForm($em, $aircrafttype);
    return $this->ShowForm($form, $allowDelete);  
 }
  
  public function GridAction(Request $request, Grid $grid, UserInterface $loggedin_user)
  {
    $sd = ViewHelper::GetSessionDataObject($request->getSession());
    $str = $request->attributes->get('_route');
    if (isset($str)) $sd->SetBookingDetailBackRoute($this->generateUrl($str));
    ViewHelper::StoreSessionDataObject($request->getSession(), $sd);
      
    // Creates a simple grid based on your entity (ORM)
    $source = new Entity('App\Entity\FresAircrafttype');

    // Attach the source to the grid
    $grid->setSource($source);
    
    // Datensätze die als gelöscht markiert sind nicht mit einlesen
    //$grid->setPermanentFilters(array('status' => array('operator' => 'neq', 'from' => 'geloescht')));
    
    $grid->setPermanentFilters(array('status' => array('operator' => 'neq', 'from' => 'geloescht'),
                                     'clientid' => array('operator' => 'eq', 'from' => $loggedin_user->getClientid())));
    
    
    $grid->setNoDataMessage('Keine Daten gefunden!');
    
    $grid->setLimits(array(25, 50, 100, 200, 400));
    $grid->setDefaultLimit(50);
    
    // Create an Actions Column
    $actionsColumn = new ActionsColumn('work_column', '');
    $grid->addColumn($actionsColumn, 100);
    
    $rowAction = new RowAction('Bearbeiten', '_editaircrafttypewithid');
    $rowAction->setColumn('work_column');
    $rowAction->setRouteParameters(array('id'));
    $grid->addRowAction($rowAction);

    // Return the response of the grid to the template
    return $grid->getGridResponse('editaircrafttype/aircrafttypetable.html.twig');
  }
}
