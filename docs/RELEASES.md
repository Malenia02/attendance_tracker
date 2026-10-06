# AttendanceHub releases

The frontend package version is the single application release number. The
production build also records the deployed Git commit. The About page displays
both values; `version.json` lets an already-open browser detect a newer build.
The update notice never reloads the page automatically, so an unfinished form
is not discarded without the user's choice.

## Version numbers

Use `MAJOR.MINOR.PATCH` and Git tags such as `v0.1.0`:

- PATCH: compatible bug or security fix.
- MINOR: new compatible feature or workflow.
- MAJOR: intentionally incompatible API or workflow change.

While the application is still being tested, `0.x.y` is appropriate. Keep the
version in `frontend/package.json` and `frontend/package-lock.json` in sync.
Do not reuse a version number for different production releases. The build ID
also changes with the Git commit, so an urgent redeploy can still be detected.

## Release flow

1. Develop and test on `testing`. Run the frontend lint/build and backend tests.
2. Bump the frontend package version for the planned production release and
   write a short summary of changes and any migration or configuration steps.
3. Promote the tested commit to `main`. Verify that Vercel and Render deploy
   successfully, run any required database migrations, and check sign-in,
   attendance, and DTR generation.
4. After confirming the deployed commit, create a Git tag and GitHub Release
   with the same version and release notes. Never move a published release tag.

For database changes, deploy backward-compatible migrations before code that
requires the new schema. Rollback is not simply reverting a frontend commit:
database changes and data written by a new version may need a separate recovery
plan. Back up and rehearse restoration before risky releases.

The About version describes the frontend build. An API-only redeploy does not
change that label; include API changes in the release notes and promote the
frontend build if the visible release number should change.

The first deployment of this update cannot notify browser tabs that were opened
before the update checker existed. Those tabs need one normal refresh. Later
deployments are checked every five minutes and when a tab becomes active.
