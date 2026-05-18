# AGENTS.md

Agent guidance for this repository. Keep this file focused on non-obvious workflow contracts and release automation gotchas. General project information belongs in `README.md`.

## Boundaries

- This repo is public. Do not add credentials, tokens, site-specific hostnames, or private deployment details.
- Keep plugin-specific behavior in caller repos unless it is truly reusable across the WPVDB plugin suite.
- Prefer adding workflow inputs over hardcoding one plugin's layout into a reusable workflow.
- Do not add static analysis just for these helper scripts without a concrete failure mode. The current local check surface consists of Composer, PHPCS, and PHPUnit.

## Reusable workflow gotchas

- Plugin repos call these reusable workflows from `@main`, so changes here immediately affect checks, maintenance, and releases.
- The maintenance workflow intentionally commits only caller-specified `commit-paths`. Do not replace that with broad `git add -A`.
- Caller commands such as `build-command` and `i18n-command` are trusted repo inputs and run through `eval`. Keep that contract internal to the caller workflow wrappers.
- Maintenance commits are made with the default `GITHUB_TOKEN`. Those commits do not trigger another workflow run, which prevents recursion.
- Do not add `[skip ci]` back to maintenance commit subjects. Tags that point to version-bump commits need to trigger the release workflow automatically.

## Release flow

- Tag plugin releases after the maintenance commit has landed on `main`, not on the feature commit.
- The release workflow reruns i18n before staging the zip and fails if generated files drift from the tag.
- Release zips should contain compiled runtime files and language files, not source directories, dependency folders, or repository docs.
- Keep release verification strict when adding new required artifacts. Playground depends on those zips being directly installable.

## Version bump helper

- `scripts/bump-plugin-version.php` is shared infrastructure. Keep it conservative and fail closed when a version surface is ambiguous.
- If a plugin adds another version surface, prefer exposing it as an optional input rather than special casing a repo name.
- Prerelease and build metadata versions are intentionally unsupported by the bump helper right now.
