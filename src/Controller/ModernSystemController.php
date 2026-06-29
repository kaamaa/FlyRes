<?php

namespace App\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Kernel;

/**
 * System-Diagnose fuer System-Administratoren (nur ROLE_SYSTEM_ADMIN).
 *
 * Bewusst eigener Controller (kein Mischen in ModernPreviewController), nutzt
 * aber dasselbe modern/layout.html.twig. Zeigt Laufzeit-Fakten zu PHP, OPcache,
 * APCu, Doctrine-Cache und Datenbank – also genau das, was zur Beurteilung von
 * "ist das Caching optimal?" gebraucht wird. phpinfo() laeuft ueber eine eigene
 * Route in einem iframe (eigenes <html>, darf das Layout nicht stoeren).
 */
class ModernSystemController extends AbstractController
{
    /** Diagnose-Uebersicht. */
    public function system(EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SYSTEM_ADMIN');

        return $this->render('modern/system.html.twig', [
            'php'      => $this->collectPhp(),
            'opcache'  => $this->collectOpcache(),
            'apcu'     => $this->collectApcu(),
            'doctrine' => $this->collectDoctrineCache($em),
            'db'       => $this->collectDatabase($em),
        ]);
    }

    /** Rohe phpinfo()-Ausgabe (eigenes Dokument fuer das iframe). */
    public function phpinfo(): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SYSTEM_ADMIN');

        ob_start();
        phpinfo();
        $html = (string) ob_get_clean();

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    // ---------------------------------------------------------------- PHP ----

    private function collectPhp(): array
    {
        $ext = get_loaded_extensions();
        sort($ext, SORT_FLAG_CASE | SORT_STRING);
        $wanted = ['apcu', 'Zend OPcache', 'intl', 'pdo_mysql', 'mysqli', 'mbstring', 'gd', 'curl', 'openssl', 'zip', 'xml', 'json'];

        return [
            'version'        => PHP_VERSION,
            'sapi'           => PHP_SAPI,
            'os'             => PHP_OS . ' ' . php_uname('r'),
            'symfony'        => Kernel::VERSION,
            'env'            => $this->getParameter('kernel.environment'),
            'debug'          => $this->getParameter('kernel.debug') ? 'an' : 'aus',
            'timezone'       => date_default_timezone_get(),
            'servertime'     => date('Y-m-d H:i:s'),
            'memory_limit'   => ini_get('memory_limit'),
            'max_exec'       => ini_get('max_execution_time') . ' s',
            'upload_max'     => ini_get('upload_max_filesize'),
            'post_max'       => ini_get('post_max_size'),
            'display_errors' => ini_get('display_errors') ? 'an' : 'aus',
            'ext_present'    => array_values(array_filter($wanted, static fn ($e) => extension_loaded($e))),
            'ext_missing'    => array_values(array_filter($wanted, static fn ($e) => !extension_loaded($e))),
            'ext_all'        => $ext,
        ];
    }

    // ------------------------------------------------------------ OPcache ----

    private function collectOpcache(): array
    {
        // Erweiterung heisst "Zend OPcache" (nicht "opcache"). Aktiv-Status ueber
        // ini lesen – unabhaengig davon, ob der Host die Status-API gesperrt hat.
        $loaded  = extension_loaded('Zend OPcache') || extension_loaded('opcache');
        $key     = PHP_SAPI === 'cli' ? 'opcache.enable_cli' : 'opcache.enable';
        $enabled = $loaded && filter_var(ini_get($key), FILTER_VALIDATE_BOOLEAN);
        $api     = function_exists('opcache_get_status');

        if (!$loaded) {
            return ['loaded' => false, 'enabled' => false, 'api' => $api];
        }
        if (!$api) {
            // Status-API per disable_functions gesperrt (z.B. Shared Hosting) –
            // OPcache laeuft, nur die Detailwerte sind nicht auslesbar.
            return ['loaded' => true, 'enabled' => $enabled, 'api' => false];
        }

        $status = @opcache_get_status(false);
        $config = function_exists('opcache_get_configuration') ? @opcache_get_configuration() : null;
        if (!is_array($status)) {
            return ['loaded' => true, 'enabled' => $enabled, 'api' => true, 'nostatus' => true];
        }

        $mem  = $status['memory_usage'] ?? [];
        $stat = $status['opcache_statistics'] ?? [];
        $hits   = (int) ($stat['hits'] ?? 0);
        $misses = (int) ($stat['misses'] ?? 0);
        $total  = $hits + $misses;

        return [
            'loaded'     => true,
            'api'        => true,
            'enabled'    => (bool) ($status['opcache_enabled'] ?? $enabled),
            'used'       => $this->bytes($mem['used_memory'] ?? null),
            'free'       => $this->bytes($mem['free_memory'] ?? null),
            'wasted'     => $this->bytes($mem['wasted_memory'] ?? null),
            'hitrate'    => $total > 0 ? round($hits / $total * 100, 1) . ' %' : '–',
            'scripts'    => (int) ($stat['num_cached_scripts'] ?? 0),
            'maxscripts' => $config['directives']['opcache.max_accelerated_files'] ?? null,
            'jit'        => $status['jit']['enabled'] ?? null,
        ];
    }

    // --------------------------------------------------------------- APCu ----

