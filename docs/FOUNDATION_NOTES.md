# Milestone 0 — Foundation notes

Short record of the choices made while scaffolding (jobs J0.1–J0.4).

## Stack / starter kit

- **Laravel 12.66** + **official Livewire starter kit** (`composer create-project laravel/livewire-starter-kit`).
- Ships **Livewire 4.4**, **Volt 1.11**, **Flux 2.16**, **Tailwind v4** (Vite).
- This is the recommended "cleanest" path from BUILD_PLAN §2 / brief §21 Q2–Q3, and matches decision **D1** (take the starter kit / Livewire 4). No fallback to Breeze was needed.
- The starter kit provided the full auth loop (register, login, logout, password reset, remember-me, plus optional email verification & password confirmation) — verified by feature tests rather than rebuilt.

### One fix required after scaffolding

Livewire 4's default `config('livewire.component_layout')` is `layouts::app` (a `layouts` view namespace the app doesn't register), so any full-page Volt component without an explicit `#[Layout]` 500s with *"No hint path defined for [layouts]"*. Published `config/livewire.php` and set `component_layout` to `components.layouts.app`. Also ran `npm ci && npm run build` — the Vite manifest must exist or every page 500s in tests.

## Database

- **PostgreSQL 16** (brief §4.1). `.env` + `.env.example` set to `pgsql`, `127.0.0.1:5432`, database `diet_tracker`.
- Local role `diet_user` created (superuser `postgres` was the only pre-existing role). `.env.example` ships with an empty `DB_PASSWORD` placeholder.
- `php artisan migrate` runs clean against Postgres. **No sqlite fallback was needed** — Postgres is the project DB. (The PHPUnit suite still uses sqlite `:memory:` as usual for speed; app/dev/prod use Postgres.)

## Redis / cache / queue / storage

- **Redis** is the driver for `CACHE_STORE`, `QUEUE_CONNECTION`, and `SESSION_DRIVER` (brief §4.1). `redis-server` is running locally on the default port.
- `FILESYSTEM_DISK=public` (local disk) for alpha image storage (brief §4.1, §21 Q43).

## Architecture decisions worth knowing

- **Thin components:** all profile/onboarding/account/deletion logic lives in `app/Services/ProfileService.php`; Livewire/Volt components only collect input and delegate (brief §4.1, §22.14).
- **Enums** back the small fixed vocabularies: `PrimaryGoal`, `Sex`, `ActivityLevel`, `DietaryPattern` (`app/Enums`).
- **`user_profiles`** is one denormalised row per user (BUILD_PLAN §5, idea #7): typed columns + JSON for `dietary_preferences` / `allergies` / `avoided_foods`. Everything is nullable except `primary_goal`. An extra `onboarding_completed_at` timestamp gates the onboarding flow.
- **Onboarding gate:** `EnsureOnboarded` middleware (alias `onboarded`) redirects authenticated-but-not-onboarded users into the flow. Optional fields are never required.
- **Navigation:** mobile-first shell (`components/layouts/app.blade.php`) with a bottom nav — Home / Pantry / **Scan (raised, prominent centre)** / Eat / Health — plus a top-right profile action. Placeholder screens stand in for later milestones.
- **Account deletion** performs real deletion of the user *and* profile inside a transaction (brief §6.4, §21 Q45).
- No AI anywhere in this milestone (brief §6.6).

## Tests

`php artisan test` — **38 passed**. Covers the full auth loop (register / login / logout / reset / remember-me), the app-shell nav & guards, onboarding completion (incl. optional-field skipping and required-goal validation), profile view/edit, and account+profile deletion.
