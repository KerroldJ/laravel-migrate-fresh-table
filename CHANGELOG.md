# Changelog

All notable changes to `laravel-migrate-fresh-table` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- `migrate:fresh-table` command to drop & recreate a specific table or an ordered
  set of tables.
- Live foreign-key impact report (parents and children) with interactive
  confirmation.
- Pluggable resolution strategies: `migration` (auto-detect + override map) and
  `schema` (Blueprint callback), plus a `FreshStrategy`/`TableResolver` contract
  for custom strategies.
- Multi-connection and multitenancy support: `--connection`, `--database`,
  `--all-connections`, a static connection list, and a dynamic `tenant_resolver`.
- PostgreSQL `--schema` / `search_path` awareness.
- `--dry-run`, `--pretend`, `--force` (production guard), `--seed`, `--seeder`.
- Lifecycle events (`TableFreshing`, `TableDropping`, `TableDropped`,
  `TableRecreating`, `TableRecreated`, `TableFreshed`) and global before/after
  hooks.
- Publishable config file and a Pest + Testbench test suite (SQLite by default,
  MySQL/PostgreSQL via `DB_DRIVER`).
