# wpvdb-scripts

[![Checks](https://github.com/Automattic/wpvdb-scripts/actions/workflows/ci.yml/badge.svg)](https://github.com/Automattic/wpvdb-scripts/actions/workflows/ci.yml)
[![PHP](https://img.shields.io/badge/PHP-8.3%2B-777bb4?logo=php&logoColor=white)](#requirements)
[![License](https://img.shields.io/badge/License-GPLv2%2B-blue.svg)](LICENSE)

Shared automation scripts and reusable workflows for WPVDB plugin repositories.

The workflows cover plugin checks and release zip creation. Plugin repositories call these workflows with their own slug, entry file, build command, and translation settings.

## Requirements

| Requirement | Version or notes |
|---|---|
| PHP | 8.3 or newer |
| GitHub Actions | Used by the reusable workflow files |

## Workflows

| Workflow | Type | Purpose |
|---|---|---|
| `ci.yml` | Repository workflow | Validates `wpvdb-scripts` itself. |
| `plugin-ci.yml` | Reusable workflow | Runs plugin Composer, Bun, lint, analysis, test, and build commands. |
| `plugin-maintain-main.yml` | Reusable workflow | Bumps plugin versions, regenerates generated plugin files on `main`, and commits them when needed. |
| `plugin-release.yml` | Reusable workflow | Builds a plugin release zip from a tag and uploads it to GitHub Releases. |

## Caller contract

Plugin repositories should call these workflows from thin repo local workflows.

- Set `permissions: contents: read` for CI wrappers.
- Set `permissions: contents: write` for maintenance and release wrappers.
- Pass `i18n-command` when a plugin owns WordPress translation files. The command must be deterministic and should update tracked files under `languages/`.
- List every path the maintenance workflow may commit in `commit-paths`. It defaults to `languages/`.
- Pass `version-bump: true` when a plugin should bump versions from conventional commits on `main`.
- Pass the plugin file and any extra version surfaces, such as `version-constant`, `package-file`, `pot-file`, `pot-project`, or `block-json-glob`.
- Pass `test-command` when a plugin has a local unit test command that should run in CI and release checks. Tests run before `build-command`, so the command should build any artifacts it depends on.
- Build block plugins before i18n when they generate JavaScript translation JSON.
- Track `languages/source-map.json` for block plugins that call `wp i18n make-json --use-map`, but exclude it from release zips with `.distignore`.
- Tag releases only after the maintenance commit has landed on `main`. Commits made by the built in `GITHUB_TOKEN` do not trigger another workflow run, so the release workflow reruns i18n and fails if generated files drift.

Version bump mapping:

| Commit type | Bump |
|---|---|
| Breaking change | Major |
| `feat` | Minor |
| `fix`, `perf`, `refactor` | Patch |
| Other types | None |

## Scripts

| Script or action | Purpose |
|---|---|
| `scripts/bump-plugin-version.php` | Updates a plugin header, optional version constant, optional `package.json`, optional POT header, and optional block metadata versions. |
| `.github/actions/bump-plugin-version/action.yml` | Composite action wrapper for `scripts/bump-plugin-version.php`. |
| `i18n-command` workflow input | Runs plugin owned i18n generation during maintenance and release workflows. |
| `test-command` workflow input | Runs caller owned tests during CI and release workflows. |

The bump script can also be run directly with environment variables:

```bash
PLUGIN_FILE=plugin.php php scripts/bump-plugin-version.php patch
```

Pass `major`, `minor`, or `patch` as the first argument. Set `PACKAGE_FILE`, `VERSION_CONSTANT`, and `BLOCK_JSON_GLOB` when the target plugin has those version surfaces.

Plugin maintenance and release workflows can receive an `i18n-command` input. Maintenance runs it on `main` and commits generated language file changes when needed. Release runs it before staging the zip, then fails if generation changed tracked files so tags stay aligned with release artifacts.

## Development

Install dependencies:

```bash
composer install
```

Run the local checks:

```bash
composer lint
composer test
```
