# Current Deployment Audit

## Scope

This audit covers only the MyEventLane Hold repository at `/Volumes/anna/myeventlane_hold`.

Files and directories reviewed:

- `deploy/`
- `composer.json`
- `README.md`
- `docs/`
- `.gitignore`
- `web/.gitignore`
- `.env.example`

An untracked file exists at `etc/myeventlane/production.env`. It was not read because it may contain real environment secrets. Its presence is recorded as a repository hygiene and security concern, but no assumptions are made about its contents.

No `.github/` workflows were found in this repository.

## Current Architecture

The repository currently mixes Drupal application code and deployment infrastructure.

Confirmed deployment-related files:

- `deploy/push-and-deploy.sh`
- `deploy/site-deploy.sh`
- `deploy/cpanel-post-deploy.sh`
- `deploy/post-deploy-verify.sh`
- `deploy/check-mel-environment.sh`
- `deploy/deploy-common.sh`
- `deploy/bootstrap-debian-ubuntu.sh`
- `deploy/rsync-exclude.txt`
- `deploy/deploy.env.example`
- `deploy/production.env.example`
- `deploy/nginx-myeventlane.conf`
- `deploy/php-fpm-99-clear-env.conf`
- `deploy/systemd/php8.3-fpm.service.d/override.conf`

The current deployment implementation is designed for the holding site. `deploy/deploy-common.sh` describes its helpers as "holding site only", and several comments identify staging paths as off limits for holding-site scripts.

## Deployment Entry Points

Confirmed entry points:

- `bash deploy/push-and-deploy.sh`
- `bash deploy/site-deploy.sh /path/to/project`
- `bash deploy/cpanel-post-deploy.sh /path/to/project`
- `sudo bash deploy/bootstrap-debian-ubuntu.sh`
- `bash deploy/post-deploy-verify.sh /path/to/project`
- `bash deploy/check-mel-environment.sh /path/to/project`

`deploy/push-and-deploy.sh` is the highest-level local deployment entry point. It can push to GitHub, send files to the server, and run remote post-deploy commands.

`deploy/site-deploy.sh` is a server-side Drupal deployment script. It runs Composer, creates runtime directories, creates `settings.production.php` from an example when present, runs database updates or a first install, rebuilds cache, and optionally runs verification.

`deploy/cpanel-post-deploy.sh` is a server-side post-deploy script for cPanel or shared hosting. It runs Composer, runs database updates and cache rebuild when Drupal bootstraps, and optionally runs verification.

`deploy/bootstrap-debian-ubuntu.sh` is a one-time server provisioning script. It installs packages, configures PHP-FPM environment loading, creates `/etc/myeventlane/production.env` if missing, creates a database/user, creates a deploy user, writes an Nginx config, and reloads services.

## Deployment Modes

`deploy/push-and-deploy.sh` supports two modes through `MEL_DEPLOY_METHOD`:

- `rsync`: default mode for cPanel or shared hosting where `/home/mel` is not a git repository.
- `git`: future/disabled-by-default mode where the server has a git clone at `MEL_DEPLOY_PATH`.

The default branch is `main` through `MEL_DEPLOY_BRANCH`.

The default deploy path is `/home/mel` through `MEL_DEPLOY_PATH`.

The default remote user is `root`, with remote commands run as `mel` through `sudo -u mel` when required.

## Environment Files

Confirmed environment examples:

- `.env.example` for local Docker development.
- `deploy/deploy.env.example` for local deployment settings consumed by `deploy/push-and-deploy.sh`.
- `deploy/production.env.example` for server-side production settings intended for `/etc/myeventlane/production.env`.

Confirmed ignored local or production environment files:

- `.env`
- `.env.*` except `.env.example`
- `deploy/deploy.env`
- `web/sites/*/settings.production.php`
- `web/sites/*/settings.local.php`

Confirmed production environment loading:

- `deploy/deploy-common.sh` loads `${MEL_PRODUCTION_ENV_FILE:-/etc/myeventlane/production.env}` when present.
- `deploy/check-mel-environment.sh` validates `MEL_ENVIRONMENT`, `DRUPAL_BASE_URL`, `DRUPAL_HASH_SALT`, and `WAITLIST_TOKEN_SECRET`.
- PHP-FPM is configured to inherit `/etc/myeventlane/production.env` through the systemd override and `clear_env = no`.

The untracked `etc/myeventlane/production.env` under this repository was not read. If it contains real secrets, it should not live inside the repository working tree.

## Rsync Usage

`deploy/push-and-deploy.sh` uses:

- `rsync -avz --delete --no-owner --no-group`
- `--exclude-from=deploy/rsync-exclude.txt`
- `ssh -o BatchMode=yes`

