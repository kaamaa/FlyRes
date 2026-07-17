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

    /**
     * Begrenzt die Anzahl Tokens pro Nutzer: behaelt die $keep neuesten
     * (nach created_at, dann id) und loescht aeltere. Nach jedem Login
     * aufgerufen, damit sich nicht bei jeder Anmeldung eine weitere Zeile
     * dauerhaft ansammelt.
     *
     * @return int Anzahl geloeschter Zeilen
     */
    public function pruneForUser(int $userId, int $keep): int
    {
        $keepIds = $this->createQueryBuilder('t')
            ->select('t.id')
            ->where('t.user = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('t.createdAt', 'DESC')
            ->addOrderBy('t.id', 'DESC')
            ->setMaxResults($keep)
            ->getQuery()
            ->getSingleColumnResult();

        // Limit noch nicht erreicht -> nichts zu loeschen.
        if (count($keepIds) < $keep) {
            return 0;
        }

        return (int) $this->getEntityManager()
            ->createQuery('DELETE FROM App\Entity\FresApiToken t WHERE t.user = :userId AND t.id NOT IN (:keepIds)')
            ->setParameter('userId', $userId)
            ->setParameter('keepIds', $keepIds)
            ->execute();
    }
}
