<?php

namespace App\Tools;

/**
 * Weight-&-Balance-Rechnung auf dem Server (Port von WbCalc der Garmin-App).
 * Bewusst server-seitig, damit die wertvollen Typdaten (Hebelarme, Envelope-
 * Polygone, Geometrie, Dichten) den Server nie verlassen: der Client schickt nur
 * Eingaben und erhaelt Ergebnis + ein fertig gerendertes Envelope-SVG (in
 * Pixel-Koordinaten, ohne die nativen Datenwerte).
 */
class WbCalc
{
    private const KGM_TO_LBIN1000 = 0.0867962;

    /**
     * Vollstaendige Rechnung fuer ein Muster. Gibt nur anzeige-taugliche Werte
     * zurueck (Massen/CG/Verdikt/Stationsmassen) plus das gerenderte SVG — keine
     * Arme, keine Momente, keine Envelope-Rohzahlen.
     *
     * @return array<string,mixed>
     */
    public static function calculate(array $type, float $emptyMass, float $emptyArm, float $tripFuel, array $load, string $units = 'metric'): array
    {
        $imp = ($units === 'imperial');
        $mU = $imp ? 'lb' : 'kg'; $aU = $imp ? 'in' : 'm'; $vU = $imp ? 'gal' : 'l'; $cU = $imp ? 'in' : 'mm';

        // Eingaben -> metrisch (kanonisch). Intern wird ausschliesslich metrisch gerechnet.
        $em = self::toM($emptyMass, 'mass', $imp);
        $ea = self::toM($emptyArm, 'arm', $imp);
        $trip = self::toM(max(0.0, $tripFuel), 'vol', $imp);
        $loadM = [];
        foreach ($type['stations'] as $s) {
            $kind = (isset($s['density']) || isset($s['oilDensity'])) ? 'vol' : 'mass';
            $v = isset($load[$s['id']]) ? (float) $load[$s['id']] : 0.0;
            $loadM[$s['id']] = self::toM($v, $kind, $imp);
        }

        $warnings = [];
        $stationsOut = [];
        $totalFuel = 0.0;
        foreach ($type['stations'] as $s) {
            $id = $s['id'];
            $isFuel = isset($s['density']) || isset($s['oilDensity']);
            $kind = $isFuel ? 'vol' : 'mass';
            $vm = $loadM[$id];                                  // Eingabe in metrisch (kg bzw. l)
            if (isset($s['density']))    { $totalFuel += $vm; $mkg = $vm * $s['density']; }
            elseif (isset($s['oilDensity'])) { $mkg = $vm * $s['oilDensity']; }
            else { $mkg = $vm; }

            $capM = $isFuel ? ($s['maxLiters'] ?? null) : ($s['maxKg'] ?? null);
            $over = ($capM !== null && $vm > $capM + 1e-9);
            if ($over) {
                $warnings[] = $s['label'] . ' über Limit (' . self::num(self::fromM($capM, $kind, $imp)) . ' ' . ($isFuel ? $vU : $mU) . ')';
            }
            $stationsOut[] = [
                'id'    => $id,
                'label' => $s['label'],
                'kind'  => $kind,
                'unit'  => $isFuel ? $vU : $mU,
                'max'   => $capM !== null ? round(self::fromM($capM, $kind, $imp), 1) : null,
                'mass'  => round(self::fromM($mkg, 'mass', $imp), 1),
                'over'  => $over,
            ];
        }
        if ($trip > $totalFuel + 1e-9) { $warnings[] = 'Trip-Fuel > Start-Kraftstoff'; }

        $to  = self::computePoint($type, $em, $ea, $loadM, 0.0);
        $ldg = self::computePoint($type, $em, $ea, $loadM, $trip);

        $mtom = (float) $type['limits']['mtom'];
        $mlm  = isset($type['limits']['mlm']) ? (float) $type['limits']['mlm'] : $mtom;
        if ($to['mass']  > $mtom) { $warnings[] = 'Startmasse > MTOM (' . self::num(self::fromM($mtom, 'mass', $imp)) . ' ' . $mU . ')'; }
        if ($ldg['mass'] > $mlm)  { $warnings[] = 'Landemasse > MLM (' . self::num(self::fromM($mlm, 'mass', $imp)) . ' ' . $mU . ')'; }

        $toX = self::envX($type, $to);   $toY = self::envY($type, $to);
        $lX  = self::envX($type, $ldg);  $lY  = self::envY($type, $ldg);
        $toIn = self::inside($type, $toX, $toY);
        $lIn  = self::inside($type, $lX, $lY);
        if (!$toIn) { $warnings[] = 'Start außerhalb des Envelopes'; }
        if (!$lIn)  { $warnings[] = 'Landung außerhalb des Envelopes'; }

        return [
            'ok'        => count($warnings) === 0,
            'warnings'  => $warnings,
            'units'     => ['mass' => $mU, 'arm' => $aU, 'vol' => $vU, 'cg' => $cU],
            'mtom'      => round(self::fromM($mtom, 'mass', $imp), 1),
            'mlm'       => round(self::fromM($mlm, 'mass', $imp), 1),
            'to'        => ['mass' => round(self::fromM($to['mass'], 'mass', $imp), 1),  'cg' => round(self::cgDisp($to['cg'], $imp), $imp ? 1 : 0)],
            'ldg'       => ['mass' => round(self::fromM($ldg['mass'], 'mass', $imp), 1), 'cg' => round(self::cgDisp($ldg['cg'], $imp), $imp ? 1 : 0)],
            'stations'  => $stationsOut,
            'svg'       => self::renderSvg($type, $toX, $toY, $toIn, $lX, $lY, $lIn, $imp),
        ];
    }

