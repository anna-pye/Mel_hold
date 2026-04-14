<?php

/**
 * @file
 * Copy to settings.local.php for local overrides (gitignored).
 *
 * This file is loaded after settings.production.php (if present) and after
 * web/sites/default/settings.php environment variables.
 */

declare(strict_types=1);

// Trusted hosts for Docker Desktop hostnames.
$settings['trusted_host_patterns'] = [
  '^localhost$',
  '^127\.0\.0\.1$',
  '^myeventlane-hold\.docker\.localhost$',
  '^.*\.docker\.localhost$',
];

// Example: enable verbose errors (already typical via development.services.yml).
$config['system.logging']['error_level'] = 'verbose';

// Local: discourage indexing (theme also adds meta robots when MEL_ENVIRONMENT=local).
$config['system.performance']['cache']['page']['max_age'] = 0;

// Optional: if CSS/JS still fail to load, disable aggregation so Drupal serves
// files directly from core/modules and themes (easier debugging; slightly slower).
// $config['system.performance']['css']['preprocess'] = FALSE;
// $config['system.performance']['js']['preprocess'] = FALSE;
