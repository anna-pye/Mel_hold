# MyEventLane Hold

Production-oriented **Drupal 11** holding site with Docker Desktop local development, a custom pastel “coming soon” theme, and a **waitlist** module (double opt-in, CSV export, admin dashboard).

## Requirements

- Docker Desktop
- Composer (optional on host; PHP container can run Composer)

## Quick start (exact commands)

```bash
git clone https://github.com/anna-pye/myeventlane_hold.git
cd myeventlane_hold
cp .env.example .env
```

Edit `.env` and set at least:

- `DRUPAL_HASH_SALT` — run `openssl rand -hex 32`
- `WAITLIST_TOKEN_SECRET` — run `openssl rand -hex 32` (separate value)

Bring up the stack:

```bash
docker compose build
docker compose up -d
```

Wait a few seconds for MariaDB, then install Drupal (inside the PHP container):

```bash
docker compose exec php mkdir -p web/sites/default/files ../private ../config/sync
docker compose exec php chmod -R 775 web/sites/default/files ../private
docker compose exec php ./vendor/bin/drush site:install minimal \
  --yes \
  --account-name=admin \
  --account-pass=admin \
  --site-name="MyEventLane Hold"
```

Point your browser at `http://localhost:8080` (or the port in `MEL_HTTP_PORT`).

Enable the waitlist module and theme, set the default theme and front page, rebuild caches:

```bash
docker compose exec php ./vendor/bin/drush en myeventlane_waitlist -y
docker compose exec php ./vendor/bin/drush theme:enable myeventlane_hold_theme -y
docker compose exec php ./vendor/bin/drush config:set system.theme default myeventlane_hold_theme -y
docker compose exec php ./vendor/bin/drush config:set system.site page.front home -y
docker compose exec php ./vendor/bin/drush cr
```

**Email (Mailhog):** open `http://localhost:8025` (or `MEL_MAILHOG_UI_PORT`) to read confirmation messages. Process the outbound queue after signup:

```bash
docker compose exec php ./vendor/bin/drush queue:run myeventlane_waitlist_mail
```

Or wait for cron:

```bash
docker compose exec php ./vendor/bin/drush cron
```

### Local hostname (optional)

Add to `/etc/hosts`:

```text
127.0.0.1 myeventlane-hold.docker.localhost
```

Set `DRUPAL_BASE_URL` in `.env` to match (e.g. `http://myeventlane-hold.docker.localhost:8080`). Nginx is configured with `server_name myeventlane-hold.docker.localhost localhost;`.

### Root URL (`/`) and the holding page

On a **minimal** Drupal profile, core’s `<front>` route (`/`) has no main controller, so the bundled Nginx config forwards `/` to `index.php` with `REQUEST_URI=/home`. The real holding page is the **`/home`** route (`myeventlane_waitlist.holding_home`) with `system.site:page.front` set to `home`. In production (Apache, another reverse proxy, or managed hosting), mirror that behavior so `/` resolves to the same application path as `/home`, or use your platform’s equivalent of this Nginx rule.

## Configuration

- **Waitlist:** `/admin/config/myeventlane/waitlist` — sender, consent text, privacy body, token TTL, contact email.
- **Theme copy / SEO fields:** Appearance → Settings for **MyEventLane Hold** (meta title/description, hero text, badges).
- **Production:** copy `web/sites/default/settings.production.example.php` to `settings.production.php` (gitignored) and set trusted hosts, mail transport, and environment variables on the host.

## Environment variables (reference)

| Variable | Purpose |
|----------|---------|
| `DRUPAL_DATABASE_*` | DB connection (used by `settings.php` when set) |
| `DRUPAL_BASE_URL` | Base URL for absolute links |
| `DRUPAL_HASH_SALT` | Drupal hash salt |
| `WAITLIST_TOKEN_SECRET` | Secret for hashing confirmation/unsubscribe tokens (never store raw tokens) |
| `WAITLIST_IP_PEPPER` | Optional extra pepper for IP hashing |
| `MEL_ENVIRONMENT` | `local` → `noindex`; use `production` for indexing |
| `MEL_MAIL_MODE` | Informational; Docker uses `mhsendmail` → Mailhog |

## Project layout

- `docker-compose.yml` — nginx, php-fpm, MariaDB, Mailhog  
- `docker/php` — PHP 8.3 image + mhsendmail for Mailhog  
- `web/modules/custom/myeventlane_waitlist` — waitlist backend  
- `web/themes/custom/myeventlane_hold_theme` — holding theme  
- `config/sync` — export config here (`drush cex`) when you adopt config workflow  

## QA checklist

1. Front page shows hero, badges, waitlist panel, “What’s next”, footer.  
2. Submit waitlist with consent → status message → queue run → Mailhog shows confirmation email.  
3. Confirm link → `/waitlist/confirmed`; expired/invalid token → `/waitlist/invalid`.  
4. Unsubscribe link in email → `/waitlist/unsubscribed`.  
5. `/privacy` shows configurable privacy stub.  
6. `/sitemap.xml` returns two URLs.  
7. View source: canonical, meta description, OG/Twitter tags, JSON-LD present; `noindex` on local.  
8. `/admin/myeventlane/waitlist` shows stats; export CSV downloads.  

## License

GPL-2.0-or-later (Drupal core and contributed code). Custom theme and module are provided under the same license unless you specify otherwise.