    /**
     * Sinnvolle Start-Beladung je Station (nach Stationstyp), in der gewaehlten
     * Anzeige-Einheit. Sitze = 80 kg (ein Standard-Insasse), Gepaeck = bis 10 kg,
     * Kraftstoff ~60% der Kapazitaet (auf 5 gerundet), Oel voll. Plus ein
     * Trip-Fuel-Vorschlag (~40% des Start-Kraftstoffs).
     *
     * @return array{load: array<string,float>, tripFuel: float}
     */
    public static function defaultLoad(array $type, bool $imp): array
    {
        $load = [];
        $totalFuelL = 0.0;
        foreach ($type['stations'] as $s) {
            if (isset($s['oilDensity'])) {                       // Oel: voll (fester Posten)
                $vm = $s['maxLiters'] ?? 6.0; $kind = 'vol';
            } elseif (isset($s['density'])) {                    // Kraftstoff: ~60% der Kapazitaet
                $max = $s['maxLiters'] ?? null; $kind = 'vol';
                $vm = $max !== null ? min($max, round($max * 0.6 / 5) * 5) : 40.0;
                $totalFuelL += $vm;
            } else {                                             // Zuladung nach type
                $kind = 'mass'; $t = $s['type'] ?? '';
                if ($t === 'seat')          { $vm = 80.0; }
                elseif ($t === 'baggage')   { $vm = min(10.0, $s['maxKg'] ?? 10.0); }
                else                        { $vm = 0.0; }
            }
            $load[$s['id']] = round(self::fromM($vm, $kind, $imp), 1);
        }
        $tripL = round($totalFuelL * 0.4 / 5) * 5;
        return ['load' => $load, 'tripFuel' => round(self::fromM($tripL, 'vol', $imp), 1)];
    }

    /** Anzeige-Einheit -> metrisch (kg/m/l). */
    private static function toM(float $v, string $kind, bool $imp): float
    {
        if (!$imp) { return $v; }
        if ($kind === 'mass') { return $v * 0.45359237; }
        if ($kind === 'arm')  { return $v * 0.0254; }
        if ($kind === 'vol')  { return $v * 3.785411784; }
        return $v;
    }

    /** metrisch -> Anzeige-Einheit. */
    private static function fromM(float $m, string $kind, bool $imp): float
    {
        if (!$imp) { return $m; }
        if ($kind === 'mass') { return $m * 2.20462262; }
        if ($kind === 'arm')  { return $m * 39.3700787; }
        if ($kind === 'vol')  { return $m * 0.264172; }
        return $m;
    }

