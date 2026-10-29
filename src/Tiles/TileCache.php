<?php

declare(strict_types=1);

namespace Viagem\Tiles;

final class TileCache
{
    public function __construct(private readonly string $directory)
    {
    }

    public function get(int $z, int $x, int $y): ?string
    {
        $path = $this->path($z, $x, $y);
        return is_file($path) ? file_get_contents($path) : null;
    }

    public function put(int $z, int $x, int $y, string $data): void
    {
        $path = $this->path($z, $x, $y);
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0775, true) && !is_dir(dirname($path))) {
            throw new \RuntimeException('Nepodarilo sa vytvoriť adresár cache dlaždíc.');
        }
        $temporary = $path . '.part.' . bin2hex(random_bytes(6));
        if (file_put_contents($temporary, $data, LOCK_EX) === false || !rename($temporary, $path)) {
            @unlink($temporary);
            throw new \RuntimeException('Nepodarilo sa uložiť dlaždicu.');
        }
    }

    public function clear(): void
    {
        if (!is_dir($this->directory)) return;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isFile() && $item->getFilename() !== '.gitkeep') unlink($item->getPathname());
            elseif ($item->isDir()) rmdir($item->getPathname());
        }
    }

    private function path(int $z, int $x, int $y): string
    {
        if ($z < 0 || $x < 0 || $y < 0) throw new \InvalidArgumentException('Neplatné súradnice dlaždice.');
        return $this->directory . '/' . $z . '/' . $x . '/' . $y . '.pbf';
    }
}
