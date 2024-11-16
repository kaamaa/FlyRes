<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use App\Entities\Bookings;
use App\ViewHelper;
use App\Entities\Users;
use App\SessionData;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class ViewBookingDetailsController extends AbstractController
{
    
  public function ViewAction(Request $request, UserInterface $loggedin_user)
  {
    $this->getDoctrine()->getConnection()->exec('SET NAMES "UTF8"');
    $bookingID = ViewHelper::GetBookingID($request); 
    
    // SessionData füllen, damit alle Daten aus der letzten Buchung verfügbar sind
    $booking = Bookings::GetBookingObject($this->getDoctrine()->getManager(), $loggedin_user->getClientid(), $bookingID);
    ViewHelper::SetBookingData($request, $booking);
    
    $user = $loggedin_user;

    $data = Bookings::GetBookingDetails($this->getDoctrine()->getManager(), $loggedin_user->getClientid(), $bookingID, $user);
    
    $sd = ViewHelper::GetSessionDataObject($request->getSession());
    $data = array_merge($data, array('BackRoute' => $sd->GetBookingDetailBackRoute()));
    
    return $this->render('viewbookingdetails/viewbookingdetails.html.twig', $data);
  }
  
  public function DeleteAction(MailerInterface $mailer, Request $request, UserInterface $loggedin_user)
  {
    $this->getDoctrine()->getConnection()->exec('SET NAMES "UTF8"');  
    $bookingID = ViewHelper::GetBookingID($request); 
    
    // Die Buchung aus der Datenbank laden und eine Info über die Löschung per Mail verschicken
    $booking_old = Bookings::GetBookingObject($this->getDoctrine()->getManager(), $loggedin_user->getClientid(), $bookingID);
    
    $em = $this->getDoctrine()->getManager();
    $user = $loggedin_user;
    
    //Bookings::SendBookingsInfoMail($em, $user, $this->container, NULL, $booking_old);
    
    $parameter['program_version'] = $this->getParameter('program_version');
    $parameter['mail_from'] = $this->getParameter('mail_from');
    $twig = $this->container->get('twig');
    Bookings::SendBookingsInfoMail($em, $user, $twig, NULL, $booking_old, $mailer, $parameter);

    // Buchung löschen
    Bookings::DeleteBooking($this->getDoctrine()->getManager(), $loggedin_user->getClientid(), $bookingID);
    return $this->forward('App\Controller\DayViewController::ViewAction');
  }
}
