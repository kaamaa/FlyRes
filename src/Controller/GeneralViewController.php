<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use App\Entities\Bookings;
use App\Entities\Users;
use App\ViewHelper;
use App\SessionData;
use Symfony\Component\Security\Core\User\UserInterface;
use Doctrine\ORM\EntityManagerInterface;

class GeneralViewController extends AbstractController
{
    
    public function ViewAction(Request $request, $command, UserInterface $loggedin_user, EntityManagerInterface $em)
    {
      $em->getConnection()->exec('SET NAMES "UTF8"');
      
      $sd = ViewHelper::GetSessionDataObject($request->getSession());
      // Für den Back-Button im View ViewBookingDetails speichern, wohin zurückgekehrt werden soll
      $str = $request->attributes->get('_route');
      if (isset($str)) $sd->SetBookingDetailBackRoute($this->generateUrl($str, array('command' => $command)));
      
      ViewHelper::StoreSessionDataObject($request->getSession(), $sd);
      
      $user = $loggedin_user;
      $bookings = Bookings::GetBookingsForGeneralView($em, $command, $user->getClientid(), $user->getId());
     
      $response = $this->render('generalview/generalview.html.twig', 
              array('bookings' => $bookings, 'command' => $command));
      $response->setExpires(new \DateTime());
      return $response;
     
    }
}
