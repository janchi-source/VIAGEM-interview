<?php

declare(strict_types=1);

$output = $argv[1] ?? dirname(__DIR__) . '/data/fixture.xml';
$size = max(1, (int) ($argv[2] ?? 3));
$features = [];
$id = 1;
for ($y = 0; $y < $size; $y++) {
    for ($x = 0; $x < $size; $x++) {
        $left = 15.35 + $x * 0.0004;
        $bottom = 50.43 + $y * 0.0004;
        $right = $left + 0.00035;
        $top = $bottom + 0.00035;
        $hole = $id === 1 ? '<gml:interior><gml:LinearRing><gml:posList>' . ($left + .0001) . ' ' . ($bottom + .0001) . ' ' . ($right - .0001) . ' ' . ($bottom + .0001) . ' ' . ($right - .0001) . ' ' . ($top - .0001) . ' ' . ($left + .0001) . ' ' . ($top - .0001) . ' ' . ($left + .0001) . ' ' . ($bottom + .0001) . '</gml:posList></gml:LinearRing></gml:interior>' : '';
        $features[] = '<Parcela gml:id="P' . $id . '"><KmenoveCislo>' . (100 + $id) . '</KmenoveCislo><DruhCislovani>0</DruhCislovani><DruhPozemku>orná pôda</DruhPozemku><Vymera>1400</Vymera><BPEJ><Kod>12345</Kod><Vymera>700</Vymera></BPEJ><BPEJ><Kod>54321</Kod><Vymera>700</Vymera></BPEJ><gml:Polygon><gml:exterior><gml:LinearRing><gml:posList>' . "$left $bottom $right $bottom $right $top $left $top $left $bottom" . '</gml:posList></gml:LinearRing></gml:exterior>' . $hole . '</gml:Polygon></Parcela>';
        $id++;
    }
}
$features[] = '<StavebniObjekt gml:id="B1"><CisloDomovni>12</CisloDomovni><KodObce>573892</KodObce><AdresniMisto>AM1</AdresniMisto><gml:Point><gml:pos>15.351 50.431</gml:pos></gml:Point></StavebniObjekt>';
$features[] = '<Parcela gml:id="PA"><KmenoveCislo>999</KmenoveCislo><gml:Polygon><gml:exterior><gml:Ring><gml:curveMember><gml:Curve><gml:segments><gml:ArcString><gml:posList>15.36 50.44 15.3602 50.4402 15.3604 50.44</gml:posList></gml:ArcString></gml:segments></gml:Curve></gml:curveMember></gml:Ring></gml:exterior></gml:Polygon></Parcela>';
$xml = '<?xml version="1.0" encoding="UTF-8"?><VFR xmlns:gml="http://www.opengis.net/gml/3.2">' . implode('', $features) . '</VFR>';
if (file_put_contents($output, $xml) === false) {
    throw new RuntimeException('Nepodarilo sa zapísať fixture.');
}
echo $output . PHP_EOL;
