<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use App\ViewHelper;
use Symfony\Component\HttpFoundation\Request;
use App\Entity\FresBooking;
use App\Entities\Planes;
use App\Entities\Bookings;
use App\Entities\Users;
use App\Entities\FlightPurposes;
use App\Entities\Airfields;
use App\Entities\Licenses;
use App\Entities\FIAvailability;
use App\SessionData;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Cache;

use Symfony\Component\Form\FormError;

class EditBookingController extends AbstractController
{
  const BookingDateFormat1 = 'dd.MM.yyyy HH:mm'; // Darstellung für Formulare
  const BookingDateFormat2 = 'd.m.Y H:i';  // Darstellung für DateTime->createFromFormat
  
  
  public function BuildForm($em, $booking)
  {  
    $form = $this->createFormBuilder($booking)
        ->add('aircraftid', ChoiceType::class, array ('choices' => Planes::GetAllPlanesForListbox($em, $booking->getClientid())))
        ->add('flightinstructor', ChoiceType::class, array ('choices' => Users::GetAllFlightinstructorsForListbox($em, $booking->getClientid()), 
              'required' => false, 'placeholder' => '(kein Fluglehrer)', 'empty_data'  => null))
        ->add('airfieldid', ChoiceType::class, array ('choices' => Airfields::GetAllAirportsForListbox($em)))
        ->add('flightpurposeid', ChoiceType::class, array ('choices' => FlightPurposes::GetFlightPuposeArray($em), 'expanded'  => true,))   
        ->add('createdbyuserid', ChoiceType::class, array ('choices' => Users::GetAllUsersForListbox($em, $booking->getClientid())))  
        ->add('emailinfoi', ChoiceType::class, array ('choices' => Users::GetAllUsersForMailListbox($em, $booking->getClientid(), Users::const_Buchungsmail), 'multiple'  => true,))      
        ->add('emailinfoe', TextType::class) 
        ->add('description', TextareaType::class) 
        ->add('itemstart', DateTimeType::class, array('html5' => false, 'format' => EditBookingController::BookingDateFormat1, 'widget' => 'single_text'))    
        ->add('itemstop', DateTimeType::class, array('html5' => false, 'format' => EditBookingController::BookingDateFormat1, 'widget' => 'single_text'))      
        ->getForm();
    return $form;
  }
 
  public function NewAction(Request $request, UserInterface $loggedin_user)
  {
    $this->getDoctrine()->getConnection()->exec('SET NAMES "UTF8"');
    $em = $this->getDoctrine()->getManager();
    
    $sd = ViewHelper::GetSessionDataObject($request->getSession());

    // Neue Buchungen erhalten zunächt die ID 0. Beim Speichern wird von der Datenbank die finale ID vergeben
    ViewHelper::SetBookingID($request, 0);
    // new Booking
    $booking = new FresBooking();
    $booking->setClientid($loggedin_user->getClientid());
    
    // Das Datum für die Buchung wird aus den Sessioninformationen zusammengebaut. 
    // Wenn z.B. bereits auf einen Tag geclicked wurde, dann ist dieser für eine neue Buchung vorbelegt
    $date = $sd->GetDateTime(SessionData::day);
    $date->setTime(10,0);
    $booking->setItemstart($date);
    $booking->setItemstop($date);
    // Worms vorbelegen (ID 104)
    $booking->setAirfieldid('104');
    // Flugzeug vorbelegen, wenn eines in der Session gespeichert ist
    $planeID = $sd->GetPlaneID();
    if (isset($planeID)) $booking->setAircraftid($planeID);
    $user = $loggedin_user;
      if ($user) $booking->setCreatedbyuserid($user->getId());
    $form = $this->BuildForm($em, $booking);
    $response = $this->render('editbooking/editbooking.html.twig', array('form' => $form->createView())); 
    
    $response->setExpires(new \DateTime());
    return $response;
  }

