<?php

namespace App\Controller;

use App\Tools\WbCalc;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
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

    /**
     * Rechnet server-seitig und gibt NUR anzeige-taugliche Werte zurueck
     * (Massen/CG/Verdikt/Stationsmassen + gerendertes Envelope-SVG). Die Rohdaten
     * (Arme, Envelope-Koordinaten, Geometrie, Dichten) verlassen den Server nicht.
     * Erwartet POST: id, emptyMass, emptyArm, tripFuel, load[station]=wert.
     */
    public function calc(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_PILOT');

        $id = (string) $request->request->get('id', '');
        $types = $this->loadTypes();
        if (!isset($types[$id])) {
            return new JsonResponse(['error' => 'not_found'], 404);
        }
        $type = $types[$id];

        $emStr = trim((string) $request->request->get('emptyMass', ''));
        $eaStr = trim((string) $request->request->get('emptyArm', ''));
        $em = $emStr !== '' ? (float) $emStr : (float) ($type['defaultEmptyMass'] ?? 0);
        $ea = $eaStr !== '' ? (float) $eaStr : (float) ($type['defaultEmptyArm'] ?? 0);
        $trip = max(0.0, (float) $request->request->get('tripFuel', 0));

        $loadRaw = $request->request->all('load');
        $load = is_array($loadRaw) ? array_map(static fn ($v) => (float) $v, $loadRaw) : [];

        $result = WbCalc::calculate($type, $em, $ea, $trip, $load);
        // Default-Leerwerte fuers Vorbefuellen der Eingabefelder mitgeben.
        $result['defaults'] = ['emptyMass' => $type['defaultEmptyMass'] ?? null, 'emptyArm' => $type['defaultEmptyArm'] ?? null];
        $result['source']   = $type['source'] ?? null;

        return new JsonResponse($result);
    }
}
