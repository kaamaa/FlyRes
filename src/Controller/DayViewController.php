<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use App\ViewHelper;
use App\Entities\Planes;
use App\Entities\Bookings;
use App\SessionData;
use Symfony\Component\Security\Core\User\UserInterface;
use App\Logging;
use Doctrine\ORM\EntityManagerInterface;

class DayViewController extends AbstractController
{
    
    public function ViewAction(Request $request, UserInterface $loggedin_user, EntityManagerInterface $em)
    {
      //Übergabe: FlugzeugID (ohne Stellenanzahl) Underscore und Datum im Format dd-mm-jjjj
      $em->getConnection()->exec('SET NAMES "UTF8"');
      $sd = ViewHelper::GetSessionDataObject($request->getSession());
      
      // Für den Back-Button im View ViewBookingDetails speichern, wohin zurückgekehrt werden soll
      $str = $request->attributes->get('_route');
      if (isset($str)) $sd->SetBookingDetailBackRoute($this->generateUrl($str));
      
      if ($request->getMethod() == 'POST') {
        
        if($request->request->has('ts')) 
        {
          $sPlane_Date = $request->get('ts');
          Logging::writeMsg("Ts: " . $sPlane_Date);
          $teile = explode("_", $sPlane_Date);

          // Flugzeugkennung
          $sd->SetPlaneID($teile[0]);

          // Datum
          list ($day, $month, $year) = explode('-', $teile[1]);
          $sd->SetYear($year, SessionData::day);
          $sd->SetMonth($month, SessionData::day);
          $sd->SetDay($day, SessionData::day);
          
          ViewHelper::StoreSessionDataObject($request->getSession(), $sd);
          
        }  
        
      } else {
        
        // Es muss ein Post sein, hier sollten wir nie hinkommen
      
      }
      
      $FlugzeugName = Planes::GetPlaneNameAndKennung($em, $loggedin_user->getClientid(), $sd->GetPlaneID());
      $nextDay = ViewHelper::GetNextDayButtonTag($sd->GetiDay(SessionData::day), $sd->GetiMonth(SessionData::day), $sd->GetiYear(SessionData::day));
      $prevDay = ViewHelper::GetPrevDayButtonTag($sd->GetiDay(SessionData::day), $sd->GetiMonth(SessionData::day), $sd->GetiYear(SessionData::day));
      $FlugzeugID = $sd->GetPlaneID();
      $Bookings = Bookings::GetBookingsForPlaneAndDate($em, $sd->GetiDay(SessionData::day), $sd->GetiMonth(SessionData::day), $sd->GetiYear(SessionData::day), $loggedin_user->getClientid(), $sd->GetPlaneID());
      $bookingTimes = Bookings::GetBookingTimes($sd->GetiDay(SessionData::day), $sd->GetiMonth(SessionData::day), $sd->GetiYear(SessionData::day));
       
      $response = $this->render('dayview/dayview.html.twig', 
              array('Flugzeug' => $FlugzeugName, 'FlugzeugID' => $FlugzeugID, 'Datum' => $sd->GetsFullDate(SessionData::day), 
                    'next' => $nextDay, 'prev' => $prevDay, 'bookingtimes' => $bookingTimes, 'bookings' => $Bookings));
      $response->setExpires(new \DateTime());
      return $response;
     
    }
}
