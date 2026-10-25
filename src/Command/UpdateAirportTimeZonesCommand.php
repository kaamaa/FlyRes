<?php

namespace App\Command;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Ermittelt fuer jeden Flugplatz (tools_airports.Type = 'A') die IANA-Zeitzone per
 * Geo-Lookup aus den vorhandenen Koordinaten (sLat/sLong) und speichert sie in der neuen
 * Spalte tools_airports.Timezone. Das ist Schritt 2 der Sonnenzeiten-Korrektur (Schritt 1:
 * tools_airport_timezone_map, siehe Sunrise_SunsetController) - deckt auch Flugplaetze
 * ohne brauchbares TIME-Feld ab und ist fuer jedes Land genauer als die eine Zeitzone aus
 * tools_countries.
 *
 * Ersetzt den fruehren, nie fertiggestellten Befehl gleichen Namens: der zeigte auf die
 * falsche Tabelle ("tools_airport" statt "tools_airports"), eine nicht existierende Spalte
 * und schickte die Koordinaten im Rohformat ("N39313473") an eine externe GeoNames-API statt
 * sie vorher in Dezimalgrad umzurechnen.
 *
 * Arbeitet komplett offline (keine API, kein Rate-Limit) mit den oeffentlichen
 * Zeitzonen-Grenzdaten von timezone-boundary-builder (https://github.com/evansiroky/
 * timezone-boundary-builder, Public Domain / ODbL). Die GeoJSON-Datei (~170 MB entpackt)
 * wird einmalig nach var/ heruntergeladen und von da an wiederverwendet.
 *
 * Schreibt zusaetzlich jede erfolgreiche Zuordnung als UPDATE-Anweisung in eine SQL-Datei,
 * damit dieselben Werte ohne erneuten Download/Rechenlauf auf dem Produktivserver
 * eingespielt werden koennen (siehe CLAUDE.md-Deploy-Konvention: manuelle SQL-Dateien unter
 * sql/).
 */
#[AsCommand(name: 'app:update-airport-timezones', description: 'Ermittelt Flugplatz-Zeitzonen per Geo-Lookup aus sLat/sLong')]
class UpdateAirportTimeZonesCommand extends Command
{
    private const DATASET_URL = 'https://github.com/evansiroky/timezone-boundary-builder/releases/latest/download/timezones.geojson.zip';
    private const CACHE_DIR = '%s/var/geo-timezones';

