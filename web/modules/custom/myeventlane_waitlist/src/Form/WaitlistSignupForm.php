<?php

declare(strict_types=1);

namespace Drupal\myeventlane_waitlist\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_waitlist\Service\WaitlistManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Waitlist signup form (embedded on front page or standalone).
 */
final class WaitlistSignupForm extends FormBase {

  public function __construct(
    private readonly WaitlistManager $waitlistManager,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_waitlist.manager'),
      $container->get('logger.channel.myeventlane_waitlist'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'waitlist_signup_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $request = $this->getRequest();
    $route = $this->getRouteMatch()->getRouteName();

    if ($route === 'myeventlane_waitlist.submit' && $request && !$request->isMethod('POST')) {
      $form_state->setResponse(new RedirectResponse(Url::fromRoute('<front>')->toString()));
      return $form;
    }

    $form['#action'] = Url::fromRoute('myeventlane_waitlist.submit')->toString();
    $form['#method'] = 'post';
    $form['#attributes']['class'][] = 'mel-waitlist-form';
    $form['#attributes']['novalidate'] = 'novalidate';

    $form['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Email'),
      '#title_display' => 'invisible',
      '#required' => TRUE,
      '#attributes' => [
        'placeholder' => $this->t('you@example.com'),
        'autocomplete' => 'email',
        'class' => ['mel-waitlist-form__input'],
      ],
    ];

    $form['website'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Leave blank'),
      '#attributes' => [
        'class' => ['mel-visually-hidden', 'mel-honeypot'],
        'tabindex' => '-1',
        'autocomplete' => 'off',
      ],
      '#wrapper_attributes' => ['class' => ['mel-honeypot-wrap']],
    ];

    $form['interest_type'] = [
      '#type' => 'hidden',
      '#value' => '',
    ];

    $form['utm_source'] = [
      '#type' => 'hidden',
      '#value' => $request?->query->get('utm_source', ''),
    ];
    $form['utm_medium'] = [
      '#type' => 'hidden',
      '#value' => $request?->query->get('utm_medium', ''),
    ];
    $form['utm_campaign'] = [
      '#type' => 'hidden',
      '#value' => $request?->query->get('utm_campaign', ''),
    ];
    $form['utm_term'] = [
      '#type' => 'hidden',
      '#value' => $request?->query->get('utm_term', ''),
    ];
    $form['utm_content'] = [
      '#type' => 'hidden',
      '#value' => $request?->query->get('utm_content', ''),
    ];

    $config = $this->config('myeventlane_waitlist.settings');
    $consent_label = $config->get('consent_text') ?: $this->t('I agree to be contacted about MyEventLane and accept the privacy terms.');

    $form['consent'] = [
      '#type' => 'checkbox',
      '#title' => $consent_label,
      '#required' => TRUE,
      '#attributes' => ['class' => ['mel-waitlist-form__consent']],
    ];

    $form['form_variant'] = [
      '#type' => 'hidden',
      '#value' => $route === 'myeventlane_waitlist.subscribe' ? 'standalone' : 'embedded',
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Notify me'),
      '#attributes' => ['class' => ['mel-btn', 'mel-btn--primary']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $honeypot = trim((string) $form_state->getValue('website'));
    if ($honeypot !== '') {
      $this->logger->notice('Waitlist honeypot triggered.');
      $form_state->set('honeypot_tripped', TRUE);
      return;
    }
    if (!$form_state->getValue('consent')) {
      $form_state->setErrorByName('consent', $this->t('Please accept the consent to continue.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if ($form_state->get('honeypot_tripped')) {
      $this->messenger()->addStatus($this->t('Thanks. If your address can be added, you will receive a confirmation message shortly.'));
      $form_state->setRedirect('<front>');
      return;
    }
    $email = (string) $form_state->getValue('email');
    $interest = trim((string) $form_state->getValue('interest_type'));
    $interest = $interest === '' ? NULL : substr($interest, 0, 64);
    $request = $this->getRequest();
    if (!$request) {
      $this->logger->error('Missing request during waitlist submit.');
      return;
    }

    foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'] as $k) {
      $v = $form_state->getValue($k);
      if ($v !== NULL && $v !== '') {
        $request->query->set($k, $v);
      }
    }

    $variant = (string) $form_state->getValue('form_variant');
    $this->waitlistManager->processSignupRequest(
      $email,
      (bool) $form_state->getValue('consent'),
      $interest,
      $request,
      $variant
    );

    $this->messenger()->addStatus($this->t('Thanks. If your address can be added, you will receive a confirmation message shortly.'));
    $form_state->setRedirect('<front>');
  }

}