  public function SaveAction(MailerInterface $mailer, Request $request, UserInterface $loggedin_user)
  {
    $this->getDoctrine()->getConnection()->exec('SET NAMES "UTF8"');
    $em = $this->getDoctrine()->getManager();

    $bookingID = ViewHelper::GetBookingID($request); 

    if ($bookingID <> 0)
    {  
      // Daten für Änderungen an einer bestehenden Buchung ändern
      // Zunächste die ursprüngliche Reservierung aus der Datenbank laden
      $booking = Bookings::GetBookingObject($em, $loggedin_user->getClientid(), $bookingID);
      // booking_old wird für den Vergleich zum Mailversand verwendet
      $booking_old = clone $booking;

      $users = Users::GetAllUsersForListboxByMailadress($em, $booking->getClientid(), $booking->getEmailinfoi());
      $booking->setEmailinfoi($users);

      $user = $loggedin_user;
      if ($user) $booking->setChangedbyuserid($user->getId());
      //$booking->setChangeddate(new \DateTime('now'));
    }
    else
    {
      // Daten für neue Buchung eintragen
      $booking = new FresBooking();
      // booking_old wird für den Vergleich zum Mailversand verwendet
      $booking_old = NULL;

      $booking->setCreatedDate(new \DateTime('now'));
      $user = $loggedin_user;
      if ($user) $booking->setCreatedbyuserid($user->getId());
      $booking->setStatus(0);
    }
    // Changedate wird immer gesetzt (On Update in der Datenbank geht nicht immer richtig
    $booking->setChangeddate(new \DateTime('now'));
    // ClientID setzen ist unabhängig davon, ob es sich um eine neue oder bestehende Buchung handelt
    $booking->setClientid($loggedin_user->getClientid());

    $form = $this->BuildForm($em, $booking);
    $form->handleRequest($request);

    // Eingegebene Daten validieren
    $errormsg = Bookings::IsPlaneAvailable($em, $booking);
    if (!empty($errormsg)) $form->addError(new FormError($errormsg));
    $errormsg1 = Bookings::IsFlightinstructorNotBooked($em, $booking);
    if (!empty($errormsg1)) $form->addError(new FormError($errormsg1));
    
    $errormsg3 = FIAvailability::IsFlightinstructorAvailable($em, $booking, $loggedin_user);
    if ($errormsg3 != "") $form->addError(new FormError($errormsg3));
    
    if (!Users::IsMailListValid($booking->getEmailinfoe())) 
      $form->addError(new FormError('Mindestens eine externe Mailadresse ist keine korrekte Mailadresse'));

    // Das Datum kann nicht aus dem $form entnommen werden, weil beim Kopieren in das Formular bereits Korrekturen durchgeführt wurden
    $ary = $request->request->get('form');

    $dateStart = \DateTime::createFromFormat(EditBookingController::BookingDateFormat2, $ary['itemstart']);
    $dateEnd = \DateTime::createFromFormat(EditBookingController::BookingDateFormat2, $ary['itemstop']);
    $isSchulung = FlightPurposes::IsSchulung($booking->getflightpurposeid());

    if ($dateStart == false)
      $form->addError(new FormError('Das Startdatum ist kein gültiges Datum'));
    if ($dateEnd == false)
      $form->addError(new FormError('Das Enddatum ist kein gültiges Datum'));

    if ($booking->getItemstart() >= $booking->getItemstop())
      $form->addError(new FormError('Das Ende der Reservierung muss später als der Start sein'));
    
    if ($isSchulung && $booking->getFlightinstructor() == NULL)
      $form->addError(new FormError('Für Schulflüge muss ein Fluglehrer ausgewählt werden'));
    if ($booking->getAircraftid() == 0)
      $form->addError(new FormError('Es muss ein Flugzeug ausgewählt werden'));
    $licenceError = Licenses::CheckIfLicencesAreValid($em, $booking->getClientid(), $booking->getCreatedbyuserid(), 
                                                      Planes::GetAircraftTypeForAircraft($em, $booking->getAircraftid(), $booking->getClientid()), 
                                                      $booking->getItemstart()->format('Y-m-d'), $isSchulung);
    if ($licenceError != NULL) $form->addError(new FormError($licenceError));
    if (!$this->isGranted('ROLE_ADMIN'))
    {       
      $advancebookingerror = Planes::CheckIfBookingIsInAdvanceRange($em, $booking->getClientid(), $booking->getAircraftid(), $booking->getItemstart());
      if ($advancebookingerror != '')  $form->addError(new FormError($advancebookingerror));
    }
    if ($form->isValid()) 
    {
      // Buchung ist vollständig und wird gespeichert
      $booking = $form->getData();
      $booking->setEmailinfoi(Users::GetAllMailsadressesByUserlist ($em, $booking->getEmailinfoi(), $booking->getClientid()));

      $em->persist($booking);
      $em->flush();

      $user = $loggedin_user;

      $parameter['program_version'] = $this->getParameter('program_version');
      $parameter['mail_from'] = $this->getParameter('mail_from');
      $twig = $this->container->get('twig');
      Bookings::SendBookingsInfoMail($em, $user, $twig, $booking, $booking_old, $mailer, $parameter);

      ViewHelper::SetBookingID($request, $booking->getId());
      // Die Buchung lesend anzeigen
      return $this->forward('App\Controller\ViewBookingDetailsController::ViewAction');
    }
    return $this->render('editbooking/editbooking.html.twig', array('form' => $form->createView()));
  }

  public function EditAction(Request $request, UserInterface $loggedin_user)
  {
    $this->getDoctrine()->getConnection()->exec('SET NAMES "UTF8"');
    $em = $this->getDoctrine()->getManager();

    $bookingID = ViewHelper::GetBookingID($request); 

    $booking = Bookings::GetBookingObject($em, $loggedin_user->getClientid(), $bookingID);
    
    $users = Users::GetAllUsersForListboxByMailadress($em, $booking->getClientid(), $booking->getEmailinfoi());
    $booking->setEmailinfoi($users);
    $form = $this->BuildForm($em, $booking);
    return $this->render('editbooking/editbooking.html.twig', array('form' => $form->createView()));        
  }
  
  public function BookingAjaxDisplayAvailableFIsAction(Request $request, UserInterface $loggedin_user)
  //Controller wird per ajax aufgerufen, wenn ein Datum zu einer Buchung verändert wird
  {        
    date_default_timezone_set('Europe/Berlin');
    $em = $this->getDoctrine()->getManager();
    $startdate = new \DateTime();
    $startdate->setTimestamp(intval($request->request->get('startdate')));
    $enddate = new \DateTime();
    $enddate->setTimestamp(intval($request->request->get('enddate')));
    
    $available_planes = Bookings::GetAllAvailablePlanesForADate($em, $loggedin_user->getClientid(), $startdate);
    $available_FIs = Bookings::GetAllAvailableFIsForADate($em, $loggedin_user->getClientid(), $startdate);
    
    if (false)
    {
        $response = new JsonResponse([
          'success' => false,
          'code'    => 400,
          'message' => 'Es ist ein Fehler aufgetreten',
        ]);
        $response->setStatusCode(400, 'false');
        return $response;
    }
    
    $data = array(
      'available_planes' => $available_planes,
      'available_FIs' => $available_FIs 
    );

    $response = new JsonResponse([
      'success' => true,
      'code'    => 200,
      'data' => $data,
    ]);
    $response->setStatusCode(200, 'success');

    return $response;

  }

}
