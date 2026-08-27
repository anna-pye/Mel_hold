<?php

declare(strict_types=1);

namespace Drupal\myeventlane_waitlist\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\EnforcedResponseException;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_waitlist\Service\WaitlistManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Waitlist signup form.
 *
 * Renders in two modes. 'organiser' asks the qualifying questions we need
 * before a conversation is worth having. 'attendee' asks for an email only.
 */
final class WaitlistSignupForm extends FormBase {

  public const AUDIENCE_ORGANISER = 'organiser';

  public const AUDIENCE_ATTENDEE = 'attendee';

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
   * Event types offered to organisers.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Event type labels keyed by their stored machine value.
   */
  public function eventTypeOptions(): array {
    return [
      'market' => $this->t('Market or fair'),
      'gig' => $this->t('Local gig or performance'),
      'workshop' => $this->t('Workshop or class'),
      'fundraiser' => $this->t('Community fundraiser or fete'),
      'other' => $this->t('Something else'),
    ];
  }

  /**
   * {@inheritdoc}
   *
   * @param array $form
   *   The form render array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state.
   * @param string $audience
   *   Either 'organiser' or 'attendee'.
   */
  public function buildForm(array $form, FormStateInterface $form_state, string $audience = self::AUDIENCE_ATTENDEE): array {
    $request = $this->getRequest();
    $route = $this->getRouteMatch()->getRouteName();

    if ($route === 'myeventlane_waitlist.submit' && $request && !$request->isMethod('POST')) {
      throw new EnforcedResponseException(
        new RedirectResponse(Url::fromRoute('<front>')->toString()),
      );
    }

    // The form posts to myeventlane_waitlist.submit, a _form route that
    // rebuilds this class WITHOUT the audience argument. Recover it from the
    // POST body, or the rebuild defaults to 'attendee' and every organiser
    // field is dropped before submitForm() ever sees it.
    // is_scalar guards against interest_type[]=x, which would otherwise make
    // InputBag::get() throw a BadRequestException and return HTTP 400.
    if ($route === 'myeventlane_waitlist.submit' && $request?->isMethod('POST')) {
      $raw = $request->request->all()['interest_type'] ?? '';
      if (is_scalar($raw) && (string) $raw !== '') {
        $audience = (string) $raw;
      }
    }

    $audience = $audience === self::AUDIENCE_ORGANISER
      ? self::AUDIENCE_ORGANISER
      : self::AUDIENCE_ATTENDEE;
    $isOrganiser = $audience === self::AUDIENCE_ORGANISER;

    // Distinct DOM id so two instances can sit on one page.
    $form['#id'] = 'mel2-signup-' . $audience;
    $form['#action'] = Url::fromRoute('myeventlane_waitlist.submit')->toString();
    $form['#method'] = 'post';
    // Keep mel-waitlist-form: /waitlist/subscribe renders this same form under
    // page.html.twig, which uses the original .mel-* stylesheet.
    $form['#attributes']['class'][] = 'mel-waitlist-form';
    $form['#attributes']['class'][] = 'mel2-form';
    $form['#attributes']['class'][] = 'mel2-form--' . $audience;
    $form['#attributes']['novalidate'] = 'novalidate';

    if ($isOrganiser) {
      $form['organisation'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Your organisation'),
        '#required' => TRUE,
        '#maxlength' => 255,
        '#attributes' => [
          'placeholder' => $this->t('Preston Makers Market'),
          'autocomplete' => 'organization',
          'class' => ['mel2-input'],
        ],
      ];

      $form['first_name'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Your name'),
        '#maxlength' => 128,
        '#attributes' => [
          'autocomplete' => 'name',
          'class' => ['mel2-input'],
        ],
      ];
    }

    $form['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Email address'),
      '#required' => TRUE,
      '#attributes' => [
        'placeholder' => $this->t('you@example.org.au'),
        'autocomplete' => 'email',
        'class' => ['mel2-input'],
      ],
    ];

