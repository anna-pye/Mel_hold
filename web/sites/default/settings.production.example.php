<?php

/**
 * @file
 * Copy to settings.production.php on production servers (gitignored).
 *
 * Load order: settings.php → settings.production.php → settings.local.php
 */

declare(strict_types=1);

// Restrict request hostnames (recommended on production).
$settings['trusted_host_patterns'] = [
  '^myeventlane\.com\.au$',
  '^www\.myeventlane\.com\.au$',
];

// Page cache max-age (config/sync keeps 0 for local/dev; override on production).
$config['system.performance']['cache']['page']['max_age'] = 3600;

// Ensure production mail transport is configured via hosting or Symfony Mailer.
