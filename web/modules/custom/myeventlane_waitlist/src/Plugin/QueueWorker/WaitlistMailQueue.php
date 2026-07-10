<?php

declare(strict_types=1);

namespace Drupal\myeventlane_waitlist\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\myeventlane_waitlist\Exception\WaitlistCanonicalUrlException;
use Drupal\myeventlane_waitlist\Service\WaitlistEmailManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Sends waitlist emails from the queue.
 *
 * Failure semantics (deliberate — do not "simplify" back to fire-and-forget):
 * - A thrown exception means core RELEASES the item for retry on the next
 *   cron run. Returning normally deletes the item, so swallowing a failure
 *   silently loses the subscriber's email.
 * - WaitlistCanonicalUrlException is environment-wide (every item would fail
 *   identically with http://default links), so it suspends the whole queue.
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
    private readonly LoggerInterface $logger,
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
      $container->get('myeventlane_waitlist.email_manager'),
      $container->get('logger.channel.myeventlane_waitlist'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $data = is_array($data) ? $data : [];
    $op = (string) ($data['op'] ?? '');
    $sid = (int) ($data['subscriber_id'] ?? 0);
    if ($sid < 1) {
      return;
    }
    if ($op !== 'confirmation') {
      $this->logger->warning('Waitlist queue: unknown op @op for subscriber @id — item discarded.', [
        '@op' => $op,
        '@id' => $sid,
      ]);
      return;
    }

    try {
      $this->emailManager->sendConfirmationForSubscriber($sid);
    }
    catch (WaitlistCanonicalUrlException $e) {
      // Environment problem (no canonical base URL): every item would fail
      // the same way. Suspend the queue so ALL items are kept for retry.
      throw new SuspendQueueException($e->getMessage(), 0, $e);
    }
    catch (\Throwable $e) {
      $this->logger->error('Waitlist queue item failed and will be retried: op=@op subscriber=@id exception=@type message=@message trace=@trace', [
        '@op' => $op,
        '@id' => $sid,
        '@type' => $e::class,
        '@message' => $e->getMessage(),
        '@trace' => $e->getTraceAsString(),
      ]);
      // Rethrow: core releases (requeues) the item instead of deleting it.
      throw $e;
    }
  }

}