    public function __construct(
        private EntityManagerInterface $entityManager,
        #[Autowire('%kernel.project_dir%')] private string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('sql-out', null, InputOption::VALUE_REQUIRED, 'Pfad fuer die generierte Deploy-SQL-Datei', 'sql/2026-10-25-airport-geo-timezone.sql')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Nur die ersten N Flugplaetze verarbeiten (zum Testen)')
            ->addOption('country', null, InputOption::VALUE_REQUIRED, 'Nur ein Land verarbeiten (Country_Code, z.B. US)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $cacheDir = sprintf(self::CACHE_DIR, $this->projectDir);
        $geojsonPath = $cacheDir . '/combined.json';

        if (!$this->ensureDataset($io, $cacheDir, $geojsonPath)) {
            return Command::FAILURE;
        }

        $io->section('Lade Zeitzonen-Grenzdaten …');
        ini_set('memory_limit', '3072M');
        $t0 = microtime(true);
        $data = json_decode(file_get_contents($geojsonPath), true, 512, JSON_THROW_ON_ERROR);
        $features = $this->precomputeBoundingBoxes($data['features']);
        $io->writeln(sprintf('%d Zeitzonen-Polygone geladen in %.1fs', count($features), microtime(true) - $t0));

        $conn = $this->entityManager->getConnection();
        $sql = 'SELECT id, sLat, sLong, Country FROM tools_airports WHERE Type = "A" AND sLat IS NOT NULL AND sLong IS NOT NULL AND sLat != "" AND sLong != ""';
        $params = [];
        if ($country = $input->getOption('country')) {
            $sql .= ' AND Country = :country';
            $params['country'] = $country;
        }
        $sql .= ' ORDER BY id';
        if ($limit = $input->getOption('limit')) {
            $sql .= ' LIMIT ' . (int) $limit;
        }
        $rows = $conn->executeQuery($sql, $params)->fetchAllAssociative();

        $io->section(sprintf('Verarbeite %d Flugplätze …', count($rows)));
        $sqlOutPath = $this->projectDir . '/' . ltrim($input->getOption('sql-out'), '/');
        $sqlOut = fopen($sqlOutPath, 'w');
        fwrite($sqlOut, "-- Automatisch generiert von app:update-airport-timezones am " . date('Y-m-d H:i') . "\n");
        fwrite($sqlOut, "-- IANA-Zeitzone je Flugplatz, per Geo-Lookup aus sLat/sLong ermittelt.\n");
        fwrite($sqlOut, "-- Manuell ausfuehren: mysql -u <user> -p <database> < " . basename($sqlOutPath) . "\n\n");

        $ok = 0; $noMatch = 0; $badCoord = 0;
        $progress = $io->createProgressBar(count($rows));
        $t1 = microtime(true);

        foreach ($rows as $row) {
            $progress->advance();
            $coords = $this->parseCoordinates($row['sLat'], $row['sLong']);
            if ($coords === null) {
                $badCoord++;
                continue;
            }
            [$lat, $lon] = $coords;
            $tzid = $this->findTimezone($lon, $lat, $features);
            if ($tzid === null) {
                $noMatch++;
                continue;
            }
            $conn->executeStatement('UPDATE tools_airports SET Timezone = :tz WHERE id = :id', ['tz' => $tzid, 'id' => $row['id']]);
            fwrite($sqlOut, sprintf("UPDATE tools_airports SET Timezone = %s WHERE id = %d;\n", $conn->quote($tzid), $row['id']));
            $ok++;
        }
        $progress->finish();
        fclose($sqlOut);

        $io->newLine(2);
        $io->success(sprintf(
            '%d Flugplätze zugeordnet in %.1fs. %d ohne Treffer (z.B. Wasserflaeche/Antarktis), %d mit unlesbaren Koordinaten.',
            $ok, microtime(true) - $t1, $noMatch, $badCoord
        ));
        $io->writeln('Deploy-SQL geschrieben nach: ' . $sqlOutPath);

        return Command::SUCCESS;
    }

    private function ensureDataset(SymfonyStyle $io, string $cacheDir, string $geojsonPath): bool
    {
        if (is_file($geojsonPath)) {
            return true;
        }
        if (!is_dir($cacheDir) && !mkdir($cacheDir, 0775, true) && !is_dir($cacheDir)) {
            $io->error("Konnte Cache-Verzeichnis nicht anlegen: $cacheDir");
            return false;
        }

        $io->section('Lade Zeitzonen-Grenzdaten herunter (einmalig, ~50 MB) …');
        $zipPath = $cacheDir . '/timezones.zip';
        $ch = curl_init(self::DATASET_URL);
        $fp = fopen($zipPath, 'w');
        curl_setopt_array($ch, [CURLOPT_FILE => $fp, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 120]);
        $ok = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        if (!$ok || $httpCode >= 400) {
            $io->error("Download fehlgeschlagen (HTTP $httpCode). URL: " . self::DATASET_URL);
            return false;
        }

        $io->writeln('Entpacke …');
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            $io->error('ZIP-Datei konnte nicht geoeffnet werden.');
            return false;
        }
        $zip->extractTo($cacheDir);
        $zip->close();
        unlink($zipPath);

        return is_file($geojsonPath);
    }

    /**
     * sLat/sLong liegen im Format "N39313473" (Breite, 9 Zeichen) bzw. "W107433698"
     * (Laenge, 10 Zeichen) vor - Himmelsrichtung + Grad + Minuten + Sekunden.hundertstel.
     * Gleiche Umrechnung wie Sunrise_SunsetController::convertToDecimal().
     */
    private function parseCoordinates(?string $sLat, ?string $sLong): ?array
    {
        if (empty($sLat) || empty($sLong)) {
            return null;
        }
        $lat = $this->toDecimal($sLat, true);
        $lon = $this->toDecimal($sLong, false);
        if ($lat === null || $lon === null || $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
            return null;
        }

        return [$lat, $lon];
    }