    /** Schwerpunkt (Meter) -> Anzeige: mm (metrisch) bzw. in (imperial). */
    private static function cgDisp(float $cgM, bool $imp): float
    {
        return $imp ? $cgM * 39.3700787 : $cgM * 1000.0;
    }

    /** @return array{mass:float,moment:float,cg:float} */
    private static function computePoint(array $t, float $em, float $ea, array $load, float $burnL): array
    {
        $mass = $em; $moment = $em * $ea; $remain = $burnL;
        foreach ($t['stations'] as $s) {
            $v = isset($load[$s['id']]) ? (float) $load[$s['id']] : 0.0;
            if (isset($s['density'])) {
                $b = min($remain, $v); $remain -= $b; $m = ($v - $b) * $s['density'];
            } elseif (isset($s['oilDensity'])) {
                $m = $v * $s['oilDensity'];
            } else {
                $m = $v;
            }
            $mass += $m; $moment += $m * $s['arm'];
        }
        return ['mass' => $mass, 'moment' => $moment, 'cg' => $mass != 0.0 ? $moment / $mass : 0.0];
    }

    private static function cgToPctMac(array $t, float $cgM): float
    {
        $g = $t['geometry'] ?? null;
        if ($g === null || empty($g['macLengthM']) || !isset($g['macLeadingEdgeM'])) { return 0.0; }
        return ($cgM - $g['macLeadingEdgeM']) / $g['macLengthM'] * 100.0;
    }

    private static function envX(array $t, array $pt): float
    {
        $ax = $t['envelope']['xAxis'] ?? null;
        if ($ax === null) { return $pt['moment']; }
        $kind = $ax['kind']; $unit = $ax['unit'];
        if ($kind === 'moment') { return $unit === 'lbIn1000' ? $pt['moment'] * self::KGM_TO_LBIN1000 : $pt['moment']; }
        if ($kind === 'cg') {
            if ($unit === 'mm') { return $pt['cg'] * 1000.0; }
            if ($unit === 'in') { return $pt['cg'] * 39.3700787; }
            if ($unit === 'percentMAC') { return self::cgToPctMac($t, $pt['cg']); }
            return $pt['cg'];
        }
        if ($kind === 'cgPercentMac') { return self::cgToPctMac($t, $pt['cg']); }
        return $pt['moment'];
    }

    private static function envY(array $t, array $pt): float
    {
        $ax = $t['envelope']['yAxis'] ?? null;
        if ($ax !== null && $ax['kind'] === 'mass' && $ax['unit'] === 'lb') { return $pt['mass'] * 2.20462262; }
        return $pt['mass'];
    }

    /** @return array<int,array{points:array}> */
    private static function polys(array $t): array
    {
        $e = $t['envelope'] ?? null;
        if ($e === null) { return []; }
        $ok = static fn ($p) => isset($p['points']) && count($p['points']) >= 3;
        if (isset($e['categories'])) {
            $cs = array_values(array_filter($e['categories'], static function ($c) use ($ok) {
                return ($c['check'] ?? true) !== false && ($c['enabled'] ?? true) !== false
                    && isset($c['polygons']) && count(array_filter($c['polygons'], $ok)) > 0;
            }));
            if (!$cs) { return []; }
            $cat = $cs[0];
            if (isset($e['defaultCategory'])) {
                foreach ($cs as $c) { if (($c['id'] ?? null) === $e['defaultCategory']) { $cat = $c; break; } }
            }
            return array_values(array_filter($cat['polygons'], $ok));
        }
        if (isset($e['polygons'])) { return array_values(array_filter($e['polygons'], $ok)); }
        if (isset($e['points'])) { return [['points' => $e['points']]]; }
        return [];
    }

    private static function inPoly(float $x, float $y, array $pts): bool
    {
        $in = false; $j = count($pts) - 1;
        for ($i = 0; $i < count($pts); $i++) {
            [$xi, $yi] = $pts[$i]; [$xj, $yj] = $pts[$j];
            if ((($yi > $y) != ($yj > $y)) && ($x < ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi)) { $in = !$in; }
            $j = $i;
        }
        return $in;
    }

