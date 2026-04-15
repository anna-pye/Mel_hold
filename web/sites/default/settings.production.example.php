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

// Ensure production mail transport is configured via hosting or Symfony Mailer.