Confirmed rsync exclusions:

- `.git/`
- `.github/`
- `.ddev/`
- `.env`
- `.env.*`
- `deploy/deploy.env`
- `vendor/`
- `node_modules/`
- `web/sites/default/files/`
- `web/sites/default/settings.local.php`
- `web/sites/default/settings.production.php`
- `private/*`
- `*.log`
- `.DS_Store`
- `.idea/`
- `.vscode/`

Risk: `--delete` operates directly against the target deployment path. There is no release directory isolation, no precomputed dry-run manifest, and no repository-specific guard beyond the staging-path blocklist.

## SSH Usage

`deploy/push-and-deploy.sh` uses SSH for:

- Running remote commands with `ssh -o BatchMode=yes`.
- Running `chown -R ${RUN_AS}:${RUN_AS} '${DEPLOY_PATH}'` after rsync.
- Running server-side post-deploy scripts through a heredoc.

The script allows SSH as one user and deployment commands as another user through `sudo -u`.

Risk: The remote path is configurable, but validation is limited. The script refuses known staging paths, but it does not prove that the target path belongs to the intended site, environment, or repository before `rsync --delete` and `chown -R` run.

## Symlink Usage

Confirmed symlink usage exists only in server bootstrap:

- `deploy/bootstrap-debian-ubuntu.sh` runs `ln -sf /etc/nginx/sites-available/myeventlane /etc/nginx/sites-enabled/myeventlane`.

No release symlink model was found. There is no `current` symlink for active releases and no `releases/` directory lifecycle.

## Validation

Current validation exists but is deployment-path focused.

Confirmed validation:

- Project root must contain `composer.json`.
- Known staging paths are refused by `mel_assert_not_staging_path`.
- Required production variables are checked by `deploy/check-mel-environment.sh`.
- `DRUPAL_BASE_URL` must be an absolute HTTP(S) URL.
- Composer must exist for post-deploy verification.
- `vendor/autoload.php` must exist for post-deploy verification.
- `drush status` must pass for post-deploy verification.
- `drush config:status` is run, but differences only warn.
- Maintenance mode must be off.
- `simple_sitemap` generation runs when the module is enabled.

Validation gaps:

- No clean git working tree requirement before deploy.
- No explicit `composer validate` before deploy.
- No explicit check that `composer.lock` is in sync before remote deployment.
- No explicit confirmation that the target path contains the expected site identity before destructive sync.
- No HTTP health check was found.
- No release symlink verification was found.
- No database backup checkpoint was found before `updatedb`.
- No pre/post release marker was found.
- No isolation check for multiple independent Drupal installations beyond a blocklist for known staging paths.

## Rollback Capability

No release-based rollback capability was found.

Existing rollback-related behaviour:

- `deploy/deploy.env.example` says to keep rsync as rollback until Git-based deployment is proven.
- No executable rollback script was found.
- No release directories are created.
- No previous release is retained.
- No `current` symlink exists for atomic rollback.
- No database rollback or backup workflow was found.

Rollback limitation: once files are changed in place, rollback depends on another deploy, manual restore, or external backups. Database updates run in place and may not be reversible.

## Deployment Assumptions

Confirmed assumptions in the current scripts:

- The holding-site production host can be reached by SSH.
- The deployment operator may SSH as `root` and run commands as `mel`.
- Production can live at `/home/mel` or another configured path.
- Staging paths under `/home/mel/staging*` must not be touched by holding-site deployment scripts.
- Composer and Drush run on the server.
- Production environment variables live in `/etc/myeventlane/production.env` or are exported before deployment.
- For the Debian/Ubuntu bootstrap path, the server uses Nginx, PHP 8.3 FPM, MariaDB, Certbot, and systemd.
- The repository contains Drupal code, Composer metadata, Drupal config, custom theme code, and custom module code.

Unconfirmed assumptions:

- Whether the production server currently uses cPanel, VPS, or another hosting model.
- Whether `/home/mel` is currently the active production document root parent.
- Whether another independent Drupal installation exists on the same server.
- Whether "MyEventLane Platform" has an existing repository, server, release layout, or deployment workflow.
- Whether server-level backups exist.
- Whether deployment is currently run manually, by CI, or both.

## Strengths

- Deployment scripts use `set -euo pipefail`.
- Secrets are not printed by environment validation.
- `deploy/deploy.env` is gitignored.
- `settings.production.php` is gitignored.
- The rsync exclude file avoids copying local secrets, vendor, generated files, and user files.
- There is a shared helper for staging-path refusal.
- Post-deploy verification checks Drupal bootstrap, config status, maintenance mode, and sitemap generation.
- The current scripts avoid changing Drupal runtime behaviour during this audit.
- The repository uses Composer for Drupal 11 dependencies and Drush 13.

