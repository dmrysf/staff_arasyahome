# GitHub production guardrails

These are recommended manual repository settings; this file does not claim they are enabled.

For `main`, block force pushes and branch deletion. Require the Staff and Operations API checks where compatible with the repository plan. A pull-request requirement is recommended for team development, but should be enabled only with owner approval so the current direct-push workflow is not silently broken.

Treat `deploy` and `api-deploy` as generated, read-only release branches:

- do not edit or commit to them manually;
- do not manually force-push them;
- allow only the corresponding GitHub Actions publishing job to create normal releases;
- investigate any release whose `release.json` source SHA or `SHA256SUMS` differs from the verified package.

Workflows default to `contents: read`; only the isolated publishing jobs receive `contents: write`. External actions are pinned to verified full commits. Dependabot checks npm/pnpm and GitHub Actions weekly, opens reviewable updates, and does not auto-merge.
