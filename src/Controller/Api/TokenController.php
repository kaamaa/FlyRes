<?php
namespace App\Controller\Api;

use App\Entities\Clients;
use App\Entities\Users;
use App\Entity\FresApiToken;
use App\Repository\FresApiTokenRepository;
use App\Security\BearerTokenAuthenticator;
use App\Service\ApiTokenService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\User\UserCheckerInterface;

/**
 * Endpunkte fuer Bearer-Token (App-Login, Logout, Verwaltung).
 *
 * - POST   /api/tokens         -> Login, gibt Klartext-Token + User zurueck
 * - DELETE /api/tokens/current -> aktuelles Token widerrufen (App-Logout)
 * - GET    /api/tokens         -> eigene Tokens auflisten
 * - DELETE /api/tokens/{id}    -> einzelnes anderes Token widerrufen
 */
class TokenController extends ApiController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FresApiTokenRepository $tokens,
        private readonly ApiTokenService $tokenService,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly UserCheckerInterface $userChecker,
        private readonly RateLimiterFactoryInterface $apiTokenLoginLimiter,
    ) {}

    public function create(Request $request): JsonResponse
    {
        // Rate-Limit pro IP (Brute-Force-Schutz, nicht pro Username, sonst kann
        // ein Angreifer den Account des Opfers gezielt lockout-en)
        $limiter = $this->apiTokenLoginLimiter->create($request->getClientIp() ?? 'unknown');
        if (false === $limiter->consume(1)->isAccepted()) {
            return $this->json(['error' => 'rate_limited'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $username   = $data['username']    ?? null;
        $password   = $data['password']    ?? null;
        $clientName = $data['client']      ?? null;
        $deviceName = $data['device_name'] ?? null;

        if (!$username || !$password) {
            return $this->json(['error' => 'missing_credentials'], Response::HTTP_BAD_REQUEST);
        }

        $clientid = $clientName ? Clients::GetClientIdByName($this->em, $clientName) : 1;
        $user = Users::GetUserObjectByName($this->em, $username, $clientid);
        if (!$user || !$this->passwordHasher->isPasswordValid($user, $password)) {
            return $this->json(['error' => 'invalid_credentials'], Response::HTTP_UNAUTHORIZED);
        }
        if (Users::isDeleted($user) || Users::isLocked($user)) {
            return $this->json(['error' => 'account_locked'], Response::HTTP_FORBIDDEN);
        }
        try {
            $this->userChecker->checkPreAuth($user);
        } catch (\Throwable) {
            return $this->json(['error' => 'account_not_allowed'], Response::HTTP_FORBIDDEN);
        }

        $plain = $this->tokenService->generate();
        $token = new FresApiToken($user, $this->tokenService->hash($plain));
        if ($deviceName) {
            $token->setDeviceName(mb_substr($deviceName, 0, 100));
        }
        $token->setUserAgent(substr((string) $request->headers->get('User-Agent', ''), 0, 255));
        $token->setLastIp($request->getClientIp());
        $this->tokens->save($token);

        $roles = $user->getRoles();
        return $this->json([
            'token' => $plain, // einmaliger Klartext-Wert
            'user'  => [
                'id'           => $user->getId(),
                'username'     => $user->getUsername(),
                'firstname'    => $user->getFirstname(),
                'lastname'     => $user->getLastname(),
                'email'        => $user->getEmail(),
                'clientid'     => $user->getClientid(),
                'roles'        => $roles,
                'isInstructor' => in_array('ROLE_FI', $roles, true),
                'isAdmin'      => in_array('ROLE_ADMIN', $roles, true),
            ],
        ]);
    }

    public function revokeCurrent(Request $request): JsonResponse
    {
        $user = $this->requirePilot();
        if ($user instanceof JsonResponse) return $user;

        $currentId = $request->attributes->get(BearerTokenAuthenticator::REQUEST_ATTR);
        if ($currentId === null) {
            return $this->json(['error' => 'no_token_in_request'], Response::HTTP_BAD_REQUEST);
        }

        $token = $this->tokens->find($currentId);
        if ($token === null || (int) $token->getUser()->getId() !== (int) $user->getId()) {
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }
        $this->tokens->delete($token);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    public function list(Request $request): JsonResponse
    {
        $user = $this->requirePilot();
        if ($user instanceof JsonResponse) return $user;

        $currentId = $request->attributes->get(BearerTokenAuthenticator::REQUEST_ATTR);

        $items = $this->tokens->findBy(['user' => $user], ['lastUsedAt' => 'DESC', 'createdAt' => 'DESC']);
        $out = [];
        foreach ($items as $t) {
            $out[] = [
                'id'           => $t->getId(),
                'device_name'  => $t->getDeviceName(),
                'created_at'   => $t->getCreatedAt()->format(\DateTimeInterface::ATOM),
                'last_used_at' => $t->getLastUsedAt()?->format(\DateTimeInterface::ATOM),
                'last_ip'      => $t->getLastIp(),
                'is_current'   => $t->getId() === $currentId,
            ];
        }
        return $this->json($out);
    }

    public function revoke(Request $request, int $id): JsonResponse
    {
        $user = $this->requirePilot();
        if ($user instanceof JsonResponse) return $user;

        $token = $this->tokens->find($id);
        if ($token === null || (int) $token->getUser()->getId() !== (int) $user->getId()) {
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }
        $this->tokens->delete($token);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