## Weaknesses

- Deployment infrastructure is stored inside the Drupal application repository.
- The deployment scripts are explicitly holding-site specific.
- The scripts combine source control operations, file transfer, remote execution, Composer install, Drupal updates, verification, and server provisioning in the same repository.
- Rsync deploys in place with `--delete`.
- There is no release isolation.
- There is no atomic activation step.
- There is no durable rollback target.
- Server bootstrap is coupled to one site name and one Nginx configuration.
- Path validation uses a blocklist for known staging paths rather than an allowlist or environment manifest.
- Database updates are run without a documented backup gate.
- Verification can be skipped with `MEL_DEPLOY_SKIP_VERIFY=1`.
- `drush config:status` warnings do not fail deployment.
- `deploy/site-deploy.sh` runs `drush cim -y || true`, which can hide failed config imports.

## Coupling

Current coupling exists in these areas:

- Deployment scripts are committed beside Drupal code.
- Server path defaults and comments are site-specific.
- Server bootstrap installs a site-specific Nginx config.
- Environment file naming is site-specific: `/etc/myeventlane/production.env`.
- Staging protection is hardcoded to `/home/mel/staging*`.
- The scripts assume deployment is for the holding site, not a fleet of independent Drupal installations.

This coupling makes it easier for one site's deployment script to accidentally know about, block, or affect another site's deployment layout.

## Deployment Risks

Primary risks:

- In-place rsync with `--delete` can remove files from the active site immediately.
- A wrong `MEL_DEPLOY_PATH` can damage another installation.
- `chown -R` on the configured path can change ownership of unrelated files if the path is wrong.
- No release isolation means failed Composer install or failed Drush commands can leave the active site partially updated.
- `drush updatedb -y` runs before an enforced backup checkpoint.
- `drush cim -y || true` can continue after a config import failure.
- Verification can be skipped.
- There is no HTTP health check to prove the public site responds after deployment.
- There is no multi-site deployment registry to separate Hold and Platform.
- Server provisioning files live with the application and could be run against the wrong target.

## Security Review

Destructive commands identified:

- `rsync --delete` in `deploy/push-and-deploy.sh`.
- `chown -R ${RUN_AS}:${RUN_AS} '${DEPLOY_PATH}'` in `deploy/push-and-deploy.sh`.
- `git checkout`, `git pull`, and `git push` in `deploy/push-and-deploy.sh`.
- `drush updatedb -y` in `deploy/site-deploy.sh` and `deploy/cpanel-post-deploy.sh`.
- `drush cim -y || true` in `deploy/site-deploy.sh`.
- `rm -f /etc/nginx/sites-enabled/default` in `deploy/bootstrap-debian-ubuntu.sh`.
- Database user and grant changes in `deploy/bootstrap-debian-ubuntu.sh`.

Unsafe or incomplete controls:

- Path validation is incomplete and blocklist-based.
- Environment isolation is not modelled per site and environment.
- Release isolation is missing.
- Rollback is missing.
- No dry-run deployment manifest is generated.
- No required clean local working tree check exists.
- No public health check exists.
- No symlink verification exists.
- No database backup gate exists.
- No confirmation that the remote path belongs to the intended Drupal installation was found.

Recommendations:

- Move deployment infrastructure into a separate deployment repository.
- Use a site/environment manifest with explicit allowlisted paths.
- Deploy into immutable release directories.
- Activate releases through a `current` symlink.
- Keep shared files outside releases.
- Require clean source state and Composer validation before building a release.
- Require backup or backup confirmation before database updates.
- Fail deployment on config import failure.
- Make verification mandatory for production.
- Add HTTP and symlink verification.
- Keep Hold and Platform deployments isolated by path, environment file, release directory, shared directory, SSH target, and deploy identity.

## Repository-Safe Validation Results

Requested validation was run from the repository root.

Passed:

- `composer validate`
- `git diff --check`
- `bash -n deploy/*.sh`

Not run:

- `shellcheck deploy/*.sh`

Reason:

- ShellCheck is not installed in the local environment. The validation command recorded `SHELLCHECK_NOT_INSTALLED` and did not treat that as a failure.

## Conclusion

The current deployment implementation is practical for a single holding-site deployment, but it is not suitable as shared deployment logic for multiple independent Drupal installations.

The key architectural limitation is that deployment infrastructure, server assumptions, release operations, and application code live together. The next architecture should move deployment ownership into a separate `mel-deployment` repository, define isolated site/environment manifests, deploy into release directories, activate with symlinks, and make validation and rollback first-class behaviours.

## Phase 2 Release Deployment Preparation Findings