    private static function inside(array $t, float $x, float $y): bool
    {
        foreach (self::polys($t) as $p) { if (self::inPoly($x, $y, $p['points'])) { return true; } }
        return false;
    }

    // -------------------------------------------------------------------------
    //  Envelope-Diagramm serverseitig als SVG. Polygone/Punkte werden in PIXEL
    //  umgerechnet — die nativen Datenwerte (Envelope-Koordinaten) erscheinen
    //  NICHT im ausgelieferten Markup. Nur die Achsen-Ticks zeigen den Bereich.
    // -------------------------------------------------------------------------

    private static function renderSvg(array $t, float $toX, float $toY, bool $toIn, float $lX, float $lY, bool $lIn, bool $imp): string
    {
        $e = $t['envelope']; $v = $e['view'];
        // Achsen in die gewaehlte Einheit umrechnen. Nur Tick-Zahlen + Titel aendern sich;
        // die Form bleibt identisch, weil Ansichtsfenster UND Punkte gleich skalieren
        // (dieselben Pixelpositionen) -> wir skalieren daher nur die angezeigten Werte.
        [$xFactor, $xUnit, $xPrefix] = self::axisScale($e['xAxis'] ?? null, $imp, 'Schwerpunkt');
        [$yFactor, $yUnit, $yPrefix] = self::axisScale($e['yAxis'] ?? null, $imp, 'Masse');
        $w = 430; $h = 300; $ml = 52; $mr = 14; $mt = 12; $mb = 34;
        $pw = $w - $ml - $mr; $ph = $h - $mt - $mb;
        $X = static fn ($x) => $ml + ($x - $v['xMin']) / ($v['xMax'] - $v['xMin']) * $pw;
        $Y = static fn ($y) => $mt + (1 - ($y - $v['yMin']) / ($v['yMax'] - $v['yMin'])) * $ph;

        // user-select:none -> Achsen-/Punktbeschriftungen lassen sich nicht markieren/kopieren.
        $s = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" width="100%" height="' . $h . '"'
            . ' style="user-select:none;-webkit-user-select:none;-moz-user-select:none">';
        $s .= '<rect x="' . $ml . '" y="' . $mt . '" width="' . $pw . '" height="' . $ph . '" fill="#fbfcfe" stroke="#e3e8ee"/>';
        // Ticks
        for ($i = 0; $i <= 5; $i++) {
            $xv = $v['xMin'] + ($v['xMax'] - $v['xMin']) * $i / 5; $px = $X($xv);
            $s .= '<line x1="' . self::f($px) . '" y1="' . $mt . '" x2="' . self::f($px) . '" y2="' . ($mt + $ph) . '" stroke="#eef2f6"/>';
            $s .= '<text x="' . self::f($px) . '" y="' . ($mt + $ph + 16) . '" font-size="9.5" fill="#7a8698" text-anchor="middle">' . self::tick($xv * $xFactor) . '</text>';
            $yv = $v['yMin'] + ($v['yMax'] - $v['yMin']) * $i / 5; $py = $Y($yv);
            $s .= '<line x1="' . $ml . '" y1="' . self::f($py) . '" x2="' . ($ml + $pw) . '" y2="' . self::f($py) . '" stroke="#eef2f6"/>';
            $s .= '<text x="' . ($ml - 6) . '" y="' . self::f($py + 3) . '" font-size="9.5" fill="#7a8698" text-anchor="end">' . self::tick($yv * $yFactor) . '</text>';
        }
        // Polygone in Pixeln
        foreach (self::polys($t) as $p) {
            $pts = [];
            foreach ($p['points'] as $q) { $pts[] = self::f($X($q[0])) . ',' . self::f($Y($q[1])); }
            $s .= '<polygon points="' . implode(' ', $pts) . '" fill="rgba(46,158,91,.13)" stroke="#2e9e5b" stroke-width="1.6"/>';
        }
        // Punkte
        $s .= '<line x1="' . self::f($X($lX)) . '" y1="' . self::f($Y($lY)) . '" x2="' . self::f($X($toX)) . '" y2="' . self::f($Y($toY)) . '" stroke="#9db4c9" stroke-dasharray="3 3"/>';
        $s .= self::dot($X($lX), $Y($lY), 4.5, $lIn ? '#3b6ea5' : '#c9453b') . '<text x="' . self::f($X($lX) + 8) . '" y="' . self::f($Y($lY) + 12) . '" font-size="10" fill="#3b6ea5">Ldg</text>';
        $s .= self::dot($X($toX), $Y($toY), 5.5, $toIn ? '#2e9e5b' : '#c9453b', '#fff') . '<text x="' . self::f($X($toX) + 8) . '" y="' . self::f($Y($toY) + 3) . '" font-size="10" fill="' . ($toIn ? '#2e9e5b' : '#c9453b') . '" font-weight="700">Start</text>';
        // Achsentitel
        $s .= '<text x="' . self::f($ml + $pw / 2) . '" y="' . ($h - 3) . '" font-size="10" fill="#7a8698" text-anchor="middle">' . $xPrefix . ' (' . $xUnit . ')</text>';
        $s .= '<text x="12" y="' . self::f($mt + $ph / 2) . '" font-size="10" fill="#7a8698" text-anchor="middle" transform="rotate(-90 12 ' . self::f($mt + $ph / 2) . ')">' . $yPrefix . ' (' . $yUnit . ')</text>';
        return $s . '</svg>';
    }

