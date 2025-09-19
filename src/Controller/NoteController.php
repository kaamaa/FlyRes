<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use App\ViewHelper;
use App\Entities\Notes;
use App\Entities\Users;
use Symfony\Component\HttpFoundation\Request;
use App\Entity\FresNotes;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Security\Core\User\UserInterface;
use APY\DataGridBundle\Grid\Source\Entity;
use APY\DataGridBundle\Grid\Action\RowAction;
use APY\DataGridBundle\Grid\Column\ActionsColumn;
use APY\DataGridBundle\Grid\Grid;
use Symfony\Component\Form\FormError;
use Doctrine\ORM\EntityManagerInterface;

class NoteController extends AbstractController
{
  const NoteDateFormat1 = 'dd.MM.yyyy'; // Darstellung für Formulare
  const NoteDateFormat2 = 'd.m.Y';  // Darstellung für DateTime->createFromFormat
  
  public function BuildForm($em, $note)
  {  
    $form = $this->createFormBuilder($note)
        ->add('header', TextType::class) 
        ->add('description', TextareaType::class) 
        ->add('validuntil', DateTimeType::class, array('html5' => false, 'format' => NoteController::NoteDateFormat1, 'widget' => 'single_text'))    
        ->getForm();
    return $form;
  }

  public function NewAction(Request $request, UserInterface $loggedin_user, EntityManagerInterface $em)
  {
    // new Note
    $em->getConnection()->exec('SET NAMES "UTF8"');
    $note = new \App\Entity\FresNote();
    $note->setClientid($loggedin_user->getClientid());
    
    $sd = ViewHelper::GetSessionDataObject($request->getSession());
    $sd->SetNoteID(0);
    ViewHelper::StoreSessionDataObject($request->getSession(), $sd);
    
    
    $form = $this->BuildForm($em, $note);
    return $this->render('note/editnote.html.twig', array('form' => $form->createView()));  
  }

  public function SaveAction(Request $request, UserInterface $loggedin_user, EntityManagerInterface $em)
  {
    $em->getConnection()->exec('SET NAMES "UTF8"');
    $sd = ViewHelper::GetSessionDataObject($request->getSession());
    $noteid = $sd->GetNoteID();

    if ($noteid != 0)
    {
      $note = Notes::GetNoteObject ($em, $loggedin_user->getClientid(), $noteid);
      // Datensatz einer bestehenden Buchung
      if ($loggedin_user) $note->setChangedbyuserid($loggedin_user->getId());
    }
    else
    {
      $note = new \App\Entity\FresNote(); 
      // Daten für neue Buchung eintragen
      $note->setCreatedDate(new \DateTime('now'));
      if ($loggedin_user) $note->setCreatedbyuserid($loggedin_user->getId());
      $note->setStatus(0);
    }
    
    $form = $this->BuildForm($em, $note);
    $form->handleRequest($request);
    
    // Changedate wird immer gesetzt (On Update in der Datenbank geht nicht immer richtig
    $note->setChangeddate(new \DateTime('now'));
    // ClientID setzen ist unabhängig davon, ob es sich um eine neue oder bestehende Buchung handelt
    $note->setClientid($loggedin_user->getClientid());

    // Das Datum kann nicht aus dem $form entnommen werden, weil beim Kopieren in das Formular bereits Korrekturen durchgeführt wurden
    $ary = $request->request->get('form');

    $validUntil = \DateTime::createFromFormat(NoteController::NoteDateFormat2, $ary['validuntil']);
    
    if ($validUntil == false)
      $form->addError(new FormError('Das "Gültig bis" Datum ist kein gültiges Datum'));
    
    $maxdate = new \DateTime();
    date_add($maxdate, date_interval_create_from_date_string('1 month'));
  
    if (!$this->isGranted('ROLE_SYSTEM_ADMIN') && $validUntil > $maxdate) 
      $form->addError(new FormError('Das "Gültig bis" Datum darf maximal einen Monat in der Zukunft liegen'));
      
    if ($form->isValid()) 
    {
      // Buchung ist vollständig und wird gespeichert
      $note = $form->getData();
      
      if ($noteid == 0)
      {  
        // es wurde ein neues Objekt erzeugt, das nur gespeichert werden kann, wenn die
        // One to One Beziehungen zu User und Note zugehörige Daten beinhalten
        // Wraum das so ist weiß ich auch nicht, aber nur so funktioniert das Speichern
        $user = Users::GetUserObject ($em, $note->getClientid(), $note->getCreatedbyuserid());
        $note->setUser($user);
      }
      
      $em->persist($note);
      $em->flush();

      return $this->redirect('weeksview');
    }
    return $this->render('note/editnote.html.twig', array('form' => $form->createView()));
  }
  
