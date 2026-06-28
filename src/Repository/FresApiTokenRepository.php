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

    /**
     * Loescht alle API-Tokens eines Nutzers (Bulk-Delete via DQL).
     * Ersetzt das durch den fehlenden FK weggefallene ON DELETE CASCADE.
     *
     * @return int Anzahl geloeschter Zeilen
     */
    public function deleteAllForUser(int $userId): int
    {
        return (int) $this->getEntityManager()
            ->createQuery('DELETE FROM App\Entity\FresApiToken t WHERE t.user = :userId')
            ->setParameter('userId', $userId)
            ->execute();
    }
}
