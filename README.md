# Protección Laboral

Plataforma de gestión de procesos legales laborales con asistencia IA (Claude).

## Stack
- Laravel 12 + Inertia + React
- MySQL
- Tailwind + Vite
- Claude API (Anthropic) — borradores y clasificación de emails

## Setup local
```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm run dev
php artisan serve
```

Acceso por defecto: ver `database/seeders/InitialUsersSeeder.php`. El usuario `carlos.camacho@proteccionlaboral.co` tiene rol `director` (admin). Password: `Password123!`.

## Ramas
- `develop` — integración estable
- `dev/ia-sprint1` — sprint actual (IA + ingesta Gmail)

## Configuración Anthropic
Variables en `.env` (ver `.env.example`):
- `ANTHROPIC_API_KEY`
- `ANTHROPIC_MODEL` (default `claude-sonnet-4-6`)
- `ANTHROPIC_MAX_TOKENS` (default `4096`)
- `ANTHROPIC_TIMEOUT` (default `60`)

## Tests
```bash
php artisan test
```