    /**
     * Grad-Anteil ist NICHT fest auf 2 (Breite) bzw. 3 Stellen (Laenge) gepolstert -
     * z.B. deutsche Laengengrade wie "E13073500" (13,126 Grad) sind nur 9 Zeichen lang,
     * nicht 10. Der Controller (Sunrise_SunsetController::convertToDecimal) geht von
     * fester Laenge aus (9 = Breite, 10 = Laenge) und parst solche Faelle teils falsch -
     * hier daher von hinten: letzte 2 Ziffern = Hundertstelsekunden, davor 2 = Sekunden,
     * davor 2 = Minuten, der (variabel lange) Rest vorne = Grad.
     */
    private function toDecimal(string $coordinate, bool $isLatitude): ?float
    {
        $coordinate = str_replace('/', '0', $coordinate);
        $direction = substr($coordinate, 0, 1);
        $digits = substr($coordinate, 1);

        if (strlen($digits) < 7 || !ctype_digit($digits)) {
            return null;
        }

        $hundredths = substr($digits, -2);
        $seconds = substr($digits, -4, 2);
        $minutes = substr($digits, -6, 2);
        $degrees = substr($digits, 0, -6);

        $decimal = (float) $degrees + ((float) $minutes / 60) + ((float) ($seconds . '.' . $hundredths) / 3600);
        if (in_array($direction, ['S', 'W'], true)) {
            $decimal *= -1;
        }

        return $decimal;
    }

    /** Bounding-Box pro Feature einmalig vorberechnen, damit jeder Lookup schnell ist. */
    private function precomputeBoundingBoxes(array $rawFeatures): array
    {
        $features = [];
        foreach ($rawFeatures as $f) {
            $geom = $f['geometry'];
            $polys = $geom['type'] === 'Polygon' ? [$geom['coordinates']] : $geom['coordinates'];
            $minLon = 180; $maxLon = -180; $minLat = 90; $maxLat = -90;
            foreach ($polys as $poly) {
                foreach ($poly[0] as $pt) {
                    if ($pt[0] < $minLon) $minLon = $pt[0];
                    if ($pt[0] > $maxLon) $maxLon = $pt[0];
                    if ($pt[1] < $minLat) $minLat = $pt[1];
                    if ($pt[1] > $maxLat) $maxLat = $pt[1];
                }
            }
            $features[] = ['bbox' => [$minLon, $minLat, $maxLon, $maxLat], 'geom' => $geom, 'tzid' => $f['properties']['tzid']];
        }

        return $features;
    }

    private function findTimezone(float $lon, float $lat, array $features): ?string
    {
        foreach ($features as $f) {
            [$minLon, $minLat, $maxLon, $maxLat] = $f['bbox'];
            if ($lon < $minLon || $lon > $maxLon || $lat < $minLat || $lat > $maxLat) continue;
            if ($this->pointInGeometry($lon, $lat, $f['geom'])) {
                return $f['tzid'];
            }
        }

        return null;
    }

    private function pointInGeometry(float $lon, float $lat, array $geom): bool
    {
        $polys = $geom['type'] === 'Polygon' ? [$geom['coordinates']] : $geom['coordinates'];
        foreach ($polys as $poly) {
            if (!$this->pointInRing($lon, $lat, $poly[0])) continue;
            $inHole = false;
            for ($h = 1; $h < count($poly); $h++) {
                if ($this->pointInRing($lon, $lat, $poly[$h])) { $inHole = true; break; }
            }
            if (!$inHole) return true;
        }

        return false;
    }

    private function pointInRing(float $lon, float $lat, array $ring): bool
    {
        $inside = false;
        $n = count($ring);
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            $xi = $ring[$i][0]; $yi = $ring[$i][1];
            $xj = $ring[$j][0]; $yj = $ring[$j][1];
            if ((($yi > $lat) != ($yj > $lat)) && ($lon < ($xj - $xi) * ($lat - $yi) / ($yj - $yi) + $xi)) {
                $inside = !$inside;
            }
        }

        return $inside;
    }
}
