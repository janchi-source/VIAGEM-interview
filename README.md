# Viagem parcel map

This project will provide a smooth parcel map backed by SQLite and PHP-generated
vector tiles. The initial scaffold follows the project brief; application
behavior will be added in later phases.

## Project layout

- `public/` - web root and browser assets
- `config.php` / `config.local.php` - application paths and local overrides
- `bootstrap.php` - PSR-4 autoloader and configuration loader
- `src/Domain/` - domain value objects
- `src/Geo/` - coordinate conversions
- `data/` - local SQLite database and imported VFR data
- `public/tiles/` - static tile cache
- `tests/` - automated tests
- `plan/` - task brief and working plan

## Running the scaffold

```sh
php -S localhost:8000 -t public public/index.php
```

The application is available at <http://localhost:8000>. Routes are not
implemented yet and currently return `OK`.

## Planned endpoints

- `GET /tiles/{z}/{x}/{y}.pbf` - PHP vector tile endpoint
- `GET /api/parcel/{id}` - parcel detail endpoint
