# Deployment Security Review

## Scope

This review covers the current deployment files in `deploy/` only.

This is an identification-only review. It does not change deployment behaviour, deployment scripts, Drupal runtime behaviour, Commerce, themes, modules, GitHub workflows, or server infrastructure.

Reviewed files:

- `deploy/push-and-deploy.sh`
- `deploy/site-deploy.sh`
- `deploy/cpanel-post-deploy.sh`
- `deploy/post-deploy-verify.sh`
- `deploy/check-mel-environment.sh`
- `deploy/deploy-common.sh`
- `deploy/bootstrap-debian-ubuntu.sh`
- `deploy/deploy.env.example`
- `deploy/production.env.example`
- `deploy/rsync-exclude.txt`

No `.github/` directory was found, so no workflow security review was possible in this repository.

## Path Validation

Confirmed controls:

- Deployment scripts check for `composer.json` at the provided project root.
- `deploy-common.sh` refuses known staging paths under `/home/mel/staging`, `/home/mel/staging/current`, `/home/mel/staging-repo`, and `/home/mel/staging/*`.

Identified risks:

- Path validation is blocklist-based rather than allowlist-based.
- The scripts do not prove that the target path belongs to the intended site, environment, or repository.
- The scripts do not reject broad parent paths such as `/`, `/home`, or `/var/www` except for the known staging paths.
- There is no release marker or site marker check before destructive operations.

## Destructive Commands

Destructive or high-impact commands identified:

- `rsync -avz --delete` in `deploy/push-and-deploy.sh`.
- `chown -R ${RUN_AS}:${RUN_AS} '${DEPLOY_PATH}'` in `deploy/push-and-deploy.sh`.
- `git checkout`, `git pull`, and `git push` in `deploy/push-and-deploy.sh`.
- `drush updatedb -y` in `deploy/site-deploy.sh` and `deploy/cpanel-post-deploy.sh`.
- `drush cim -y || true` in `deploy/site-deploy.sh`.
- `rm -f /etc/nginx/sites-enabled/default` in `deploy/bootstrap-debian-ubuntu.sh`.
- Database creation, user creation, and grant changes in `deploy/bootstrap-debian-ubuntu.sh`.

Identified risks:

- Destructive file operations run against the configured deploy path in place.
- Recursive ownership changes can affect unrelated files if the path is wrong.
- Database updates run without a documented backup gate.
- Config import failure can be hidden by `|| true`.

## Rsync Safety

Confirmed controls:

- `deploy/rsync-exclude.txt` excludes `.git/`, `.github/`, `.env`, `.env.*`, `deploy/deploy.env`, `vendor/`, `node_modules/`, user files, private files, logs, and common editor files.
- Rsync uses `--no-owner` and `--no-group`.
- SSH runs with `BatchMode=yes`.

Identified risks:

- `rsync --delete` deploys directly to the active target path.
- There is no dry-run manifest requirement.
- There is no immutable release directory.
- There is no atomic activation step.
- There is no previous release preservation before deletion.

## SSH Assumptions

Confirmed assumptions:

- `deploy/push-and-deploy.sh` assumes SSH access to `MEL_DEPLOY_HOST`.
- The default SSH user is `root`.
- Remote commands can run as `MEL_DEPLOY_RUN_AS`, defaulting to `mel`, through `sudo -u` when the SSH user differs.

Identified risks:

- SSH target identity is provided through local environment configuration and is not independently verified against a manifest.
- Root SSH is the documented default.
- There is no host allowlist in this repository.
- There is no server-side proof that the selected deploy user owns only the intended release path.

## Environment Assumptions

Confirmed assumptions:

- Local deployment settings may be loaded from `deploy/deploy.env`, which is gitignored.
- Production settings may be loaded from `/etc/myeventlane/production.env` or `MEL_PRODUCTION_ENV_FILE`.
- Required production variables include `MEL_ENVIRONMENT`, `DRUPAL_BASE_URL`, `DRUPAL_HASH_SALT`, and `WAITLIST_TOKEN_SECRET`.
- Secret values are not printed by `check-mel-environment.sh`.

Identified risks:

- The production environment file path is site-specific and not environment-manifest driven.
- Required variable checks confirm presence, not value suitability beyond the base URL scheme.
- The repository currently has an untracked `etc/myeventlane/production.env` path in the working tree status; it was not read because it may contain secrets.

## Production-Only Assumptions

Confirmed assumptions:

- Scripts and comments are specific to the holding site.
- Defaults target `main`, `/home/mel`, `myeventlane.com.au`, and user `mel`.
- The bootstrap script assumes Debian or Ubuntu, Nginx, PHP 8.3 FPM, MariaDB, Certbot, and systemd.

Identified risks:

- Production and staging isolation is protected only by a staging path blocklist in these scripts.
- There is no site/environment manifest separating production from any future staging or platform deployment.
- Server provisioning files live in the application repository.

## Missing Validation

Missing validation identified:

- No required clean git working tree check before deployment.
- No required `composer validate` before deployment.
- No Composer lock hash recording.
- No release ID or deployment ID recording.
- No remote target marker validation.
- No HTTP 200 health check.
- No release symlink verification.
- No backup checkpoint before database updates.
- No mandatory failure on config import differences or config import failure.

## Missing Rollback

No release-based rollback support was found.

Missing rollback capabilities:

- No immutable release directories.
- No retained previous release target.
- No `current` symlink activation model.
- No rollback command.
- No release metadata proving which release is active.
- No rollback verification.

Rollback currently depends on manual intervention, another deployment, or external backup restoration.

## Security Recommendations For Future Deployment Repository

Future deployment tooling should:

- Use allowlisted site and environment manifests.
- Refuse empty, broad, or unregistered paths.
- Build immutable releases outside the active document root.
- Activate by switching a verified `current` symlink.
- Preserve the previous successful release until the new release passes verification.
- Require backup confirmation before database updates.
- Fail loudly on config import failure.
- Require `composer validate`, clean source state, Composer lock hash recording, Drush validation, and HTTP health checks.
- Avoid printing secrets.
- Keep Hold deployment isolated from any future Platform deployment.

These recommendations are documented only and are not implemented in this phase.
