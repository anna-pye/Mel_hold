<?php

declare(strict_types=1);

namespace Drupal\myeventlane_analytics\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\myeventlane_analytics\Service\PlatformStatusService;
use Drupal\myeventlane_analytics\Value\StatusCheck;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders the Analytics Status operational dashboard.
 *
 * Presentation only: all state is gathered by PlatformStatusService. The page
 * is intentionally glanceable and framework-free (no charts, no JS) so it can
 * later become the platform's operational home screen.
 */
final class StatusController extends ControllerBase {

  public function __construct(
    private readonly PlatformStatusService $platformStatus,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_analytics.platform_status'),
    );
  }

  /**
   * Builds the status dashboard render array.
   *
   * @return array<string, mixed>
   */
  public function dashboard(): array {
    $checks = $this->platformStatus->getChecks();
    $summary = $this->platformStatus->getSummary($checks);

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-status']],
      '#attached' => ['library' => ['myeventlane_analytics/analytics_status']],
      // Live operational state — never serve a cached snapshot.
      '#cache' => ['max-age' => 0],
    ];

    $build['summary'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['mel-status__summary', 'mel-status__summary--' . $summary['overall']],
        'role' => 'status',
      ],
      'heading' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('Analytics health'),
        '#attributes' => ['class' => ['mel-status__summary-heading']],
      ],
      'badge' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#attributes' => ['class' => ['mel-status__badge']],
        '#value' => $this->t('<span class="mel-status__badge-emoji" aria-hidden="true">@emoji</span> @label', [
          '@emoji' => $summary['emoji'],
          '@label' => $summary['label'],
        ]),
      ],
      'count' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#attributes' => ['class' => ['mel-status__count']],
        '#value' => $this->t('@passing / @total checks passing', [
          '@passing' => $summary['passing'],
          '@total' => $summary['total'],
        ]),
      ],
      'checked' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#attributes' => ['class' => ['mel-status__checked']],
        '#value' => $this->t('Last checked: @time', ['@time' => $summary['checked']]),
      ],
    ];

    $build['cards'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-status__cards']],
    ];
    foreach ($checks as $check) {
      $build['cards'][$check->id] = $this->buildCard($check);
    }

    return $build;
  }

  /**
   * Builds one status card.
   *
   * @return array<string, mixed>
   */
  private function buildCard(StatusCheck $check): array {
    $details = [];
    foreach ($check->details as $detail) {
      $details[] = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => ['class' => ['mel-status__detail']],
        'label' => [
          '#type' => 'html_tag',
          '#tag' => 'dt',
          '#value' => $detail['label'],
          '#attributes' => ['class' => ['mel-status__detail-label']],
        ],
        'value' => [
          '#type' => 'html_tag',
          '#tag' => 'dd',
          '#value' => $detail['value'],
          '#attributes' => ['class' => ['mel-status__detail-value']],
        ],
      ];
    }

    $classes = ['mel-status__card', 'mel-status__card--' . $check->state];

    // When a route is set the whole card is clickable: the card title holds
    // the single real link and CSS stretches its hit area over the card, so
    // there is exactly one, properly-labelled link per card (WCAG 2.4.4 /
    // 4.1.2) rather than a giant opaque anchor wrapping everything.
    if ($check->route !== NULL) {
      $classes[] = 'mel-status__card--clickable';
      $title = [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#attributes' => ['class' => ['mel-status__card-title']],
        'link' => [
          '#type' => 'link',
          '#title' => $check->label,
          '#url' => Url::fromRoute($check->route),
          '#attributes' => ['class' => ['mel-status__card-link']],
        ],
      ];
    }
    else {
      $title = [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#value' => $check->label,
        '#attributes' => ['class' => ['mel-status__card-title']],
      ];
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => $classes],
      'header' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-status__card-header']],
        'title' => $title,
        'state' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#attributes' => [
            'class' => ['mel-status__pill', 'mel-status__pill--' . $check->state],
          ],
          // State is conveyed by the visible summary text below and this
          // label — not by colour alone (WCAG 1.4.1).
          '#value' => $check->summary,
        ],
      ],
      'details' => $details === [] ? [] : [
        '#type' => 'html_tag',
        '#tag' => 'dl',
        '#attributes' => ['class' => ['mel-status__details']],
        'items' => $details,
      ],
    ];
  }

}