    if ($isOrganiser) {
      // Deliberately NOT #required. See the brief's required-fields decision.
      $form['event_type'] = [
        '#type' => 'select',
        '#title' => $this->t('What sort of event do you run?'),
        '#empty_option' => $this->t('- Choose one -'),
        '#options' => $this->eventTypeOptions(),
        '#attributes' => ['class' => ['mel2-input']],
      ];

      $form['next_event_date'] = [
        '#type' => 'date',
        '#title' => $this->t('Date of your next event'),
        '#description' => $this->t('An approximate date is fine.'),
        '#attributes' => ['class' => ['mel2-input']],
      ];

      $form['current_platform'] = [
        '#type' => 'textfield',
        '#title' => $this->t('What do you use now?'),
        '#maxlength' => 128,
        '#attributes' => [
          'placeholder' => $this->t('Eventbrite, a Google Form, nothing yet'),
          'class' => ['mel2-input'],
        ],
      ];

      $form['audience_size'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Roughly how many people can you reach?'),
        '#description' => $this->t('Your mailing list, members or regulars.'),
        '#maxlength' => 64,
        '#attributes' => [
          'placeholder' => $this->t('About 400 on our mailing list'),
          'class' => ['mel2-input'],
        ],
      ];
    }

    // Honeypot. Unchanged.
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
      '#value' => $audience,
    ];

    foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'] as $key) {
      $form[$key] = [
        '#type' => 'hidden',
        '#value' => $request?->query->get($key, ''),
      ];
    }

    $config = $this->config('myeventlane_waitlist.settings');
    $consent_label = $config->get('consent_text') ?: $this->t('I agree to be contacted about MyEventLane and accept the privacy terms.');

    $form['consent'] = [
      '#type' => 'checkbox',
      '#title' => $consent_label,
      '#required' => TRUE,
      '#attributes' => ['class' => ['mel2-consent']],
    ];

    $form['form_variant'] = [
      '#type' => 'hidden',
      '#value' => ($route === 'myeventlane_waitlist.subscribe' ? 'standalone_' : 'embedded_') . $audience,
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $isOrganiser
        ? $this->t('Send my details')
        : $this->t('Notify me at launch'),
      '#attributes' => [
        'class' => ['mel2-btn', $isOrganiser ? 'mel2-btn--primary' : 'mel2-btn--quiet'],
      ],
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
      $form_state->setErrorByName('consent', $this->t('Please tick the consent box to continue.'));
    }

    // '#type' => date has no valueCallback in core, so the submitted value is
    // the raw POST string. Enforce the format we claim to store.
    $date = trim((string) $form_state->getValue('next_event_date'));
    if ($date !== '') {
      if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $form_state->setErrorByName('next_event_date', $this->t('Please give the date as YYYY-MM-DD.'));
      }
      elseif (!checkdate(
        (int) substr($date, 5, 2),
        (int) substr($date, 8, 2),
        (int) substr($date, 0, 4),
      )) {
        $form_state->setErrorByName('next_event_date', $this->t('That does not look like a real date.'));
      }
      elseif (strtotime($date) < strtotime('today')) {
        $form_state->setErrorByName('next_event_date', $this->t('Please give a date in the future.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Neutral response. Never reveal whether the address was accepted.
    $neutral = $this->t('Thanks. If your address can be added, you will receive a confirmation message shortly.');

    if ($form_state->get('honeypot_tripped')) {
      $this->messenger()->addStatus($neutral);
      $form_state->setRedirect('<front>');
      return;
    }

    $request = $this->getRequest();
    if (!$request) {
      $this->logger->error('Missing request during waitlist submit.');
      return;
    }

    $interest = trim((string) $form_state->getValue('interest_type'));
    $interest = $interest === '' ? NULL : mb_substr($interest, 0, 64);

    $profile = [
      'first_name' => (string) $form_state->getValue('first_name'),
      'organisation' => (string) $form_state->getValue('organisation'),
      'event_type' => (string) $form_state->getValue('event_type'),
      'next_event_date' => (string) $form_state->getValue('next_event_date'),
      'audience_size' => (string) $form_state->getValue('audience_size'),
      'current_platform' => (string) $form_state->getValue('current_platform'),
    ];

    foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'] as $k) {
      $v = $form_state->getValue($k);
      if ($v !== NULL && $v !== '') {
        $request->query->set($k, $v);
      }
    }

    $this->waitlistManager->processSignupRequest(
      (string) $form_state->getValue('email'),
      (bool) $form_state->getValue('consent'),
      $interest,
      $request,
      (string) $form_state->getValue('form_variant'),
      $profile,
    );

    $this->messenger()->addStatus($neutral);
    $form_state->setRedirect('<front>');
  }

}
