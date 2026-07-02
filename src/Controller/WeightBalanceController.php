<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Weight & Balance (erster Prototyp im modernen Frontend, Variante A). Die
 * Flugzeug-Typdaten (Stationen/Arme/Envelope) stammen aus der Garmin-W&B-App
 * (nach JSON konvertiert, data/wb_aircraft_types.json). index() liefert die
 * Auswahlliste, typeJson() die vollen Daten eines Musters fuers Live-Rechnen im
 * Browser. Pfad fuer alle angemeldeten Piloten; Menue-Eintrag nur Global-Admin.
 */
class WeightBalanceController extends AbstractController
{
    /** Laedt die Typ-Datenbank (id => Datensatz). */
    private function loadTypes(): array
    {
        $file = $this->getParameter('kernel.project_dir') . '/data/wb_aircraft_types.json';
        $json = is_file($file) ? (string) file_get_contents($file) : '{}';
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    public function index(): Response
    {
        $this->denyAccessUnlessGranted('ROLE_PILOT');

        $list = [];
        foreach ($this->loadTypes() as $id => $t) {
            $label = trim(($t['manufacturer'] ?? '') . ' ' . ($t['model'] ?? ''));
            if ($label === '') { $label = (string) $id; }
            $list[] = ['id' => (string) $id, 'label' => $label];
        }
        usort($list, static fn ($a, $b) => strcasecmp($a['label'], $b['label']));

        return $this->render('modern/weightbalance.html.twig', ['aircraft' => $list]);
    }

    /** Volle Typdaten eines Musters als JSON (fuer die Live-Berechnung). */
    public function typeJson(string $id): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_PILOT');
        $types = $this->loadTypes();
        if (!isset($types[$id])) {
            return new JsonResponse(['error' => 'not_found'], 404);
        }
        return new JsonResponse($types[$id]);
    }
}
