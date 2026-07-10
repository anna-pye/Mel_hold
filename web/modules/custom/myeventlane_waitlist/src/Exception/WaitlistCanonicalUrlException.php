<?php

declare(strict_types=1);

namespace Drupal\myeventlane_waitlist\Exception;

/**
 * Thrown when an email would contain links to the placeholder "default" host.
 *
 * This happens when absolute URLs are generated in a CLI context (drush cron,
 * queue workers) without a canonical base URL configured — Drupal falls back
 * to http://default/... The queue worker maps this to a SuspendQueueException
 * because the problem is environment-wide: every queued email would be equally
 * broken, so items must be retained, not sent or discarded.
 *
 * Fix: set DRUSH_OPTIONS_URI for the CLI environment (see
 * deploy/production.env.example; DDEV sets it automatically).
 */
final class WaitlistCanonicalUrlException extends \RuntimeException {
}
