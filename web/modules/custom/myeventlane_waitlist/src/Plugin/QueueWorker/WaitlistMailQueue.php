<?php

declare(strict_types=1);

namespace Drupal\myeventlane_waitlist\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\myeventlane_waitlist\Service\WaitlistEmailManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Sends waitlist emails from the queue.
 *
 * @QueueWorker(
 *   id = "myeventlane_waitlist_mail",
 *   title = @Translation("MyEventLane waitlist mail"),
 *   cron = {"time" = 60}
 * )
 */
final class WaitlistMailQueue extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly WaitlistEmailManager $emailManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('myeventlane_waitlist.email_manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $data = is_array($data) ? $data : [];
    $op = $data['op'] ?? '';
    $sid = (int) ($data['subscriber_id'] ?? 0);
    if ($sid < 1) {
      return;
    }
    if ($op === 'confirmation') {
      $this->emailManager->sendConfirmationForSubscriber($sid);
    }
  }

}
