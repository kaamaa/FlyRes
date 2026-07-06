<?php

namespace App\Command;

use App\Entity\FresAccounts;
use App\Entity\FresClient;
use App\Entity\FresFunction;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Legt einen Nutzer mit der Rolle "Pilot" (ROLE_PILOT) an.
 *
 * Das Passwort wird interaktiv und verdeckt abgefragt (nicht als Argument),
 * damit es nicht in der Shell-History landet. Verwendung z. B.:
 *
 *   php bin/console app:create-pilot Test --client=4
 */
#[AsCommand(name: 'app:create-pilot')]
class CreatePilotCommand extends Command
{
    private EntityManagerInterface $em;
    private UserPasswordHasherInterface $hasher;

    public function __construct(EntityManagerInterface $em, UserPasswordHasherInterface $hasher)
    {
        parent::__construct();
        $this->em = $em;
        $this->hasher = $hasher;
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Legt einen Piloten (ROLE_PILOT) an; Passwort wird verdeckt abgefragt.')
            ->addArgument('username', InputArgument::REQUIRED, 'Nutzername (Login)')
            ->addOption('client', 'c', InputOption::VALUE_REQUIRED, 'Mandanten-ID (clientid)')
            ->addOption('firstname', null, InputOption::VALUE_REQUIRED, 'Vorname')
            ->addOption('lastname', null, InputOption::VALUE_REQUIRED, 'Nachname')
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'E-Mail-Adresse');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $username  = trim((string) $input->getArgument('username'));
        $clientid  = (int) $input->getOption('client');
        $firstname = (string) ($input->getOption('firstname') ?? $username);
        $lastname  = (string) ($input->getOption('lastname') ?? 'Pilot');
        $email     = (string) ($input->getOption('email') ?? strtolower($username) . '@test.local');

        if ($username === '') {
            $io->error('Nutzername darf nicht leer sein.');
            return Command::FAILURE;
        }

        // Mandant prüfen
        $client = $clientid ? $this->em->getRepository(FresClient::class)->find($clientid) : null;
        if (!$client) {
            $io->error('Bitte einen gültigen Mandanten via --client=<id> angeben.');
            foreach ($this->em->getRepository(FresClient::class)->findBy([], ['id' => 'ASC']) as $c) {
                $io->writeln(sprintf('  %d = %s', $c->getId(), $c->getName()));
            }
            return Command::FAILURE;
        }

        // Namenskollision (auch mit gelöschten Nutzern) verhindern – der Login lädt
        // per username+clientid und würde sonst mehrdeutig.
        $existing = $this->em->getRepository(FresAccounts::class)->findOneBy([
            'username' => $username, 'clientid' => $clientid,
        ]);
        if ($existing) {
            $io->error(sprintf(
                'Auf Mandant %d existiert bereits ein Nutzer "%s" (Status: %s). Bitte anderen Namen oder Mandanten wählen.',
                $clientid, $existing->getUsername(), $existing->getStatus() ?: '0'
            ));
            return Command::FAILURE;
        }

        // Pilot-Funktion (ROLE_PILOT) laden
        $pilot = $this->em->getRepository(FresFunction::class)->findOneBy(['role' => 'ROLE_PILOT']);
        if (!$pilot) {
            $io->error('Funktion ROLE_PILOT nicht gefunden.');
            return Command::FAILURE;
        }

        // Passwort verdeckt abfragen (+ Bestätigung)
        $helper = $this->getHelper('question');
        $q1 = (new Question('Passwort: '))->setHidden(true)->setHiddenFallback(false);
        $q2 = (new Question('Passwort wiederholen: '))->setHidden(true)->setHiddenFallback(false);
        $pass  = (string) $helper->ask($input, $output, $q1);
        $pass2 = (string) $helper->ask($input, $output, $q2);
        if ($pass === '' || $pass !== $pass2) {
            $io->error('Passwörter leer oder nicht identisch.');
            return Command::FAILURE;
        }
        if (strlen($pass) < 5) {
            $io->error('Das Passwort muss mindestens 5 Zeichen lang sein.');
            return Command::FAILURE;
        }

        $user = new FresAccounts();
        $user->setClientid($clientid);
        $user->setFirstname($firstname);
        $user->setLastname($lastname);
        $user->setUsername($username);
        $user->setEmail($email);
        $user->setStatus(0);
        $user->setGetbookingmails(1);
        $user->setGetlicencemails(1);
        $user->setPassword($this->hasher->hashPassword($user, $pass));
        $user->getFunction()->add($pilot);

        $this->em->persist($user);
        $this->em->flush();

        $io->success(sprintf(
            'Pilot "%s" auf Mandant %d (%s) angelegt. Login: %d:%s',
            $username, $clientid, $client->getName(), $clientid, $username
        ));

        return Command::SUCCESS;
    }
}
