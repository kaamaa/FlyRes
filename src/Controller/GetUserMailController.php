<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use App\ViewHelper;
use Symfony\Component\HttpFoundation\Request;
use App\Entities\Users;
use Symfony\Component\Security\Core\User\UserInterface;
use Doctrine\ORM\EntityManagerInterface;

class GetUserMailController extends AbstractController
{
  public function ViewAction(Request $request, UserInterface $loggedin_user, EntityManagerInterface $em)
  {
    $em->getConnection()->exec('SET NAMES "UTF8"');

    $Mails_Outlook = Users::GetAllValidMailsadresses($em, $loggedin_user->getClientid(), '; ');
    $Mails_Apple = Users::GetAllValidMailsadresses($em, $loggedin_user->getClientid(), ', ');

    return $this->render('getmailadress/getmailadress.html.twig', 
            array('mails_outlook' => $Mails_Outlook, 'mails_apple' => $Mails_Apple));
  }
}
