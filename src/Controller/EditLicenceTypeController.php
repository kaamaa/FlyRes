<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use App\ViewHelper;
use Symfony\Component\HttpFoundation\Request;
use App\Entities\Licensetype;
use App\Entity\FresLicencetype;

use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;

use APY\DataGridBundle\Grid\Source\Entity;
use APY\DataGridBundle\Grid\Action\RowAction;
use APY\DataGridBundle\Grid\Column\ActionsColumn;
use APY\DataGridBundle\Grid\Grid;
use Doctrine\ORM\EntityManagerInterface;


class EditLicenceTypeController extends AbstractController
{
  public function BuildForm($em, $licencetype)
  {  
    $form = $this->createFormBuilder($licencetype)
        ->add('id', IntegerType::class, array ('attr' => array('readonly' => true,)))
        ->add('categoryid', IntegerType::class)    
        ->add('categoryname', TextType::class)
        ->add('longname', TextType::class)
        ->add('description', TextareaType::class, array('attr' => array('cols' => '60', 'rows' => '3')))
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
    return $this->render('editlicencetype/editlicencetype.html.twig', $data);
  }
  
  public function GlobalWithIDAction(Request $request, $id)
  {
    if (!$this->isGranted('ROLE_GLOBAL_ADMIN')) die('unerlaubter Zugriff!'); 
    $sd = ViewHelper::GetSessionDataObject($request->getSession());
    $sd->SetLicenceTypeID($id);
    ViewHelper::StoreSessionDataObject($request->getSession(), $sd);
    return $this->forward('App\Controller\EditLicenceTypeController::EditAction');
  }
  
  public function GlobalAction(Request $request)
  {
    // Das ist der Einstiegspunkt aus dem Formular aus dem das Lizenz bearbeitet werden kann. Je nach verwendeter Schaltfläche
    // wird die entsprechende Aktion ausgelöst
    $sd = ViewHelper::GetSessionDataObject($request->getSession());
    if($request->get('Speichern')) return $this->forward('App\Controller\EditLicenceTypeController::SaveAction');
    if($request->get('Löschen')) return $this->forward('App\Controller\EditLicenceTypeController::DeleteAction');
    if($request->get('Zurück')) return $this->redirect($sd->GetBookingDetailBackRoute());
   
    return $this->forward('App\Controller\EditLicenceTypeController::EditAction');
  }

  public function NewAction(Request $request, EntityManagerInterface $em)
  {
    if (!$this->isGranted('ROLE_GLOBAL_ADMIN')) die('unerlaubter Zugriff!');
    $em->getConnection()->exec('SET NAMES "UTF8"');

    $sd = ViewHelper::GetSessionDataObject($request->getSession());
    $sd->SetLicenceTypeID(0);
    ViewHelper::StoreSessionDataObject($request->getSession(), $sd);

    $licencetype = new FresLicencetype();
    $licencetype->setStatus(0);
    
    $form = $this->BuildForm($em, $licencetype);
    return $this->ShowForm($form, FALSE);
  }

  public function DeleteAction(Request $request, EntityManagerInterface $em)
  {
    if (!$this->isGranted('ROLE_GLOBAL_ADMIN')) die('unerlaubter Zugriff!'); 
    
    $sd = ViewHelper::GetSessionDataObject($request->getSession());
    $licensetypeid = $sd->GetLicenceTypeID();
    Licensetype::SetLicensetypeToInactive($em, $licensetypeid);
    return $this->redirect($sd->GetBookingDetailBackRoute());    
  }

  public function SaveAction(Request $request)
  {
    if (!$this->isGranted('ROLE_GLOBAL_ADMIN')) die('unerlaubter Zugriff!');

    $sd = ViewHelper::GetSessionDataObject($request->getSession());
    $licensetypeid = $sd->GetLicenceTypeID();

    if ($licensetypeid != 0) $licensetype = Licensetype::GetLicenseTypeObject($em, $licensetypeid);
      else $licensetype = new FresLicencetype(); 

    $form = $this->BuildForm($em, $licensetype);
    $form->handleRequest($request);

    if ($form->isValid()) 
    {
      // Lizenttyp ist vollständig und wird gespeichert
      $licencetype = $form->getData();
      $em->persist($licencetype);
      $em->flush();

      return $this->redirect($sd->GetBookingDetailBackRoute());
    }
    return $this->ShowForm($form, $licencetype);
  }

  public function EditAction(Request $request, EntityManagerInterface $em, $allowDelete = true)
  {
    if (!$this->isGranted('ROLE_GLOBAL_ADMIN')) die('unerlaubter Zugriff!');
    $em->getConnection()->exec('SET NAMES "UTF8"');

    $sd = ViewHelper::GetSessionDataObject($request->getSession());
    $licencetypeid = $sd->GetLicenceTypeID();
    if ($licencetypeid != 0) $licencetype = Licensetype::GetLicenseTypeObject($em, $licencetypeid);
    $form = $this->BuildForm($em, $licencetype);
    return $this->ShowForm($form, $allowDelete); 
 }
  
  public function GridAction(Request $request, Grid $grid)
  {
    if (!$this->isGranted('ROLE_GLOBAL_ADMIN')) die('unerlaubter Zugriff!');
    
    $sd = ViewHelper::GetSessionDataObject($request->getSession());
    $str = $request->attributes->get('_route');
    if (isset($str)) $sd->SetBookingDetailBackRoute($this->generateUrl($str));
    ViewHelper::StoreSessionDataObject($request->getSession(), $sd);

    // Creates a simple grid based on your entity (ORM)
    $source = new Entity('App\Entity\FresLicencetype');

    // Attach the source to the grid
    $grid->setSource($source);
    $grid->setDefaultOrder("categoryid", "asc");

    // Datensätze die als gelöscht markiert sind nicht mit einlesen
    $grid->setPermanentFilters(array('status' => array('operator' => 'neq', 'from' => Licensetype::const_geloescht)));

    $grid->setNoDataMessage('Keine Daten gefunden!');
    $grid->setNoResultMessage('Keine Daten gefunden!');

    $grid->setLimits(array(25, 50, 100, 200, 400));
    $grid->setDefaultLimit(50);

    // Create an Actions Column
    $actionsColumn = new ActionsColumn('work_column', '');
    $grid->addColumn($actionsColumn, 100);

    $rowAction = new RowAction('Bearbeiten', '_editlicencetypewithid');
    $rowAction->setColumn('work_column');
    $rowAction->setRouteParameters(array('id'));
    $grid->addRowAction($rowAction);

    // Return the response of the grid to the template
    return $grid->getGridResponse('editlicencetype/licencetypetable.html.twig');
  }
}
