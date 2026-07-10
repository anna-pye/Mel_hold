<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_waitlist\Kernel;

use Drupal\Core\Queue\SuspendQueueException;
use Drupal\KernelTests\KernelTestBase;
use Drupal\myeventlane_waitlist\Exception\WaitlistCanonicalUrlException;
use Drupal\myeventlane_waitlist\Plugin\QueueWorker\WaitlistMailQueue;
use Symfony\Component\HttpFoundation\Request;

/**
 * End-to-end kernel coverage for the waitlist email workflow.
 *
 * Covers: queue creation, queue processing, email delivery, confirmation and
 * unsubscribe tokens, generated URLs, the http://default guard, and failure
 * handling (failed sends must throw so the queue retains the item).
 *
 * @group myeventlane_waitlist
 */
final class WaitlistEmailFlowTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'myeventlane_waitlist',
    'myeventlane_waitlist_mailfail',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('myeventlane_waitlist', [
      'myeventlane_waitlist_subscriber',
      'myeventlane_waitlist_event',
    ]);
    $this->installConfig(['myeventlane_waitlist']);
    $this->setSetting('myeventlane_waitlist.token_secret', 'kernel-test-secret');
  }

  /**
   * Signing up creates a pending subscriber and queues one confirmation item.
   */
  public function testSignupCreatesSubscriberAndQueuesConfirmation(): void {
    $request = Request::create('http://localhost/waitlist/subscribe', 'POST');

    $result = $this->container->get('myeventlane_waitlist.manager')
      ->processSignupRequest('kernel@example.com', TRUE, 'organiser', $request, 'test');
    $this->assertSame('waitlist_neutral', $result);

    $row = $this->loadSubscriber('kernel@example.com');
    $this->assertNotEmpty($row);
    $this->assertSame('pending', $row->status);

    $queue = $this->container->get('queue')->get('myeventlane_waitlist_mail');
    $this->assertSame(1, $queue->numberOfItems());
    $item = $queue->claimItem();
    $this->assertSame('confirmation', $item->data['op']);
    $this->assertSame((int) $row->id, (int) $item->data['subscriber_id']);
  }

  /**
   * Happy path: canonical URLs, stored token hashes, confirm + unsubscribe.
   */
  public function testConfirmationEmailDeliveryAndTokens(): void {
    $id = $this->createPendingSubscriber('flow@example.com');

    $this->container->get('myeventlane_waitlist.email_manager')
      ->sendConfirmationForSubscriber($id);

    $mails = $this->container->get('state')->get('system.test_mail_collector') ?? [];
    $this->assertCount(1, $mails);
    $mail = end($mails);
    $this->assertSame('myeventlane_waitlist_waitlist_confirm', $mail['id']);
    $this->assertSame('flow@example.com', $mail['to']);

    $body = (string) $mail['body'];
    $this->assertStringNotContainsString('http://default', $body, 'No email may ever contain http://default links.');

    // Extract the raw tokens from the links and prove they match the stored
    // hashes (link <-> database consistency).
    $this->assertSame(1, preg_match('~/waitlist/confirm/([0-9a-f]{64})~', $body, $confirm));
    $this->assertSame(1, preg_match('~/waitlist/unsubscribe/([0-9a-f]{64})~', $body, $unsub));
    $this->assertStringContainsString('://localhost/waitlist/confirm/', $body, 'Links use the canonical base URL.');

    $tokens = $this->container->get('myeventlane_waitlist.token_manager');
    $row = $this->loadSubscriber('flow@example.com');
    $this->assertTrue($tokens->tokensMatch($confirm[1], $row->confirm_token));
    $this->assertTrue($tokens->tokensMatch($unsub[1], $row->unsubscribe_token));
    $this->assertNotEmpty($row->last_sent_at, 'A successful send records last_sent_at.');

    // The emailed links actually work.
    $manager = $this->container->get('myeventlane_waitlist.manager');
    $this->assertSame('ok', $manager->confirmByRawToken($confirm[1]));
    $this->assertSame('confirmed', $this->loadSubscriber('flow@example.com')->status);
    $this->assertSame('ok', $manager->unsubscribeByRawToken($unsub[1]));
    $this->assertSame('unsubscribed', $this->loadSubscriber('flow@example.com')->status);
  }

  /**
   * With no canonical base URL (host "default") nothing is sent or mutated.
   */
  public function testPlaceholderHostBlocksSend(): void {
    $id = $this->createPendingSubscriber('blocked@example.com');
    $this->container->get('router.request_context')->setHost('default');

    $thrown = NULL;
    try {
      $this->container->get('myeventlane_waitlist.email_manager')
        ->sendConfirmationForSubscriber($id);
    }
    catch (WaitlistCanonicalUrlException $e) {
      $thrown = $e;
    }

    $this->assertInstanceOf(WaitlistCanonicalUrlException::class, $thrown);
    $mails = $this->container->get('state')->get('system.test_mail_collector') ?? [];
    $this->assertCount(0, $mails, 'No email is sent with placeholder-host links.');
    $row = $this->loadSubscriber('blocked@example.com');
    $this->assertNull($row->confirm_token, 'Nothing was mutated before the guard fired.');
    $this->assertNull($row->last_sent_at);
  }

  /**
   * The queue worker suspends the whole queue on a placeholder-host failure.
   */
  public function testQueueWorkerSuspendsOnPlaceholderHost(): void {
    $id = $this->createPendingSubscriber('suspend@example.com');
    $this->container->get('router.request_context')->setHost('default');

    $worker = WaitlistMailQueue::create($this->container, [], 'myeventlane_waitlist_mail', []);
    $this->expectException(SuspendQueueException::class);
    $worker->processItem(['op' => 'confirmation', 'subscriber_id' => $id]);
  }

  /**
   * A transport failure throws (so core requeues) and never fakes success.
   */
  public function testTransportFailureThrowsAndStaysTruthful(): void {
    $id = $this->createPendingSubscriber('fail@example.com');
    // KernelTestBase forces the test mail collector via a $GLOBALS['config']
    // runtime override, which beats saved config — so use the same mechanism.
    $GLOBALS['config']['system.mail']['interface']['default'] = 'waitlist_failing_mail';
    $this->container->get('config.factory')->reset('system.mail');

    $thrown = NULL;
    try {
      $this->container->get('myeventlane_waitlist.email_manager')
        ->sendConfirmationForSubscriber($id);
    }
    catch (\RuntimeException $e) {
      $thrown = $e;
    }

    $this->assertInstanceOf(\RuntimeException::class, $thrown);
    $row = $this->loadSubscriber('fail@example.com');
    $this->assertNull($row->last_sent_at, 'A failed send must not record last_sent_at.');
  }

  /**
   * Inserts a minimal pending subscriber row directly.
   */
  private function createPendingSubscriber(string $email): int {
    return (int) $this->container->get('database')
      ->insert('myeventlane_waitlist_subscriber')
      ->fields([
        'email' => $email,
        'email_normalized' => strtolower($email),
        'status' => 'pending',
        'consent_given' => 1,
        'double_opt_in_required' => 1,
        'created' => time(),
        'changed' => time(),
      ])
      ->execute();
  }

  /**
   * Loads a subscriber row by email.
   */
  private function loadSubscriber(string $email): object|false {
    return $this->container->get('database')
      ->select('myeventlane_waitlist_subscriber', 's')
      ->fields('s')
      ->condition('email', $email)
      ->execute()
      ->fetchObject();
  }

}
