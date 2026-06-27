<?php

namespace App\Controller;

use App\Entity\FresClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Zentrale Konfiguration (nur ROLE_GLOBAL_ADMIN).
 *
 * Stufe 1: Mandanten (Flugschulen) verwalten inkl. Flugplatz-/Öffnungszeiten,
 * die die bisher hardcodierten Worms-Werte in TimeFunctions ersetzen.
 *
 * Bewusst eigener Controller + eigene Routen (config/routes/admin.yaml) und
 * eigene Templates (templates/admin/), um bestehende Bereiche nicht zu berühren.
 */
class AdminConfigController extends AbstractController
{
    private function denyUnlessGlobalAdmin(): void
    {
        if (!$this->isGranted('ROLE_GLOBAL_ADMIN')) {
            throw $this->createAccessDeniedException();
        }
    }

    /** GET /config/mandanten — Liste aller Mandanten. */
    public function mandanten(EntityManagerInterface $em): Response
    {
        $this->denyUnlessGlobalAdmin();

        $clients = $em->getRepository(FresClient::class)->findBy([], ['name' => 'ASC']);
        $stats = [];
        foreach ($clients as $c) {
            $stats[$c->getId()] = [
                'users'    => (int) $em->getRepository('App\Entity\FresAccounts')->count(['clientid' => $c->getId()]),
                'aircraft' => (int) $em->getRepository('App\Entity\FresAircraft')->count(['clientid' => $c->getId()]),
            ];
        }

        return $this->render('admin/mandanten.html.twig', ['clients' => $clients, 'stats' => $stats]);
    }

    /** POST /config/mandant/{id}/toggle — Mandant (de)aktivieren. */
    public function toggleActive(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyUnlessGlobalAdmin();

        if (!$this->isCsrfTokenValid('mandant_toggle', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges CSRF-Token.');
        }

        $client = $em->getRepository(FresClient::class)->find($id);
        if (!$client) {
            throw $this->createNotFoundException('Mandant nicht gefunden.');
        }

        $client->setActive(!$client->isActive());
        $em->flush();

        return $this->redirectToRoute('_config_mandanten');
    }

    /** GET /config/mandant/{id} — Mandant bearbeiten. */
    public function edit(int $id, EntityManagerInterface $em): Response
    {
        $this->denyUnlessGlobalAdmin();

        $client = $em->getRepository(FresClient::class)->find($id);
        if (!$client) {
            throw $this->createNotFoundException('Mandant nicht gefunden.');
        }

        return $this->render('admin/mandant_edit.html.twig', [
            'client' => $client,
            'isNew'  => false,
        ]);
    }

    /** GET /config/mandant/new — neuen Mandanten anlegen. */
    public function new(): Response
    {
        $this->denyUnlessGlobalAdmin();

        return $this->render('admin/mandant_edit.html.twig', [
            'client' => new FresClient(),
            'isNew'  => true,
        ]);
    }

    /**
     * POST /config/mandant/{id}  (Update)  bzw.  POST /config/mandant  (Neu).
     */
    public function save(Request $request, EntityManagerInterface $em, ?int $id = null): Response
    {
        $this->denyUnlessGlobalAdmin();

        if (!$this->isCsrfTokenValid('mandant', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges CSRF-Token.');
        }

        if ($id !== null) {
            $client = $em->getRepository(FresClient::class)->find($id);
            if (!$client) {
                throw $this->createNotFoundException('Mandant nicht gefunden.');
            }
        } else {
            $client = new FresClient();
        }

        $p = $request->request;
        $client->setName(trim((string) $p->get('name')));
        $client->setActive($p->getBoolean('active'));

        $em->persist($client);
        $em->flush();

        return $this->redirectToRoute('_config_mandanten');
    }
}
