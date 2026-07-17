<?php
namespace App\Entity;

use App\Repository\FresApiTokenRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FresApiTokenRepository::class)]
#[ORM\Table(name: 'fres_api_tokens')]
#[ORM\Index(name: 'idx_user_last_used', columns: ['user_id', 'last_used_at'])]
#[ORM\UniqueConstraint(name: 'uk_token_hash', columns: ['token_hash'])]
class FresApiToken
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint', options: ['unsigned' => true])]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: FresAccounts::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false)]
    private FresAccounts $user;

    #[ORM\Column(name: 'token_hash', type: 'string', length: 64)]
    private string $tokenHash;

    #[ORM\Column(name: 'device_name', type: 'string', length: 100, nullable: true)]
    private ?string $deviceName = null;

    #[ORM\Column(name: 'user_agent', type: 'string', length: 255, nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Column(name: 'last_ip', type: 'string', length: 45, nullable: true)]
    private ?string $lastIp = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'last_used_at', type: 'datetime', nullable: true)]
    private ?\DateTime $lastUsedAt = null;

    #[ORM\Column(name: 'expires_at', type: 'datetime', nullable: true)]
    private ?\DateTime $expiresAt = null;

    public function __construct(FresAccounts $user, string $tokenHash)
    {
        $this->user = $user;
        $this->tokenHash = $tokenHash;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id === null ? null : (int) $this->id; }
    public function getUser(): FresAccounts { return $this->user; }
    public function getTokenHash(): string { return $this->tokenHash; }

    public function getDeviceName(): ?string { return $this->deviceName; }
    public function setDeviceName(?string $v): self { $this->deviceName = $v; return $this; }

    public function getUserAgent(): ?string { return $this->userAgent; }
    public function setUserAgent(?string $v): self { $this->userAgent = $v; return $this; }

    public function getLastIp(): ?string { return $this->lastIp; }
    public function setLastIp(?string $v): self { $this->lastIp = $v; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getLastUsedAt(): ?\DateTime { return $this->lastUsedAt; }
    public function setLastUsedAt(?\DateTime $v): self { $this->lastUsedAt = $v; return $this; }

    public function getExpiresAt(): ?\DateTime { return $this->expiresAt; }
    public function setExpiresAt(?\DateTime $v): self { $this->expiresAt = $v; return $this; }
}
