<?php

declare(strict_types=1);

namespace Viagem\Http;

use PDO;
use Throwable;
use Viagem\Domain\ParcelRepository;
use Viagem\Tiles\TileBuilder;
use Viagem\Tiles\TileCache;

final class App
{
    private ParcelRepository $parcels;
    private TileBuilder $tiles;

    public function __construct(PDO $pdo, private readonly TileCache $cache)
    {
        $this->parcels = new ParcelRepository($pdo);
        $this->tiles = new TileBuilder($pdo);
    }

    /** @param array<string, mixed> $query */
    public function handle(string $method, string $path, array $query = []): Response
    {
        if ($method !== 'GET') return Response::error('Metóda nie je podporovaná.', 405);
        try {
            if (preg_match('#^/tiles/(\d+)/(\d+)/(\d+)\.pbf$#', $path, $matches) === 1) {
                [$z, $x, $y] = array_map('intval', array_slice($matches, 1));
                $this->validateTile($z, $x, $y);
                $tile = $this->cache->get($z, $x, $y);
                if ($tile === null) {
                    $tile = $this->tiles->build($z, $x, $y);
                    $this->cache->put($z, $x, $y, $tile);
                }
                return Response::tile($tile);
            }
            if ($path === '/api/parcels/search' || $path === '/api/search') {
                $text = $query['text'] ?? $query['q'] ?? null;
                if (!is_string($text) || trim($text) === '') return Response::error('Chýba číslo parcely.', 400);
                $ku = $this->optionalInt($query['ku'] ?? null, 'katastrálne územie');
                return Response::json(['items' => $this->parcels->search($ku, $text)]);
            }
            if (preg_match('#^/api/parcels?/([^/]+)$#', $path, $matches) === 1) {
                $parcel = $this->parcels->find(rawurldecode($matches[1]));
                return $parcel === null ? Response::error('Parcela nebola nájdená.', 404) : Response::json($parcel);
            }
            if ($path === '/api/katastralni-uzemi') return Response::json(['items' => $this->parcels->listKatastralniUzemi()]);
            if ($path === '/api/stats') return Response::json($this->parcels->stats());
            return Response::error('Požadovaná cesta neexistuje.', 404);
        } catch (\InvalidArgumentException $error) {
            return Response::error($error->getMessage(), 400);
        } catch (Throwable $error) {
            error_log($error->__toString());
            return Response::error('Služba je dočasne nedostupná.', 500);
        }
    }

    private function validateTile(int $z, int $x, int $y): void
    {
        if ($z < 0 || $z > 15 || $x < 0 || $y < 0 || $x >= (1 << $z) || $y >= (1 << $z)) {
            throw new \InvalidArgumentException('Neplatné súradnice dlaždice.');
        }
    }

    private function optionalInt(mixed $value, string $label): ?int
    {
        if ($value === null || $value === '') return null;
        if (is_array($value) || filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new \InvalidArgumentException('Neplatná hodnota: ' . $label . '.');
        }
        return (int) $value;
    }
}
