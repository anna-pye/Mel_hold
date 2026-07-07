<?php

declare(strict_types=1);

namespace Drupal\myeventlane_analytics\Value;

use Drupal\Component\Render\MarkupInterface;

/**
 * One immutable operational status check for the Analytics Status dashboard.
 *
 * A check is pure data: it carries a machine id, a human label, a severity
 * state, a one-line summary, and zero or more label/value detail rows. It
 * holds no rendering or business logic — PlatformStatusService builds these
 * from the existing analytics services and StatusController renders them.
 */
final class StatusCheck {

  /**
   * Configured and operational — counts as passing.
   */
  public const OK = 'ok';

  /**
   * Purely informational (e.g. environment, counts) — counts as passing.
   */
  public const INFO = 'info';

  /**
   * A non-critical integration is missing or incomplete.
   */
  public const WARNING = 'warning';

  /**
   * A required integration is broken or a health signal is failing.
   */
  public const CRITICAL = 'critical';

  /**
   * @param string $id
   *   Machine name, e.g. 'google_analytics'.
   * @param string|MarkupInterface $label
   *   Human-readable card title.
   * @param string $state
   *   One of the OK/INFO/WARNING/CRITICAL constants.
   * @param string|MarkupInterface $summary
   *   One-line status, e.g. 'Configured' or 'Not configured'.
   * @param array<int, array{label: string|MarkupInterface, value: string|MarkupInterface}> $details
   *   Optional label/value rows shown beneath the summary.
   * @param string|null $route
   *   Optional route name the card links to (e.g. the integration's settings
   *   page). When set, the whole card becomes clickable. No secret is ever
   *   passed as a route argument.
   */
  public function __construct(
    public readonly string $id,
    public readonly string|MarkupInterface $label,
    public readonly string $state,
    public readonly string|MarkupInterface $summary,
    public readonly array $details = [],
    public readonly ?string $route = NULL,
  ) {}

  /**
   * Whether this check contributes to the "passing" count.
   */
  public function isPassing(): bool {
    return $this->state === self::OK || $this->state === self::INFO;
  }

}