  public function ViewNotesAction(Request $request, UserInterface $loggedin_user, EntityManagerInterface $em)
  {
    // Zeigt die Pinnwand auf der Startseite an
    $em->getConnection()->exec('SET NAMES "UTF8"');
    $notes = Notes::GetAllActiveNotesAsObject($em);
    foreach ($notes as $note) 
    {
      $note->setDescription(nl2br($note->getDescription(), true));
    }
    
    return $this->render('note/shownote.html.twig', array('notes' => $notes));
  }

  public function EditAction(Request $request, $id, UserInterface $loggedin_user, EntityManagerInterface $em)
  {
    $em->getConnection()->exec('SET NAMES "UTF8"');
    $note = Notes::GetNoteObject($em, $loggedin_user->getClientid(), $id);
    
    $sd = ViewHelper::GetSessionDataObject($request->getSession());
    $sd->SetNoteID($id);
    ViewHelper::StoreSessionDataObject($request->getSession(), $sd);
    
    $form = $this->BuildForm($em, $note);
    return $this->render('note/editnote.html.twig', array('form' => $form->createView()));        
  }
  
  public function DeleteAction(Request $request, $id, UserInterface $loggedin_user, EntityManagerInterface $em)
  {
    $em->getConnection()->exec('SET NAMES "UTF8"'); 
    $sd = ViewHelper::GetSessionDataObject($request->getSession());
    $sd->SetNoteID(0);
    ViewHelper::StoreSessionDataObject($request->getSession(), $sd);
    
    Notes::DeleteNote($em, $loggedin_user->getClientid(), $id);
    
    if ($this->isGranted('ROLE_SYSTEM_ADMIN')) {
      $url = $this->generateUrl('_notesgrid', ['command' => 'all']);
    }
    else {
      $url = $this->generateUrl('_notesgrid', ['command' => 'my']);
    }
    
    return $this->redirect($url);
  }

  public function NotesGridAction(Request $request, Grid $grid, UserInterface $loggedin_user, EntityManagerInterface $em, $command = NULL)
  {
    // Diese Liste zeigt wahlweise alle Notizen oder nur meine Notizen an

    $em->getConnection()->exec('SET NAMES "UTF8"');

    // Creates a simple grid based on your entity (ORM)
    $source = new Entity('App\Entity\FresNote');

    // Attach the source to the grid
    $grid->setSource($source);

    // Datensätze die als gelöscht markiert sind nicht anzeigen 
    if ($command == 'all' && $this->isGranted('ROLE_SYSTEM_ADMIN'))
    {
      $filter = array('status'   => array('operator' => 'neq', 'from' => Notes::const_geloescht),
                      'clientid' => array('operator' => 'eq',  'from' => $loggedin_user->getClientid()));
    }
    if ($command == 'my')
    {
      // nur meine Pinnwandeinträge anzeigen
      $filter = array('status'   => array('operator' => 'neq', 'from' => Notes::const_geloescht),
                      'clientid' => array('operator' => 'eq',  'from' => $loggedin_user->getClientid()),
                      'createdbyuserid' => array('operator' => 'eq',  'from' => $loggedin_user->getId()));
    }
    $grid->setDefaultFilters($filter);
    $grid->setNoDataMessage('Keine Pinnwandeinträge vorhanden');
    $grid->setNoResultMessage('Keine Pinnwandeinträge vorhanden');

    $grid->setDefaultOrder('validuntil', 'desc');

    $grid->setLimits(array(25, 50, 100, 200, 400));
    $grid->setDefaultLimit(400);

    $actionsColumn = new ActionsColumn('work_column', '');
    // ganz hinten einfügen
    $grid->addColumn($actionsColumn, 100);

    $rowAction = new RowAction('Bearbeiten', '_editnote');
    $rowAction->setColumn('work_column');
    $rowAction->setRouteParameters(array('id'));
    $grid->addRowAction($rowAction);

    $rowAction = new RowAction('Löschen', '_deletenote');
    $rowAction->setColumn('work_column');
    $rowAction->setRouteParameters(array('id'));
    $grid->addRowAction($rowAction);

    $titel = '';
    if ($command == 'my') $titel = 'Meine Pinnwandeinträge';
    if ($command == 'all') $titel = 'Alle Pinnwandeinträge';

    return $grid->getGridResponse('note/notestable.html.twig', array('titel' => $titel)); 
  }
}
