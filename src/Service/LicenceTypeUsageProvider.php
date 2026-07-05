<?php

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Liefert die Nutzungs-Uebersicht aller Lizenztypen (Lizenztypen sind global,
 * nicht mandantengebunden): je Typ aktive Inhaber, fruehere/geloeschte Lizenzen
 * und von wie vielen Flugzeugtypen er verlangt wird, plus Status.
 *
 * Gemeinsam genutzt von der Diagnose und dem Lizenzen-Reiter "Lizenztypennutzung".
 */
class LicenceTypeUsageProvider
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * @return array{types: list<array{id:int,name:string,aktiv:int,geloescht:int,req:int,status:string,deaktiviert:bool,neverUsed:bool}>, total:int, unused:int}
     */
    public function usage(): array
    {
        // Meistgenutzte Typen oben (aktive Inhaber, dann Gesamtnutzung).
        $sql = "SELECT lt.id, lt.categoryname, lt.longname, lt.status,
            (SELECT COUNT(*) FROM FRes_userLicences ul WHERE ul.licenceid = lt.id AND (ul.status IS NULL OR ul.status <> 'geloescht')) AS aktiv,
            (SELECT COUNT(*) FROM FRes_userLicences ul WHERE ul.licenceid = lt.id AND ul.status = 'geloescht') AS geloescht,
            (SELECT COUNT(*) FROM FRes_aircraftType2Licences a WHERE a.licenceid = lt.id) AS req
            FROM FRes_licenceType lt
            ORDER BY aktiv DESC, (aktiv + geloescht) DESC, req DESC, lt.categoryname ASC, lt.longname ASC";

        $types  = [];
        $unused = 0;
        foreach ($this->em->getConnection()->fetchAllAssociative($sql) as $row) {
            $aktiv = (int) $row['aktiv'];
            $gel   = (int) $row['geloescht'];
            $req   = (int) $row['req'];

            $neverUsed = ($aktiv === 0 && $gel === 0 && $req === 0);
            if ($neverUsed) {
                $unused++;
            }

            $types[] = [
                'id'          => (int) $row['id'],
                'name'        => trim(($row['categoryname'] ? $row['categoryname'] . ': ' : '') . $row['longname']),
                'aktiv'       => $aktiv,
                'geloescht'   => $gel,
                'req'         => $req,
                'status'      => $neverUsed ? 'nie genutzt' : ($aktiv === 0 ? 'keine aktiven Inhaber' : 'in Nutzung'),
                'deaktiviert' => $row['status'] === 'geloescht',
                'neverUsed'   => $neverUsed,
            ];
        }

        return ['types' => $types, 'total' => count($types), 'unused' => $unused];
    }
}
