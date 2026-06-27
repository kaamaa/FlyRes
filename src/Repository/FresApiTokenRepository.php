<?php
namespace App\Repository;

use App\Entity\FresApiToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FresApiToken>
 */
class FresApiTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FresApiToken::class);
    }

    public function findByHash(string $tokenHash): ?FresApiToken
    {
        return $this->findOneBy(['tokenHash' => $tokenHash]);
    }

    public function save(FresApiToken $token, bool $flush = true): void
    {
        $this->getEntityManager()->persist($token);
        if ($flush) $this->getEntityManager()->flush();
    }

    public function delete(FresApiToken $token, bool $flush = true): void
    {
        $this->getEntityManager()->remove($token);
        if ($flush) $this->getEntityManager()->flush();
    }
}
