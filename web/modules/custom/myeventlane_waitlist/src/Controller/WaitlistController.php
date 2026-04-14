<?php

declare(strict_types=1);

namespace Drupal\myeventlane_waitlist\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\myeventlane_waitlist\Service\WaitlistManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Public waitlist confirmation and status pages.
 */
final class WaitlistController extends ControllerBase {

  public function __construct(
    private readonly WaitlistManager $waitlistManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_waitlist.manager')
    );
  }

  public function confirm(string $token): RedirectResponse {
    $result = $this->waitlistManager->confirmByRawToken($token);
    if ($result === 'ok') {
      return new RedirectResponse(Url::fromRoute('myeventlane_waitlist.page_confirmed')->toString());
    }
    return new RedirectResponse(Url::fromRoute('myeventlane_waitlist.page_invalid')->toString());
  }

  public function unsubscribe(string $token): RedirectResponse {
    $result = $this->waitlistManager->unsubscribeByRawToken($token);
    if ($result === 'ok') {
      return new RedirectResponse(Url::fromRoute('myeventlane_waitlist.page_unsubscribed')->toString());
    }
    return new RedirectResponse(Url::fromRoute('myeventlane_waitlist.page_invalid')->toString());
  }

  public function pageConfirmed(): array {
    return [
      '#theme' => 'mel_waitlist_status',
      'status' => 'confirmed',
      'title_text' => $this->t('You are on the list'),
      'message_text' => $this->t('Your email is confirmed. We will share updates when MyEventLane is ready.'),
      '#cache' => ['max-age' => 0],
    ];
  }

  public function pageInvalid(): array {
    return [
      '#theme' => 'mel_waitlist_status',
      'status' => 'invalid',
      'title_text' => $this->t('Link not valid'),
      'message_text' => $this->t('This confirmation link is no longer valid. You can join again from the home page.'),
      '#cache' => ['max-age' => 0],
    ];
  }

  public function pageUnsubscribed(): array {
    return [
      '#theme' => 'mel_waitlist_status',
      'status' => 'unsubscribed',
      'title_text' => $this->t('Unsubscribed'),
      'message_text' => $this->t('You have been removed from the waitlist.'),
      '#cache' => ['max-age' => 0],
    ];
  }

}
