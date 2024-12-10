<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use App\Security\FlyResAuthenticator;
use Symfony\Component\HttpFoundation\JsonResponse;
use App\Entities\Clients;
use App\Entities\Users;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Http\Authentication\UserAuthenticatorInterface;
use App\LogonType;


class LoginController extends AbstractController
{
  public function login(Request $request, AuthenticationUtils $authenticationUtils): Response
  {
    
    $em = $this->getDoctrine()->getManager();
    // get the login error if there is one
    $error = $authenticationUtils->getLastAuthenticationError();

    // last username entered by the user
    $lastUsername = $authenticationUtils->getLastUsername();
    
    return $this->render('login/index.html.twig', [
         'last_username' => $lastUsername,
         'clients'       => Clients::GetAllClientsForListbox($em),
         'selected'      => "ASW",
         'error'         => $error,
    ]);
  }
  
  public function loginwithcredentials(Request $request,
                                       UserCheckerInterface $checker, 
                                       UserAuthenticatorInterface $userAuthenticator, 
                                       FlyResAuthenticator $LoginAuthenticator) : Response
  {
    $session = $request->getSession();
    $em = $this->getDoctrine()->getManager();
    
    $str = $_SERVER['QUERY_STRING'];
    $str1 = str_replace("%22", '"', $str);

    $parameters = json_decode($str1, true, JSON_UNESCAPED_UNICODE);
    $username = $parameters['username'];
    $password = $parameters['password'];
 
    if ($username && $password)
    {
      // Da die Funktion nur der Homepage Flugschule-Worms augferufen wird kann der Mandant auf "1" festgelegt werden
      $user = Users::GetUserObjectByName($em, $username, 1);
      if ($user)
      {
        if ($password === $user->getPassword())
        { 
          if (!Users::isDeleted($user) && !Users::isLocked($user))
          {
            $checker->checkPreAuth($user);
            // Der folgenden Aufruf bewirkt einen Aufruf von createAuthenticatedToken im FlyResAuthenticator
            $userAuthenticator->authenticateUser($user, $LoginAuthenticator, $request);
            
            LogonType::defineInFrame($session);
      
            // Das Ergbnis des Authetifizierungsprozess wird nicht zurückgegeben
            // Die Flugschule-Worms Homepage erwarte bei erfolgreichem Login den Usernamen im Format md5(Username) 
            // und die Session ID zurück
            $items = array("username" => md5($user->getUsername()), "id" => $session->getId());
            $ret = new JsonResponse($items);
            return $ret;
          }
        }
      }
    }
    return new Response('Login fehlgeschlagen');        
  }
  
  /*
  public function login_json(Session $session, Request $request) : Response
  {
    // Wird nicht verwendet, das beim Json Login keine Session gestartet wird
    $em = $this->getDoctrine()->getManager();
    //$username = 'Martin';
    $parameters = json_decode($request->getContent(), true);
    $username = $parameters['username'];
    //$password = $parameters['password'];
    $user = Users::GetUserObjectByName($em, $username, 1);
    //$html = file_get_contents('/symfony54/public/weeksview');
    $items = array("username" => md5($user->getUsername()), "id" => $session->getId());
        return new JsonResponse($items);
  }
   * 
   */
}