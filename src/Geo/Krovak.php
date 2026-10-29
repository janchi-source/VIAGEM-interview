<?php

declare(strict_types=1);

namespace Viagem\Geo;

final class Krovak
{
    /**
     * Prevedie S-JTSK (EPSG:5514) na WGS84 v poradí [zemepisná dĺžka, šírka].
     *
     * @return array{0: float, 1: float}
     */
    public static function toWgs84(float $e5514, float $n5514): array
    {
        // EPSG používa záporný East/North zápis; inverzia Křováka zároveň osi prehodí.
        $x = -$n5514;
        $y = -$e5514;

        $a = 6377397.155;
        $e = 0.081696831215303;
        $aWgs84 = 6378137.0;
        $eWgs84 = 0.081819191042816;
        $es = 0.006674372230614;
        $phi0 = deg2rad(49.5);
        $alpha = sqrt(1.0 + $es * cos($phi0) ** 4 / (1.0 - $es));
        $u0 = asin(sin($phi0) / $alpha);
        $g = ((1.0 + $e * sin($phi0)) / (1.0 - $e * sin($phi0))) ** ($alpha * $e / 2.0);
        $k = tan($u0 / 2.0 + M_PI / 4.0)
            / tan($phi0 / 2.0 + M_PI / 4.0) ** $alpha * $g;
        $s0 = 1.37008346281555;
        $n = sin($s0);
        $rho0 = 0.9999 * sqrt(1.0 - $es) / (1.0 - $es * sin($phi0) ** 2) / tan($s0);
        $ad = M_PI / 2.0 - 1.04216856380474;
        $lambda0 = deg2rad(24.8333333333333);

        $rho = hypot($x, $y) / $a;
        $epsilon = atan2($y, $x);
        $d = $epsilon / sin($s0);
        $s = $rho === 0.0
            ? M_PI / 2.0
            : 2.0 * (atan(pow($rho0 / $rho, 1.0 / $n) * tan($s0 / 2.0 + M_PI / 4.0)) - M_PI / 4.0);
        $u = asin(cos($ad) * sin($s) - sin($ad) * cos($s) * cos($d));
        $deltaV = asin(cos($s) * sin($d) / cos($u));

        // Najprv získame konformnú šírku a následne ju iteráciou vrátime na elipsoid.
        $phi = $u;
        for ($i = 0; $i < 10; $i++) {
            $next = 2.0 * (atan(
                $k ** (-1.0 / $alpha)
                * tan($u / 2.0 + M_PI / 4.0) ** (1.0 / $alpha)
                * ((1.0 + $e * sin($phi)) / (1.0 - $e * sin($phi))) ** ($e / 2.0)
            ) - M_PI / 4.0);
            if (abs($next - $phi) < 1e-12) {
                break;
            }
            $phi = $next;
        }

        // Helmertova transformácia prenesie kartézsky bod z Bessela do WGS84.
        $sinPhi = sin($phi);
        $normal = $a / sqrt(1.0 - $e ** 2 * $sinPhi ** 2);
        $xB = $normal * cos($phi) * cos($lambda0 - $deltaV / $alpha);
        $yB = $normal * cos($phi) * sin($lambda0 - $deltaV / $alpha);
        $zB = $normal * (1.0 - $e ** 2) * $sinPhi;
        $tx = 570.69;
        $ty = 85.69;
        $tz = 462.84;
        $scale = 1.0 + 3.543e-6;
        $rx = deg2rad(4.99821 / 3600.0);
        $ry = deg2rad(1.58676 / 3600.0);
        $rz = deg2rad(5.2611 / 3600.0);
        $xW = $tx + $scale * ($xB - $rz * $yB + $ry * $zB);
        $yW = $ty + $scale * ($rz * $xB + $yB - $rx * $zB);
        $zW = $tz + $scale * (-$ry * $xB + $rx * $yB + $zB);

        $longitude = atan2($yW, $xW);
        $latitude = atan2($zW, hypot($xW, $yW));
        for ($i = 0; $i < 10; $i++) {
            $normal = $aWgs84 / sqrt(1.0 - $eWgs84 ** 2 * sin($latitude) ** 2);
            $next = atan2(
                $zW + $eWgs84 ** 2 * $normal * sin($latitude),
                hypot($xW, $yW)
            );
            if (abs($next - $latitude) < 1e-12) {
                break;
            }
            $latitude = $next;
        }

        return [rad2deg($longitude), rad2deg($latitude)];
    }
}
