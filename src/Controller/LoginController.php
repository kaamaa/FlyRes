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
use Symfony\Component\Security\Http\RememberMe\RememberMeHandlerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Bundle\SecurityBundle\Security;
use App\LogonType;
use Doctrine\ORM\EntityManagerInterface;


class LoginController extends AbstractController
{
  public function login(Request $request, AuthenticationUtils $authenticationUtils, EntityManagerInterface $em): Response
  {
    
    // get the login error if there is one
    $error = $authenticationUtils->getLastAuthenticationError();

    // last username entered by the user
    $lastUsername = $authenticationUtils->getLastUsername();
    
    $response = $this->render('login/modern.html.twig', [
         'last_username' => $lastUsername,
         'clients'       => Clients::GetAllClientsForListbox($em),
         'selected'      => "ASW",
         'error'         => $error,
    ]);
    //$response->headers->clearCookie('REMEMBERME');
    return $response;
  }
  
  public function loginwithcredentials(Request $request,
                                       UserCheckerInterface $checker, 
                                       UserAuthenticatorInterface $userAuthenticator, 
                                       FlyResAuthenticator $LoginAuthenticator, 
                                       EntityManagerInterface $em) : Response
  {
    // Die Funktion wird von Joomla direkt aufgerufen
    $session = $request->getSession();
    
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

  /**
   * Saubere JSON-Login-API fuer das Joomla-Login-Modul.
   *
   * Aufruf browser-seitig per fetch() (gleiche Domain), z.B.:
   *   fetch('/api/login', {
   *     method: 'POST',
   *     headers: { 'Content-Type': 'application/json' },
   *     credentials: 'same-origin',
   *     body: JSON.stringify({ username, password })
   *   })
   *
   * Bei Erfolg wird das Session-Cookie als First-Party-Cookie im Browser gesetzt,
   * sodass FlyRes anschliessend im iframe (gleiche Domain) eingeloggt laeuft.
   *
   * Antwort: { "success": true } bzw. { "success": false, "error": "<code>" }
   */
  public function apiLogin(Request $request,
                          UserCheckerInterface $checker,
                          UserAuthenticatorInterface $userAuthenticator,
                          FlyResAuthenticator $loginAuthenticator,
                          UserPasswordHasherInterface $passwordHasher,
                          RememberMeHandlerInterface $rememberMeHandler,
                          EntityManagerInterface $em): JsonResponse
  {
    $data = json_decode($request->getContent(), true);
    $username   = $data['username'] ?? null;
    $password   = $data['password'] ?? null;
    $clientName = $data['client']   ?? null;
    $remember   = !empty($data['remember']);

    if (!$username || !$password) {
      return new JsonResponse(['success' => false, 'error' => 'missing_credentials'], 400);
    }

    // Mandant: per Name aufloesen, sonst Default-Mandant "1" (Homepage Flugschule-Worms)
    $clientid = $clientName ? Clients::GetClientIdByName($em, $clientName) : 1;

    $user = Users::GetUserObjectByName($em, $username, $clientid);
    if (!$user || !$passwordHasher->isPasswordValid($user, $password)) {
      return new JsonResponse(['success' => false, 'error' => 'invalid_credentials'], 401);
    }
    if (Users::isDeleted($user) || Users::isLocked($user)) {
      return new JsonResponse(['success' => false, 'error' => 'account_locked'], 403);
    }
    try {
      $checker->checkPreAuth($user);
    } catch (\Throwable $e) {
      return new JsonResponse(['success' => false, 'error' => 'account_not_allowed'], 403);
    }

    // Programmatischer Login: setzt das Security-Token -> Session-Cookie wird automatisch gesetzt.
    $userAuthenticator->authenticateUser($user, $loginAuthenticator, $request);
    LogonType::defineInFrame($request->getSession());

    // "Angemeldet bleiben": dauerhaftes Remember-Me-Cookie (30 Tage, s. security.yaml)
    // erzeugen. Das Cookie wird vom Security-ResponseListener an die Antwort gehaengt.
    if ($remember) {
      $rememberMeHandler->createRememberMeCookie($user);
    }

    return new JsonResponse(['success' => true]);
  }

  /**
   * JSON-Logout-API fuer das Joomla-Login-Modul.
   *
   * Aufruf browser-seitig per fetch('/api/logout', { method: 'POST', credentials: 'same-origin' }).
   * Invalidiert die Session und loescht das RememberMe-Cookie.
   */
  public function apiLogout(Request $request, Security $security): JsonResponse
  {
    if ($security->getUser()) {
      // Feuert das LogoutEvent -> Session wird gemaess Firewall-Config invalidiert.
      $security->logout(false);
    }
    $response = new JsonResponse(['success' => true]);
    $response->headers->clearCookie('REMEMBERME', '/');
    return $response;
  }

  /**
   * Zustandslose Pruefung von Zugangsdaten (KEIN Login, KEIN Cookie).
   *
   * Wird vom Joomla-Modul server-seitig aufgerufen, um vor dem Anmelden des
   * technischen Joomla-Accounts sicherzustellen, dass ein gueltiger FlyRes-Login
   * vorliegt. Gibt bei Erfolg minimale Userdaten zurueck.
   *
   * Antwort: { "success": true, "user": { "username": "..." } } bzw.
   *          { "success": false, "error": "<code>" }
   */
  public function apiVerify(Request $request,
                           UserPasswordHasherInterface $passwordHasher,
                           EntityManagerInterface $em): JsonResponse
  {
    $data = json_decode($request->getContent(), true);
    $username   = $data['username'] ?? null;
    $password   = $data['password'] ?? null;
    $clientName = $data['client']   ?? null;

    if (!$username || !$password) {
      return new JsonResponse(['success' => false, 'error' => 'missing_credentials'], 400);
    }

    $clientid = $clientName ? Clients::GetClientIdByName($em, $clientName) : 1;

    $user = Users::GetUserObjectByName($em, $username, $clientid);
    if (!$user || !$passwordHasher->isPasswordValid($user, $password)) {
      return new JsonResponse(['success' => false, 'error' => 'invalid_credentials'], 401);
    }
    if (Users::isDeleted($user) || Users::isLocked($user)) {
      return new JsonResponse(['success' => false, 'error' => 'account_locked'], 403);
    }

    return new JsonResponse([
      'success' => true,
      'user'    => ['username' => $user->getUsername()],
    ]);
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