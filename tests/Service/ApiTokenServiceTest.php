<?php
namespace App\Tests\Service;

use App\Service\ApiTokenService;
use PHPUnit\Framework\TestCase;

class ApiTokenServiceTest extends TestCase
{
    public function testGenerateReturnsTokenWithPrefixAndCorrectLength(): void
    {
        $svc = new ApiTokenService();
        $token = $svc->generate();

        $this->assertStringStartsWith('flyres_', $token);
        // 7 (Prefix) + 43 (base64url von 32 Bytes ohne Padding) = 50
        $this->assertSame(50, strlen($token));
        $this->assertMatchesRegularExpression('/^flyres_[A-Za-z0-9_-]{43}$/', $token);
    }

    public function testGenerateProducesUniqueTokens(): void
    {
        $svc = new ApiTokenService();
        $tokens = [];
        for ($i = 0; $i < 100; $i++) {
            $tokens[] = $svc->generate();
        }
        $this->assertCount(100, array_unique($tokens));
    }

    public function testHashReturnsSha256HexOfFullToken(): void
    {
        $svc = new ApiTokenService();
        $token = 'flyres_test123';
        $expected = hash('sha256', $token);

        $this->assertSame($expected, $svc->hash($token));
        $this->assertSame(64, strlen($svc->hash($token)));
    }

    public function testHashIsDeterministic(): void
    {
        $svc = new ApiTokenService();
        $token = $svc->generate();
        $this->assertSame($svc->hash($token), $svc->hash($token));
    }
}