This append-only update records findings for preparing MyEventLane Hold for future deterministic, release-based deployments by a separate deployment repository.

Files reviewed for this phase:

- `deploy/`
- `docs/audits/current-deployment-audit.md`
- `docs/deployment/deployment-redesign.md`
- `composer.json`
- `composer.lock`
- `web/index.php`
- `web/.htaccess`
- `web/sites`
- `README.md`

New documentation created for this phase:

- `docs/deployment/deployment-contract.md`
- `docs/deployment/release-validation.md`
- `docs/deployment/release-metadata.md`
- `docs/deployment/deployment-interface.md`
- `docs/audits/deployment-security-review.md`

No Drupal source, Commerce code, theme code, module code, runtime configuration, deployment scripts, or GitHub workflows were changed.

### Deployment Entry Points Reconfirmed

Confirmed deployment entry points remain:

- `bash deploy/push-and-deploy.sh`
- `bash deploy/site-deploy.sh /path/to/project`
- `bash deploy/cpanel-post-deploy.sh /path/to/project`
- `sudo bash deploy/bootstrap-debian-ubuntu.sh`
- `bash deploy/post-deploy-verify.sh /path/to/project`
- `bash deploy/check-mel-environment.sh /path/to/project`

`deploy/push-and-deploy.sh` remains the highest-level local deployment script. It performs git operations, file transfer or remote git pull, and remote post-deploy execution.

### Release Support Reconfirmed

No release-based deployment implementation was found in this repository.

Missing release capabilities remain:

- No immutable `releases/` directory lifecycle.
- No `current` symlink activation.
- No generated release metadata.
- No deployment ID recording.
- No Composer lock hash recording.
- No atomic activation gate.
- No previous-release preservation.

The new deployment documentation defines the expected future contract only. It does not implement release behaviour.

### Rollback Support Reconfirmed

No executable rollback support was found.

The future deployment repository must own rollback. This repository now documents that rollback should preserve the previous release and must never switch the active release after a failed pre-activation validation.

### Validation Findings Reconfirmed

Existing validation remains script-specific and deployment-path focused.

Confirmed existing validation:

- Project root checks require `composer.json`.
- Known staging paths under `/home/mel/staging*` are refused.
- Production environment variables are checked without printing secret values.
- `vendor/autoload.php` is checked by post-deploy verification.
- `drush status` is checked by post-deploy verification.
- Maintenance mode is checked after deployment.

Validation still missing from the current scripts:

- Required clean working tree check.
- Required `composer validate`.
- Composer lock hash recording.
- HTTP 200 health check.
- Release marker validation.
- Target site/environment marker validation.
- Backup checkpoint before database updates.
- Release symlink verification.

These checks are now documented in `docs/deployment/release-validation.md` for future implementation outside this repository.

### GitHub Readiness

No `.github/` directory was found in this repository during this phase.

Because `.github/` does not exist, no workflow assumptions, deployment isolation controls, or GitHub environment usage could be audited. No GitHub workflows were created or modified.

### Security Review Findings

The deployment security review identified the same primary risk pattern: current scripts deploy in place and use destructive commands without release isolation.

High-impact behaviours identified:

- `rsync --delete` runs against the configured deploy path.
- `chown -R` runs against the configured deploy path.
- `drush updatedb -y` can run without a documented backup gate.
- `drush cim -y || true` can hide config import failure.
- Server bootstrap can alter Nginx, PHP-FPM, MariaDB, users, and service state.

Detailed findings are recorded in `docs/audits/deployment-security-review.md`.

### Repository Contract Findings

The future deployment contract is now documented as:

- This repository owns Drupal source, Composer metadata, Drupal configuration, and deployment metadata specifications.
- This repository does not own production servers, release management, rollback, infrastructure, secrets, or GitHub environments.

This boundary matches the Phase 2 objective and keeps release orchestration outside the application repository.

### Unresolved Assumptions

The following assumptions remain unresolved and were not guessed:

- The final deployment repository name.
- The production server release directory layout.
- The production `current` symlink path.
- The final deploy user and SSH target.
- The secret provider or final environment file strategy.
- The backup checkpoint process before database updates.
- The public health check URL.
- The release retention policy.
- Whether future validation will run locally, in CI, on the server, or across multiple stages.

### Phase 2 Repository-Safe Validation Results

Requested validation was run from the repository root after documentation changes.

Passed:

- `composer validate`
- `git diff --check`
- `bash -n deploy/*.sh`

Not run:

- `shellcheck deploy/*.sh`

Reason:

- ShellCheck is not installed in the local environment. The validation command recorded `SHELLCHECK_NOT_INSTALLED` and did not treat that as a failure.
