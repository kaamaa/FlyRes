<?php

namespace App\Command;

use Doctrine\DBAL\ConnectionException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpClient\HttpClient;

class UpdateAirportTimeZonesCommand extends Command
{
    protected static $defaultName = 'app:update-airport-timezones';
    private $entityManager;
    private $username = 'kaamaa'; // GeoNames Benutzername

    public function __construct(EntityManagerInterface $entityManager)              
    {
        parent::__construct();

        $this->entityManager = $entityManager;
    }

    protected function configure()
    {
        $this
            ->setDescription('Aktualisiert die Zeitzonen der Flughäfen in den USA')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $conn = $this->entityManager->getConnection();

        try {
            $conn->connect();
        } catch (ConnectionException $e) {
            $io->error('Die Verbindung zur Datenbank konnte nicht hergestellt werden: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $sql = 'SELECT id, sLat, sLong FROM tools_airport WHERE Country = "US"';
        $stmt = $conn->prepare($sql);
        $resultSet = $stmt->executeQuery();

        $httpClient = HttpClient::create();

        foreach ($resultSet->fetchAllAssociative() as $row) {
            $id = $row['id'];
            $latitude = $row['sLat'];
            $longitude = $row['sLong'];

            // GeoNames API aufrufen
            $url = sprintf(
                'http://api.geonames.org/timezoneJSON?lat=%f&lng=%f&username=%s',
                $latitude,
                $longitude,
                $this->username
            );

            $response = $httpClient->request('GET', $url);
            $data = $response->toArray();

            if (isset($data['timezoneId'])) {
                $timezone = $data['timezoneId'];

                // Zeitzone in der Datenbank speichern
                $updateSql = 'UPDATE tools_airport SET TimeZone = :timezone WHERE id = :id';
                $updateStmt = $conn->prepare($updateSql);
                $updateStmt->execute(['timezone' => $timezone, 'id' => $id]);

                $io->success("Zeitzone für Eintrag mit ID $id erfolgreich aktualisiert.");
            } else {
                $io->error("Zeitzone für Eintrag mit ID $id konnte nicht ermittelt werden.");
            }
        }

        $io->success('Alle Zeitzonen wurden aktualisiert.');

        return Command::SUCCESS;
    }
}
