<?php

namespace App\Repository;

use App\Entity\FresAccounts;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Security\User\UserLoaderInterface;
use Doctrine\ORM\EntityManagerInterface;
use App\Entities\Users;

class UserRepository extends ServiceEntityRepository implements UserLoaderInterface
{
    // Das UserRepository wird verwendet um beim Login den Nutzer bei dem richtigen Client zu laden
    private $entityManager;
    private $client;
            
    public function __construct(ManagerRegistry $registry, EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager; 
        parent::__construct($registry, FresAccounts::class);
    }
    
  
    public function setClient($client) 
    {
      // Wir vom Loginformular gesetzt
      $this->client = $client;
    }


    public function loadUserByIdentifier(string $identifier): ?FresAccounts
    {
        // Mandantenfaehiger Identifier "clientid:username" (siehe FresAccounts::getUserIdentifier).
        // Damit laedt auch Remember-Me / der Provider exakt den richtigen Mandanten.
        // Fallback: per setClient() gesetzter Mandant (Login-Formular), falls kein ":" enthalten.
        $clientid = $this->client;
        $username = $identifier;
        if (str_contains($identifier, ':')) {
            [$clientid, $username] = explode(':', $identifier, 2);
        }

        // Ohne Mandant keine eindeutige Identitaet -> nicht laden
        if ($clientid === null || $clientid === '') {
            return null;
        }

        $user = $this->entityManager->createQuery(
                'SELECT u
                FROM App\Entity\FresAccounts u
                WHERE u.username = :query and
                u.clientid = :client'
            )
            ->setParameter('query', $username)
            ->setParameter('client', $clientid)
            ->getOneOrNullResult();
        if($user)
        {
          if (!Users::isDeleted($user) && !Users::isLocked($user))
          {
            return $user;
          }
        }
        return null;
    }

    
    public function find($id, $lockMode = null, $lockVersion = null)
    {
        // holt den User basierend auf einer ID (clientID wird dazu nicht benötigt weil die ID eindeutig ist)
        $user = $this->entityManager->createQuery(
                'SELECT u
                FROM App\Entity\FresAccounts u
                WHERE u.id = :query'
            )
            ->setParameter('query', $id)
            ->getOneOrNullResult();
        return $user;
    }

    /** @deprecated since Symfony 5.3 */
    public function loadUserByUsername(string $usernameOrEmail): ?User
    {
        return $this->loadUserByIdentifier($usernameOrEmail);
    }
}

