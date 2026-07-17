<?php
namespace App\Tests\Security;

use App\Entity\FresAccounts;
use App\Entity\FresApiToken;
use App\Entity\FresClient;
use App\Repository\FresApiTokenRepository;
use App\Security\BearerTokenAuthenticator;
use App\Service\ApiTokenService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;

class BearerTokenAuthenticatorTest extends TestCase
{
    private function makeAuthenticator(
        ?FresApiTokenRepository $tokens = null,
        ?EntityManagerInterface $em = null,
    ): BearerTokenAuthenticator {
        return new BearerTokenAuthenticator(
            $tokens ?? $this->createMock(FresApiTokenRepository::class),
            new ApiTokenService(),
            $em ?? $this->createMock(EntityManagerInterface::class),
        );
    }

    private function bearerRequest(): Request
    {
        $req = new Request();
        $req->headers->set('Authorization', 'Bearer flyres_test');
        return $req;
    }

    /**
     * Baut eine Authenticator-Instanz, deren Token auf $user zeigt und deren
     * Mandant $clientActive ist.
     */
    private function authenticatorForUser(FresAccounts $user, bool $clientActive = true): BearerTokenAuthenticator
    {
        $tokens = $this->createMock(FresApiTokenRepository::class);
        $tokens->method('findByHash')->willReturn(new FresApiToken($user, 'irrelevant-hash'));

        $client = (new FresClient())->setActive($clientActive);
        $clientRepo = $this->createMock(EntityRepository::class);
        $clientRepo->method('find')->willReturn($client);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($clientRepo);

        return $this->makeAuthenticator($tokens, $em);
    }

    private function healthyUser(): FresAccounts
    {
        $u = new FresAccounts();
        $u->setUsername('pilot');
        $u->setClientid(1);
        $u->setStatus(0);
        $u->setIslocked(false);
        return $u;
    }

    public function testSupportsReturnsTrueOnBearerHeader(): void
    {
        $this->assertTrue($this->makeAuthenticator()->supports($this->bearerRequest()));
    }

    public function testSupportsReturnsFalseWithoutHeader(): void
    {
        $this->assertFalse($this->makeAuthenticator()->supports(new Request()));
    }

    public function testSupportsReturnsFalseForBasicAuth(): void
    {
        $auth = $this->makeAuthenticator();
        $req = new Request();
        $req->headers->set('Authorization', 'Basic dXNlcjpwYXNz');

        $this->assertFalse($auth->supports($req));
    }

    public function testSupportsReturnsFalseForEmptyBearer(): void
    {
        $auth = $this->makeAuthenticator();
        $req = new Request();
        $req->headers->set('Authorization', 'Bearer ');

        $this->assertFalse($auth->supports($req));
    }

    public function testAuthenticateSucceedsForHealthyUser(): void
    {
        $auth = $this->authenticatorForUser($this->healthyUser(), clientActive: true);

        $passport = $auth->authenticate($this->bearerRequest());

        $this->assertInstanceOf(Passport::class, $passport);
        // mandantenfaehiger Identifier: "clientid:username"
        $this->assertSame('1:pilot', $passport->getUser()->getUserIdentifier());
    }

    public function testAuthenticateRejectsLockedUser(): void
    {
        $user = $this->healthyUser();
        $user->setIslocked(true);
        $auth = $this->authenticatorForUser($user, clientActive: true);

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('account_locked');
        $auth->authenticate($this->bearerRequest());
    }

    public function testAuthenticateRejectsDeletedUser(): void
    {
        $user = $this->healthyUser();
        $user->setStatus('geloescht');
        $auth = $this->authenticatorForUser($user, clientActive: true);

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('account_locked');
        $auth->authenticate($this->bearerRequest());
    }

    public function testAuthenticateRejectsUserOfDeactivatedClient(): void
    {
        $auth = $this->authenticatorForUser($this->healthyUser(), clientActive: false);

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('account_locked');
        $auth->authenticate($this->bearerRequest());
    }
}
