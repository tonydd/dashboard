# AGENTS.md — dashboard

Notes for coding agents working in this repository.

## Required reading

- At the start of every session, read
  `.github/copilot-instructions.md` before making any change. It is the
  authoritative project guide (stack, conventions, tests, operational
  precautions) and is not loaded automatically.

## Architecture conventions

- **Key-value storage**: application state that is not a business entity
  lives in the `KeyValue` entity/table (`symfony/src/Entity/KeyValue.php`).
  Add a case to the `App\Enum\KeyValueKey` enum and read/write through
  `KeyValueRepository` (`getValue`, `getUpdatedAt`, `setValue`). The
  `updated_at` column is maintained automatically by `setValue`. Do not
  create one-off tables for such state.

## Environment

- Production deploys run from the host via `scripts/deploy-prod.sh [branch]`
  (default `main`); secrets live in a GPG-encrypted tar archive, managed by
  `scripts/{read,add,copy,update}-prod-env.sh` (see
  `docs/PRODUCTION_DEPLOYMENT.md`).
- Local dev runs through Docker Compose (`compose.yml`): `php` on
  `localhost:8000`, camera signaling WebSocket on `localhost:8081`.
