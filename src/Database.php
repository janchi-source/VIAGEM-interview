<?php

declare(strict_types=1);

namespace Viagem;

use PDO;

final class Database
{
    public static function connect(string $path, bool $readOnly = false): PDO
    {
        $dsn = $readOnly ? 'sqlite:file:' . $path . '?mode=ro' : 'sqlite:' . $path;
        $pdo = new PDO($dsn);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        if ($readOnly) {
            $pdo->exec('PRAGMA query_only=ON');
        }
        return $pdo;
    }

    public static function createSchema(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS obec (
    kod INTEGER PRIMARY KEY, nazev TEXT NOT NULL, okres_kod INTEGER
);
CREATE TABLE IF NOT EXISTS cast_obce (
    kod INTEGER PRIMARY KEY, obec_kod INTEGER, nazev TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS ulice (
    kod INTEGER PRIMARY KEY, obec_kod INTEGER, nazev TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS ku (
    kod INTEGER PRIMARY KEY, nazev TEXT NOT NULL, obec_kod INTEGER,
    min_lon REAL, min_lat REAL, max_lon REAL, max_lat REAL,
    label_lon REAL, label_lat REAL, parcel_count INTEGER NOT NULL DEFAULT 0, geom BLOB
);
CREATE TABLE IF NOT EXISTS parcel (
    id TEXT PRIMARY KEY, ku_kod INTEGER, kmenove_cislo INTEGER,
    pododdeleni INTEGER, druh_cislovani INTEGER, druh_pozemku TEXT,
    zpusob_vyuziti TEXT, vymera INTEGER, bpej TEXT NOT NULL DEFAULT '[]',
    label_lon REAL, label_lat REAL, geom BLOB
);
CREATE VIRTUAL TABLE IF NOT EXISTS parcel_rtree USING rtree(
    id, min_lon, max_lon, min_lat, max_lat
);
CREATE TABLE IF NOT EXISTS stavebni_objekt (
    id TEXT PRIMARY KEY, ku_kod INTEGER, cislo_domovni INTEGER,
    cislo_orientacni INTEGER, identifikacni_parcela TEXT,
    label_lon REAL, label_lat REAL, geom BLOB
);
CREATE TABLE IF NOT EXISTS adresni_misto (
    id TEXT PRIMARY KEY, obec_kod INTEGER, ku_kod INTEGER,
    cislo_domovni INTEGER, cislo_orientacni INTEGER, label_lon REAL, label_lat REAL
);
CREATE TABLE IF NOT EXISTS meta (key TEXT PRIMARY KEY, value TEXT NOT NULL);
SQL);
    }
}
