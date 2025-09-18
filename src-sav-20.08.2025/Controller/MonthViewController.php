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

class MonthViewController extends AbstractController
{
    
    public function ViewAction(Request $request, UserInterface $loggedin_user)
    {
      //Übergabe: Monat als Zahl (zweistellig) plus Jahr (vierstellig)
      $this->getDoctrine()->getConnection()->exec('SET NAMES "UTF8"');
      $sd = ViewHelper::GetSessionDataObject($request->getSession());
      
      if ($request->getMethod() == 'POST') {
             
        $sDate = $request->get('ts');
        $sd->SetYear(substr($sDate, 2, 4), SessionData::month);
        $sd->SetMonth(substr($sDate, 0, 2), SessionData::month);
      } 
      $str = $request->attributes->get('_route');
      if (isset($str)) $sd->SetBookingDetailBackRoute($this->generateUrl($str));
      
      ViewHelper::StoreSessionDataObject($request->getSession(), $sd);
      
      $em = $this->getDoctrine()->getManager();
      
      $monate = ViewHelper::GetAllMonthForHeader($sd->GetiYear(SessionData::month));
      $aktmonat = ViewHelper::GetActualMonthForHeader($sd->GetsMonth(SessionData::month), $sd->GetsYear(SessionData::month));
      $maxdayspermonth = ViewHelper::GetMaxDaysPerMonth(1, $sd->GetiMonth(SessionData::month), $sd->GetiYear(SessionData::month));
      $aktYear = $sd->GetsYear(SessionData::month);
      $nextMonth = ViewHelper::GetNextMonthButtonTag($sd->GetiDay(SessionData::month), $sd->GetiMonth(SessionData::month), $sd->GetiYear(SessionData::month));
      $prevMonth = ViewHelper::GetPrevMonthButtonTag($sd->GetiDay(SessionData::month), $sd->GetiMonth(SessionData::month), $sd->GetiYear(SessionData::month));
      $aryMonth = ViewHelper::GetAllDaysOfMonthForHeader($sd->GetiMonth(SessionData::month), $sd->GetiYear(SessionData::month));
      $planelist = Planes::GetAllPlanesForMonthview($em, $loggedin_user->getClientid());
      $tzo = new \DateTimeZone('Europe/Berlin');
      $daystart = new \DateTime("now", $tzo);
      $daystart->setDate($sd->GetiYear(SessionData::month), $sd->GetiMonth(SessionData::month), 1);
      $bookinglist = Bookings::GetBookingsForAllPlanes($em, $daystart, $maxdayspermonth, $loggedin_user->getClientid());
      
      $response = $this->render('monthview/monthview.html.twig', 
                            array('monate' => $monate, 'aktmonat' => $aktmonat, 'maxdyspermonth' => $maxdayspermonth,
                                  'year' => $aktYear, 'next' => $nextMonth, 'prev' => $prevMonth, 'month' => $aryMonth, 
                                  'planes' => $planelist,  'bookings' => $bookinglist));
      
      $response->setExpires(new \DateTime());
      return $response;
    }
}