    private function collectApcu(): array
    {
        $loaded  = extension_loaded('apcu');
        $enabled = $loaded && function_exists('apcu_enabled') && apcu_enabled();
        $out = ['loaded' => $loaded, 'enabled' => $enabled];

        if ($enabled && function_exists('apcu_sma_info')) {
            $sma = @apcu_sma_info(true);
            if (is_array($sma)) {
                $segSize = (int) ($sma['seg_size'] ?? 0);
                $segs    = (int) ($sma['num_seg'] ?? 1);
                $avail   = (int) ($sma['avail_mem'] ?? 0);
                $totalMem = $segSize * max(1, $segs);
                $out['total'] = $this->bytes($totalMem);
                $out['free']  = $this->bytes($avail);
                $out['used']  = $this->bytes($totalMem - $avail);
            }
        }
        if ($enabled && function_exists('apcu_cache_info')) {
            $info = @apcu_cache_info(true);
            if (is_array($info)) {
                $out['entries'] = (int) ($info['num_entries'] ?? 0);
                $out['hits']    = (int) ($info['num_hits'] ?? 0);
                $out['misses']  = (int) ($info['num_misses'] ?? 0);
            }
        }

        return $out;
    }

    // ----------------------------------------------------- Doctrine-Cache ----

    private function collectDoctrineCache(EntityManagerInterface $em): array
    {
        $cfg = $em->getConfiguration();

        $slcEnabled = false;
        $slcRegion  = null;
        try {
            $slc = $cfg->getSecondLevelCacheConfiguration();
            $slcEnabled = $slc !== null;
            if ($slc !== null) {
                $factory = $slc->getCacheFactory();
                // Region-Cache aus der Factory ziehen (Property-Name variiert je
                // Doctrine-Version) – zeigt, ob persistent oder ArrayAdapter.
                $ref = new \ReflectionObject($factory);
                foreach ($ref->getProperties() as $prop) {
                    $prop->setAccessible(true);
                    $val = $prop->getValue($factory);
                    if ($val instanceof \Psr\Cache\CacheItemPoolInterface) {
                        $slcRegion = $this->short(get_class($val));
                        break;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Diagnose darf nie crashen
        }

        return [
            'metadata'    => $this->cacheClass($cfg, 'getMetadataCache'),
            'query'       => $this->cacheClass($cfg, 'getQueryCache'),
            'result'      => $this->cacheClass($cfg, 'getResultCache'),
            'slc_enabled' => $slcEnabled,
            'slc_region'  => $slcRegion,
            'slc_persistent' => $slcRegion !== null && stripos($slcRegion, 'Array') === false,
        ];
    }

    private function cacheClass(object $cfg, string $method): ?string
    {
        try {
            $cache = $cfg->{$method}();
            return $cache ? $this->short(get_class($cache)) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    // ----------------------------------------------------------- Datenbank ---

    private function collectDatabase(EntityManagerInterface $em): array
    {
        $conn = $em->getConnection();
        $out  = ['driver' => null, 'name' => null, 'version' => null, 'tables' => [], 'totalSize' => null, 'bookingIndexes' => [], 'error' => null];

        try {
            $params = $conn->getParams();
            $out['driver']  = $params['driver'] ?? get_class($conn->getDriver());
            $out['host']    = $params['host'] ?? null;
            $out['charset'] = $params['charset'] ?? null;
            $out['name']    = $conn->getDatabase();
            $out['version'] = (string) $conn->executeQuery('SELECT VERSION()')->fetchOne();

            $rows = $conn->executeQuery(
                'SELECT table_name AS n, engine AS e, table_rows AS r,
                        data_length AS d, index_length AS i, table_collation AS c
                 FROM information_schema.tables
                 WHERE table_schema = :db
                 ORDER BY (data_length + index_length) DESC',
                ['db' => $out['name']]
            )->fetchAllAssociative();

            $total = 0;
            foreach ($rows as $r) {
                $size = (int) ($r['d'] ?? 0) + (int) ($r['i'] ?? 0);
                $total += $size;
                $out['tables'][] = [
                    'name'      => $r['n'],
                    'engine'    => $r['e'],
                    'rows'      => (int) ($r['r'] ?? 0),
                    'data'      => $this->bytes((int) ($r['d'] ?? 0)),
                    'index'     => $this->bytes((int) ($r['i'] ?? 0)),
                    'size'      => $this->bytes($size),
                    'collation' => $r['c'],
                ];
            }
            $out['totalSize'] = $this->bytes($total);
            $out['tableCount'] = count($rows);

            // Indizes der heissen Buchungstabelle (fuer die Uebersichts-Performance relevant)
            $bookingTable = $em->getClassMetadata(\App\Entity\FresBooking::class)->getTableName();
            $out['bookingTable'] = $bookingTable;
            $idx = $conn->executeQuery('SHOW INDEX FROM ' . $bookingTable)->fetchAllAssociative();
            $grouped = [];
            foreach ($idx as $row) {
                $key = $row['Key_name'];
                $grouped[$key]['name']    = $key;
                $grouped[$key]['unique']  = ((int) $row['Non_unique']) === 0;
                $grouped[$key]['columns'][(int) $row['Seq_in_index']] = $row['Column_name'];
            }
            foreach ($grouped as &$g) {
                ksort($g['columns']);
                $g['columns'] = implode(', ', $g['columns']);
            }
            $out['bookingIndexes'] = array_values($grouped);
        } catch (\Throwable $e) {
            $out['error'] = $e->getMessage();
        }

        return $out;
    }

    // -------------------------------------------------------------- Utils ----

    private function bytes($n): string
    {
        if ($n === null) {
            return '–';
        }
        $n = (float) $n;
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($n >= 1024 && $i < count($units) - 1) {
            $n /= 1024;
            $i++;
        }
        return ($i === 0 ? (int) $n : number_format($n, 1, ',', '.')) . ' ' . $units[$i];
    }

    /** Klassenname ohne Namespace. */
    private function short(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');
        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }
}
