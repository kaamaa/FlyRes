<?php
namespace App\Tests\Security;

use App\Repository\FresApiTokenRepository;
use App\Security\BearerTokenAuthenticator;
use App\Service\ApiTokenService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class BearerTokenAuthenticatorTest extends TestCase
{
    private function makeAuthenticator(): BearerTokenAuthenticator
    {
        return new BearerTokenAuthenticator(
            $this->createMock(FresApiTokenRepository::class),
            new ApiTokenService(),
        );
    }

    public function testSupportsReturnsTrueOnBearerHeader(): void
    {
        $auth = $this->makeAuthenticator();
        $req = new Request();
        $req->headers->set('Authorization', 'Bearer flyres_xyz');

        $this->assertTrue($auth->supports($req));
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
}
