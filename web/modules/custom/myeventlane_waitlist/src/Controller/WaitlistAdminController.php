<?php

declare(strict_types=1);

namespace Drupal\myeventlane_waitlist\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Admin dashboard for waitlist metrics.
 */
final class WaitlistAdminController extends ControllerBase {

  public function __construct(
    private readonly Connection $database,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database')
    );
  }

  public function dashboard(): array {
    $counts = [];
    foreach (['pending', 'confirmed', 'unsubscribed'] as $status) {
      $counts[$status] = (int) $this->database->select('myeventlane_waitlist_subscriber', 's')
        ->condition('status', $status)
        ->countQuery()
        ->execute()
        ->fetchField();
    }

    $total = array_sum($counts);
    $confirmed = $counts['confirmed'];
    $pending = $counts['pending'];
    $denom = $confirmed + $pending;
    $rate = $denom > 0 ? round(100 * $confirmed / $denom, 1) : 0.0;

    $recent = $this->database->select('myeventlane_waitlist_subscriber', 's')
      ->fields('s', ['id', 'email', 'status', 'created', 'utm_source', 'utm_campaign'])
      ->orderBy('created', 'DESC')
      ->range(0, 25)
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);

    $rows = [];
    foreach ($recent as $row) {
      $rows[] = [
        $row['id'],
        $row['email'],
        $row['status'],
        date('Y-m-d H:i', (int) $row['created']),
        $row['utm_source'] ?? '',
        $row['utm_campaign'] ?? '',
      ];
    }

    $top_sources = $this->database->query(
      "SELECT utm_source, COUNT(*) FROM {myeventlane_waitlist_subscriber}
       WHERE utm_source IS NOT NULL AND utm_source <> ''
       GROUP BY utm_source ORDER BY COUNT(*) DESC LIMIT 10"
    )->fetchAllKeyed();

    $top_campaigns = $this->database->query(
      "SELECT utm_campaign, COUNT(*) FROM {myeventlane_waitlist_subscriber}
       WHERE utm_campaign IS NOT NULL AND utm_campaign <> ''
       GROUP BY utm_campaign ORDER BY COUNT(*) DESC LIMIT 10"
    )->fetchAllKeyed();

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-admin-waitlist']],
    ];

    $build['actions'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['mel-admin-waitlist__actions'],
        'style' => 'margin-bottom: 1.25rem;',
      ],
      '#weight' => -20,
      'export' => [
        '#type' => 'link',
        '#title' => $this->t('Download CSV'),
        '#url' => Url::fromRoute('myeventlane_waitlist.admin_export'),
        '#attributes' => [
          'class' => ['button', 'button--primary', 'button--small'],
        ],
      ],
    ];

    $build['summary'] = [
      '#type' => 'inline_template',
      '#weight' => -10,
      '#template' => '
        <div class="mel-admin-waitlist__stats">
          <p><strong>{{ "Total"|t }}:</strong> {{ total }}</p>
          <p><strong>{{ "Pending"|t }}:</strong> {{ pending }} — <strong>{{ "Confirmed"|t }}:</strong> {{ confirmed }} — <strong>{{ "Unsubscribed"|t }}:</strong> {{ unsub }}</p>
          <p><strong>{{ "Confirmation rate (confirmed / confirmed+pending)"|t }}:</strong> {{ rate }}%</p>
        </div>',
      '#context' => [
        'total' => $total,
        'pending' => $pending,
        'confirmed' => $confirmed,
        'unsub' => $counts['unsubscribed'],
        'rate' => $rate,
      ],
    ];

    $build['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('ID'),
        $this->t('Email'),
        $this->t('Status'),
        $this->t('Created'),
        $this->t('utm_source'),
        $this->t('utm_campaign'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No signups yet.'),
    ];

    $source_items = [];
    foreach ($top_sources as $label => $c) {
      $source_items[] = $label . ' (' . $c . ')';
    }
    $build['sources'] = [
      '#theme' => 'item_list',
      '#title' => $this->t('Top sources'),
      '#items' => $source_items,
      '#empty' => $this->t('No UTM sources recorded.'),
    ];

    $campaign_items = [];
    foreach ($top_campaigns as $label => $c) {
      $campaign_items[] = $label . ' (' . $c . ')';
    }
    $build['campaigns'] = [
      '#theme' => 'item_list',
      '#title' => $this->t('Top campaigns'),
      '#items' => $campaign_items,
      '#empty' => $this->t('No UTM campaigns recorded.'),
    ];

    $build['#cache'] = [
      'max-age' => 60,
    ];

    return $build;
  }

}
