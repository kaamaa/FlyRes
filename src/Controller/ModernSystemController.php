<?php

namespace App\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
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

        // Passwort-Status + Lizenz-Bereinigung nur fuer Global-Admins
        // (mandantenuebergreifend).
        $isGlobal = $this->isGranted('ROLE_GLOBAL_ADMIN');
        $pw  = $isGlobal ? $this->pwStatus($em) : null;
        $lic = $isGlobal ? $this->licStatus($em) : null;
        $lictype = $isGlobal ? $this->licTypeStatus($em) : null;

        return $this->render('modern/system.html.twig', [
            'php'      => $this->collectPhp(),
            'opcache'  => $this->collectOpcache(),
            'apcu'     => $this->collectApcu(),
            'doctrine' => $this->collectDoctrineCache($em),
            'db'       => $this->collectDatabase($em),
            'pw'       => $pw,
            'lic'      => $lic,
            'lictype'  => $lictype,
        ]);
    }

    /**
     * Passwort-Migration (Legacy-MD5 -> bcrypt) per Button, da kein CLI-Zugriff.
     * Nur Global-Admin (mandantenuebergreifend). POST -> durch den
     * CsrfOriginSubscriber gegen Cross-Origin geschuetzt. Logik bewusst inline
     * (keine eigene Klasse), damit kein zusaetzlicher Autoloader-Eintrag noetig ist.
     */
    public function passwordMigrate(Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_GLOBAL_ADMIN');

        $dryRun = $request->request->get('mode') !== 'run';
        $r = $this->pwMigrate($em, $dryRun);
        $r['dryRun'] = $dryRun;
        $this->addFlash('pwmigrate', $r);

        return $this->redirectToRoute('modern_system');
    }

    /**
     * Karteileichen-Bereinigung: Lizenzen, deren Pilot bereits geloescht ist
     * (Account status='geloescht'), ebenfalls soft-loeschen (status='geloescht').
     * Nur Global-Admin (mandantenuebergreifend). POST -> CsrfOriginSubscriber.
     * Nur ein Soft-Delete – es wird nichts physisch entfernt.
     */
    public function licencesCleanup(Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_GLOBAL_ADMIN');

        $dryRun = $request->request->get('mode') !== 'run';
        $r = $this->licCleanup($em, $dryRun);
        $r['dryRun'] = $dryRun;
        $this->addFlash('liccleanup', $r);

        return $this->redirectToRoute('modern_system');
    }

    /** Rohe phpinfo()-Ausgabe (eigenes Dokument fuer das iframe). */
    public function phpinfo(): Response
    {
        // phpinfo() legt $_SERVER/$_ENV offen (APP_SECRET, DATABASE_URL, MAILER_DSN) –
        // daher nur fuer GLOBAL_ADMIN, nicht fuer den pro-Mandant-System-Admin.
        $this->denyAccessUnlessGranted('ROLE_GLOBAL_ADMIN');

        ob_start();
        phpinfo();
        $html = (string) ob_get_clean();

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * Vollstaendiges SQL-Backup (Struktur + Daten ALLER Tabellen) als Download.
     * Erzeugt einen phpMyAdmin-aehnlichen Dump und streamt ihn, damit auch grosse
     * Tabellen (z. B. tools_airports) ohne Speicherprobleme laufen.
     */
    public function backup(EntityManagerInterface $em): Response
    {
        // Der Dump umfasst die GESAMTE (mandantenuebergreifende) DB inkl. aller
        // Passwort-Hashes/E-Mails. ROLE_SYSTEM_ADMIN ist pro-Mandant -> hier
        // bewusst ROLE_GLOBAL_ADMIN, um die Mandantengrenze nicht zu brechen.
        $this->denyAccessUnlessGranted('ROLE_GLOBAL_ADMIN');

        $conn     = $em->getConnection();
        $dbName   = (string) $conn->getDatabase();
        $filename = 'flyres-backup-' . date('Y-m-d_H-i-s') . '.sql';

        $response = new StreamedResponse(static function () use ($conn, $dbName) {
            $out = fopen('php://output', 'wb');
            $w = static function (string $s) use ($out) { fwrite($out, $s); };

            $w("-- FlyRes SQL-Backup\n");
            $w('-- Datenbank: `' . $dbName . "`\n");
            $w('-- Erstellt:  ' . date('Y-m-d H:i:s') . "\n");
            $w("-- Struktur und Daten aller Tabellen. Wiederherstellung: Datei importieren.\n\n");
            // NO_AUTO_VALUE_ON_ZERO + geleerter strict-Teil, damit Legacy-Defaults
            // ('0000-00-00') beim Import nicht abgelehnt werden (wie bei phpMyAdmin).
            $w("SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n");
            $w("SET time_zone = \"+00:00\";\n");
            $w("SET NAMES utf8mb4;\n");
            $w("SET FOREIGN_KEY_CHECKS = 0;\n");

            foreach ($conn->executeQuery('SHOW TABLES')->fetchFirstColumn() as $table) {
                $create = $conn->executeQuery('SHOW CREATE TABLE `' . $table . '`')->fetchAssociative();
                $ddl = $create['Create Table'] ?? null;
                if ($ddl === null) {
                    continue; // Views o. Ae. ueberspringen
                }

                $w("\n-- --------------------------------------------------------\n");
                $w('-- Tabelle `' . $table . "`\n\n");
                $w('DROP TABLE IF EXISTS `' . $table . "`;\n");
                $w($ddl . ";\n\n");

                // Daten gestreamt in Bloecken zu je 100 Zeilen (Multi-Row-INSERT).
                $cols  = null;
                $batch = [];
                foreach ($conn->executeQuery('SELECT * FROM `' . $table . '`')->iterateAssociative() as $row) {
                    if ($cols === null) {
                        $cols = array_keys($row);
                    }
                    $cells = [];
                    foreach ($cols as $c) {
                        $cells[] = $row[$c] === null ? 'NULL' : $conn->quote((string) $row[$c]);
                    }
                    $batch[] = '(' . implode(',', $cells) . ')';
                    if (count($batch) >= 100) {
                        $w('INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . "`) VALUES\n");
                        $w(implode(",\n", $batch) . ";\n");
                        $batch = [];
                    }
                }
                if ($batch) {
                    $w('INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . "`) VALUES\n");
                    $w(implode(",\n", $batch) . ";\n");
                }
            }

            $w("\nSET FOREIGN_KEY_CHECKS = 1;\n");
            fclose($out);
        });

        $response->headers->set('Content-Type', 'application/sql; charset=utf-8');
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $filename
        ));
        $response->headers->set('X-Accel-Buffering', 'no'); // nginx: nicht puffern
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
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

    // ------------------------------------------------ Passwort-Migration ----

    /**
     * Status: wie viele Konten schon bcrypt, wie viele noch Legacy-MD5 sind.
     *
     * @return array{total:int, bcrypt:int, md5:int}
     */
    private function pwStatus(EntityManagerInterface $em): array
    {
        $conn = $em->getConnection();
        $row = $conn->executeQuery(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN password LIKE '\$2%' THEN 1 ELSE 0 END) AS bcrypt,
                    SUM(CASE WHEN password REGEXP '^[a-f0-9]{32}$' THEN 1 ELSE 0 END) AS md5
             FROM fres_accounts"
        )->fetchAssociative();

        return [
            'total'  => (int) ($row['total'] ?? 0),
            'bcrypt' => (int) ($row['bcrypt'] ?? 0),
            'md5'    => (int) ($row['md5'] ?? 0),
        ];
    }

    /**
     * Migration rohes MD5 -> bcrypt(MD5), ohne Klartext, idempotent.
     *
     * bcrypt (Kosten 12) ist absichtlich langsam (~0,3 s/Hash). Bei vielen
     * Konten wuerde ein einziger Request in den nginx/PHP-Gateway-Timeout (504)
     * laufen. Daher pro Aufruf nur ein ZEITBUDGET abarbeiten; der Rest wird beim
     * naechsten Aufruf migriert (das Template setzt automatisch fort, bis 0).
     *
     * @return array{dryRun:bool, migrated:int, pending:int}
     */
    private function pwMigrate(EntityManagerInterface $em, bool $dryRun): array
    {
        $conn = $em->getConnection();

        // Anzahl noch offener (roher MD5) Konten – schnelle Zaehlung.
        $pending = (int) $conn->executeQuery(
            "SELECT COUNT(*) FROM fres_accounts WHERE password REGEXP '^[a-f0-9]{32}$'"
        )->fetchOne();

        if ($dryRun || $pending === 0) {
            return ['dryRun' => $dryRun, 'migrated' => 0, 'pending' => $pending];
        }

        // Nur noch nicht migrierte Konten laden und zeitbudgetiert abarbeiten.
        $rows = $conn->executeQuery(
            "SELECT id, password FROM fres_accounts WHERE password REGEXP '^[a-f0-9]{32}$'"
        )->fetchAllAssociative();

        $deadline = microtime(true) + 20.0;   // sicher unter dem Gateway-Timeout
        $migrated = 0;

        foreach ($rows as $row) {
            $new = password_hash(strtolower((string) $row['password']), PASSWORD_BCRYPT, ['cost' => 12]);
            $conn->executeStatement(
                'UPDATE fres_accounts SET password = :p WHERE id = :id',
                ['p' => $new, 'id' => $row['id']]
            );
            $migrated++;
            if (microtime(true) >= $deadline) {
                break;   // Rest beim naechsten (Auto-)Durchlauf
            }
        }

        return ['dryRun' => false, 'migrated' => $migrated, 'pending' => $pending - $migrated];
    }

    /** SQL fuer offene Karteileichen: aktive Lizenzen an geloeschten Piloten. */
    private const LIC_ORPHAN_WHERE =
        "FRes_userLicences l JOIN FRes_accounts a ON a.id = l.accountid "
        . "WHERE a.status = 'geloescht' AND (l.status IS NULL OR l.status = '0')";

    /**
     * @return array{pending:int, list:array<int,array{id:int,label:string}>}
     * Anzahl UND konkrete Liste der aktiven Lizenzen an geloeschten Piloten
     * (Pilot + Lizenztyp), damit vor der Bereinigung sichtbar ist, was betroffen ist.
     */
    private function licStatus(EntityManagerInterface $em): array
    {
        $conn = $em->getConnection();
        $pending = (int) $conn->executeQuery('SELECT COUNT(*) FROM ' . self::LIC_ORPHAN_WHERE)->fetchOne();

        // Identische Bedingung wie LIC_ORPHAN_WHERE, zusaetzlich der Lizenztyp-Name.
        $rows = $conn->executeQuery(
            "SELECT l.id, a.lastname, a.firstname, lt.categoryname, lt.longname "
            . "FROM FRes_userLicences l "
            . "JOIN FRes_accounts a ON a.id = l.accountid "
            . "LEFT JOIN FRes_licenceType lt ON lt.id = l.licenceid "
            . "WHERE a.status = 'geloescht' AND (l.status IS NULL OR l.status = '0') "
            . "ORDER BY a.lastname, a.firstname, lt.longname"
        )->fetchAllAssociative();

        $list = [];
        foreach ($rows as $r) {
            $name = trim(trim((string) $r['lastname']) . ', ' . trim((string) $r['firstname']), ', ');
            $typ  = trim(($r['categoryname'] ? '[' . $r['categoryname'] . '] ' : '') . ($r['longname'] ?? '(unbekannter Lizenztyp)'));
            $list[] = ['id' => (int) $r['id'], 'label' => ($name !== '' ? $name : '(ohne Namen)') . ' — ' . $typ];
        }

        return ['pending' => $pending, 'list' => $list];
    }

    /**
     * Soft-Delete der Karteileichen (status='geloescht'). Ein einzelnes UPDATE
     * (schnell, kein Zeitbudget noetig). Idempotent: bereits geloeschte bleiben
     * unberuehrt.
     *
     * @return array{deleted:int, pending:int}
     */
    private function licCleanup(EntityManagerInterface $em, bool $dryRun): array
    {
        $conn = $em->getConnection();
        $pending = (int) $conn->executeQuery('SELECT COUNT(*) FROM ' . self::LIC_ORPHAN_WHERE)->fetchOne();

        if ($dryRun || $pending === 0) {
            return ['deleted' => 0, 'pending' => $pending];
        }

        $deleted = (int) $conn->executeStatement(
            "UPDATE FRes_userLicences l JOIN FRes_accounts a ON a.id = l.accountid "
            . "SET l.status = 'geloescht' "
            . "WHERE a.status = 'geloescht' AND (l.status IS NULL OR l.status = '0')"
        );

        return ['deleted' => $deleted, 'pending' => $pending - $deleted];
    }

    /**
     * Lizenztypen fuer ein Dropdown, sortiert nach Nutzung aufsteigend
     * (am wenigsten genutzte zuerst). Jede Zeile traegt ausgeschrieben, wie oft
     * der Typ genutzt wird (aktive Inhaber, fruehere/geloeschte Lizenzen, von wie
     * vielen Flugzeugtypen verlangt) und einen Status in Klartext.
     *
     * @return array{types: array<int, array{id:int,label:string}>, total:int, unused:int}
     */
    private function licTypeStatus(EntityManagerInterface $em): array
    {
        // Sortierung: zuerst aktive Inhaber, dann Gesamtnutzung, dann Flugzeug-
        // Anforderungen – jeweils aufsteigend, damit die am wenigsten genutzten
        // Typen oben stehen.
        $sql = "SELECT lt.id, lt.categoryname, lt.longname, lt.status,
            (SELECT COUNT(*) FROM FRes_userLicences ul WHERE ul.licenceid = lt.id AND (ul.status IS NULL OR ul.status <> 'geloescht')) AS aktiv,
            (SELECT COUNT(*) FROM FRes_userLicences ul WHERE ul.licenceid = lt.id AND ul.status = 'geloescht') AS geloescht,
            (SELECT COUNT(*) FROM FRes_aircraftType2Licences a WHERE a.licenceid = lt.id) AS req
            FROM FRes_licenceType lt
            ORDER BY aktiv ASC, (aktiv + geloescht) ASC, req ASC, lt.longname ASC";

        $types  = [];
        $unused = 0;
        foreach ($em->getConnection()->fetchAllAssociative($sql) as $row) {
            $aktiv = (int) $row['aktiv'];
            $gel   = (int) $row['geloescht'];
            $req   = (int) $row['req'];

            $neverUsed = ($aktiv === 0 && $gel === 0 && $req === 0);
            if ($neverUsed) {
                $unused++;
            }
            $statusWord = $neverUsed ? 'nie genutzt' : ($aktiv === 0 ? 'keine aktiven Inhaber' : 'in Nutzung');

            $name = trim(($row['categoryname'] ? $row['categoryname'] . ': ' : '') . $row['longname']);
            if ($row['status'] === 'geloescht') {
                $name .= ' (deaktiviert)';
            }

            $label = $name
                . '  —  aktive Inhaber: ' . $aktiv
                . ', frühere/gelöschte: ' . $gel
                . ', von ' . $req . ' Flugzeugtyp' . ($req === 1 ? '' : 'en') . ' verlangt'
                . '  —  ' . $statusWord;

            $types[] = ['id' => (int) $row['id'], 'label' => $label];
        }

        return ['types' => $types, 'total' => count($types), 'unused' => $unused];
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