    private static function dot(float $x, float $y, float $r, string $fill, ?string $stroke = null): string
    {
        return '<circle cx="' . self::f($x) . '" cy="' . self::f($y) . '" r="' . $r . '" fill="' . $fill . '"'
            . ($stroke ? ' stroke="' . $stroke . '" stroke-width="1.5"' : '') . '/>';
    }

    /**
     * Achsen-Skalierung nativ -> gewaehlte Anzeige-Einheit.
     * @return array{0:float,1:string,2:string} [Faktor, Einheiten-Label, Kind-Prefix]
     */
    private static function axisScale(?array $ax, bool $imp, string $fbPrefix): array
    {
        if ($ax === null) { return [1.0, $imp ? 'lb' : 'kg', $fbPrefix]; }
        $kind = $ax['kind'] ?? null; $unit = $ax['unit'] ?? null;
        $prefix = ['moment' => 'Moment', 'cg' => 'Schwerpunkt', 'cgPercentMac' => 'Schwerpunkt', 'mass' => 'Masse'][$kind] ?? $fbPrefix;

        if ($kind === 'mass') {
            $n2c = ($unit === 'lb') ? 0.45359237 : 1.0;                 // nativ -> kg
            return [$n2c * ($imp ? 2.20462262 : 1.0), $imp ? 'lb' : 'kg', $prefix];
        }
        if ($kind === 'cg') {
            if ($unit === 'percentMAC') { return [1.0, '%MAC', $prefix]; }
            $n2c = ['m' => 1.0, 'mm' => 0.001, 'in' => 0.0254][$unit] ?? 1.0;  // nativ -> m
            return [$n2c * ($imp ? 39.3700787 : 1000.0), $imp ? 'in' : 'mm', $prefix];
        }
        if ($kind === 'cgPercentMac') { return [1.0, '%MAC', $prefix]; }
        if ($kind === 'moment') {
            $n2c = ($unit === 'lbIn1000') ? (1.0 / self::KGM_TO_LBIN1000) : 1.0;   // nativ -> kg·m
            return [$n2c * ($imp ? self::KGM_TO_LBIN1000 : 1.0), $imp ? 'lb·in/1000' : 'kg·m', $prefix];
        }
        return [1.0, (string) ($unit ?? ''), $prefix];
    }

    private static function tick(float $v): string
    {
        if (abs($v) >= 100) { return (string) round($v); }
        if (abs($v) >= 10)  { return (string) (round($v * 10) / 10); }
        return (string) (round($v * 100) / 100);
    }

    private static function f(float $x): string { return (string) round($x, 1); }
    private static function num(float $x): string { return rtrim(rtrim(number_format($x, 1, '.', ''), '0'), '.'); }
}
