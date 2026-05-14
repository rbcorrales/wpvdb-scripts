# wpvdb-scripts

Shared automation scripts and reusable workflows for WPVDB plugin repositories.

The workflows cover plugin checks and release zip creation. Plugin repositories call these workflows with their own slug, entry file, build command, and translation settings.

## Workflows

| Workflow | Type | Purpose |
|---|---|---|
| `ci.yml` | Repository workflow | Validates `wpvdb-scripts` itself. |
| `plugin-ci.yml` | Reusable workflow | Runs plugin Composer, Bun, lint, analysis, and build commands. |
| `plugin-release.yml` | Reusable workflow | Builds a plugin release zip from a tag and uploads it to GitHub Releases. |

## Scripts

`scripts/bump-plugin-version.php` updates a plugin header, optional version constant, optional `package.json`, optional POT header, and optional block metadata versions.
