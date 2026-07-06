<?php
/**
 * Deploy-Diagnose (nach dem 8.1-Upgrade). Zeigt den echten Boot-Fehler, den die
 * leere prod-Seite versteckt. Aufruf:  https://.../deploy-check.php
 * Danach BITTE WIEDER LOESCHEN (gibt interne Pfade preis).
 */
header('Content-Type: text/plain; charset=utf-8');

require dirname(__DIR__) . '/vendor/autoload.php';

// .env laden (wie public/index.php), sonst fehlen MAILER_DSN & Co.
if (class_exists('Symfony\\Component\\Dotenv\\Dotenv')) {
    (new \Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__) . '/.env');
}

echo "PHP:     " . PHP_VERSION . "\n";
echo "Symfony: " . (class_exists('Symfony\\Component\\HttpKernel\\Kernel')
        ? Symfony\Component\HttpKernel\Kernel::VERSION : 'FEHLT (vendor unvollstaendig?)') . "\n\n";

echo "Schluesselklassen (FEHLT = vendor-Upload unvollstaendig):\n";
foreach ([
    'Doctrine\\DBAL\\Result',
    'Doctrine\\ORM\\EntityManager',
    'Doctrine\\Bundle\\DoctrineBundle\\DoctrineBundle',
    'Symfony\\Bundle\\MonologBundle\\MonologBundle',
] as $c) {
    echo "  $c: " . (class_exists($c) ? 'OK' : 'FEHLT') . "\n";
}

echo "\nprod-Kernel booten:\n";
try {
    $kernel = new \App\Kernel('prod', false);
    $kernel->boot();
    echo "  Kernel(prod) boot: OK\n";
    echo "  Cache-Dir: " . $kernel->getCacheDir() . "\n";
} catch (\Throwable $e) {
    echo "  >>> BOOT-FEHLER: " . get_class($e) . "\n";
    echo "      " . $e->getMessage() . "\n";
    echo "      in " . $e->getFile() . ':' . $e->getLine() . "\n";
    if ($e->getPrevious()) {
        echo "      (Ursache: " . $e->getPrevious()->getMessage() . ")\n";
    }
}
