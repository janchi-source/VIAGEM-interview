<?php

declare(strict_types=1);

namespace Viagem\Import;

final class VfrDownloader
{
    public function __construct(private readonly string $baseUrl, private readonly string $directory)
    {
    }

    public function latestDate(?\DateTimeImmutable $now = null): string
    {
        $date = ($now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('last day of this month');
        $candidate = $date->format('Ymd');
        if ($this->available($candidate)) return $candidate;
        return $date->modify('-1 month')->modify('last day of this month')->format('Ymd');
    }

    public function download(string $date, string $kind = 'ST_UKSG'): string
    {
        $filename = $date . '_' . $kind . '.xml.zip';
        $target = rtrim($this->directory, '/') . '/' . $filename;
        if (is_file($target)) return $target;
        if (!is_dir($this->directory) && !mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Nepodarilo sa vytvoriť adresár VFR.');
        }
        $temporary = $target . '.part';
        $this->transfer(rtrim($this->baseUrl, '/') . '/' . $filename, $temporary);
        if (!rename($temporary, $target)) throw new \RuntimeException('Nepodarilo sa dokončiť sťahovanie VFR.');
        return $target;
    }

    private function available(string $date): bool
    {
        $handle = curl_init(rtrim($this->baseUrl, '/') . '/' . $date . '_ST_UKSG.xml.zip');
        if ($handle === false) return false;
        curl_setopt_array($handle, [CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
        curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        return $status >= 200 && $status < 400;
    }

    private function transfer(string $url, string $target): void
    {
        $file = fopen($target, 'wb');
        if ($file === false) throw new \RuntimeException('Nepodarilo sa otvoriť dočasný súbor VFR.');
        $handle = curl_init($url);
        if ($handle === false) {
            fclose($file);
            throw new \RuntimeException('Nepodarilo sa inicializovať sieťové sťahovanie VFR.');
        }
        curl_setopt_array($handle, [CURLOPT_FILE => $file, CURLOPT_FOLLOWLOCATION => true, CURLOPT_FAILONERROR => true, CURLOPT_TIMEOUT => 300]);
        $ok = curl_exec($handle);
        $error = curl_error($handle);
        curl_close($handle);
        fclose($file);
        if ($ok === false) {
            @unlink($target);
            throw new \RuntimeException('Sieťová chyba pri sťahovaní VFR: ' . $error);
        }
    }
}
