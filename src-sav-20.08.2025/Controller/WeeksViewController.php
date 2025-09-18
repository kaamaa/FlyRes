<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use App\SessionData;
use App\ViewHelper;
use App\Entities\Planes;
use App\Entities\Users;
use App\Entities\FlightPurposes;
use App\Entities\Airfields;
use App\Entities\Bookings;
use App\Logging;
use Symfony\Component\Security\Core\User\UserInterface;
use DateTimeZone;
use Symfony\Component\HttpFoundation\Response;

class WeeksViewController extends AbstractController
{
    
    public function ViewAction(Request $request, UserInterface $loggedin_user)
    {
      /*
      // Alle Cookies abrufen 
      $cookies = $request->cookies->all(); 
      // Cookies anzeigen (beispielsweise im Response-Content oder über Logging) 
      return new Response('<pre>' . print_r($cookies, true) . '</pre>');
      */

      //Übergabe: Monat als Zahl (zweistellig) plus Jahr (vierstellig)
      $this->getDoctrine()->getConnection()->exec('SET NAMES "UTF8"');
      $sd = ViewHelper::GetSessionDataObject($request->getSession());
      
      if ($request->getMethod() == 'POST') {
             
        $sDate = $request->get('ts');
        $sd->SetYear(substr($sDate, 4, 4), SessionData::week);
        $sd->SetMonth(substr($sDate, 2, 2), SessionData::week);
        $sd->SetDay(substr($sDate, 0, 2), SessionData::week);
        
      } 
      $str = $request->attributes->get('_route');
      if (isset($str)) $sd->SetBookingDetailBackRoute($this->generateUrl($str));
      
      ViewHelper::StoreSessionDataObject($request->getSession(), $sd);
      
      $em = $this->getDoctrine()->getManager();
      
      $NumberOfdays = 15;
      $weeks = ViewHelper::GetAllDaysOfWeeksForHeader($sd->GetiDay(SessionData::week), $sd->GetiMonth(SessionData::week), $sd->GetiYear(SessionData::week),'+15 days');
      $aktYear = $sd->GetsYear(SessionData::week);
      $nextWeek = ViewHelper::GetNextWeekButtonTag($sd->GetiDay(SessionData::week), $sd->GetiMonth(SessionData::week), $sd->GetiYear(SessionData::week));
      $prevWeek = ViewHelper::GetPrevWeekButtonTag($sd->GetiDay(SessionData::week), $sd->GetiMonth(SessionData::week), $sd->GetiYear(SessionData::week));
      $planelist = Planes::GetAllPlanesForMonthview($em, $loggedin_user->getClientid());
      $tzo = new \DateTimeZone('Europe/Berlin');
      $daystart = new \DateTime("now", $tzo);
      $daystart->setDate($sd->GetiYear(SessionData::week), $sd->GetiMonth(SessionData::week), $sd->GetiDay(SessionData::week));
      $bookinglist = Bookings::GetBookingsForAllPlanes($em, $daystart, $NumberOfdays, $loggedin_user->getClientid());
      
      $response = $this->render('weeksview/weeksview.html.twig', 
                            array('weeks' => $weeks, 'numberofdays' => $NumberOfdays,
                                  'year' => $aktYear, 'next' => $nextWeek, 'prev' => $prevWeek, 
                                  'planes' => $planelist,  'bookings' => $bookinglist));
      $response->setExpires(new \DateTime());
      return $response; 
    }
}
