<?php

/**
 * @file
 * Copy to settings.production.php on production servers (gitignored).
 *
 * Load order: settings.php → settings.production.php → settings.local.php
 */

declare(strict_types=1);

// Example: restrict hosts to your apex domain.
// $settings['trusted_host_patterns'] = [
//   '^example\.com$',
//   '^www\.example\.com$',
// ];

// Ensure production mail transport is configured via hosting or Symfony Mailer.
