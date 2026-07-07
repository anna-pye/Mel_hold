<?php

declare(strict_types=1);

namespace Drupal\myeventlane_analytics\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\StorageComparer;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\myeventlane_analytics\Value\StatusCheck;
use Drupal\simple_sitemap\Entity\SimpleSitemap;
use Drupal\simple_sitemap\Manager\Generator;
use Psr\Log\LoggerInterface;

/**
 * Operational health aggregator for the Analytics Status dashboard.
 *
 * This is an interpretation layer, not a data layer: it never queries a
 * business table or an external API itself. It composes the existing
 * read-only services (AnalyticsService, WaitlistAnalyticsService,
 * PlatformHealthService) and stable core APIs (config, settings, mailsystem,
 * automated_cron, config sync storage) into severity-graded StatusCheck
 * value objects plus one overall summary. No secret value (Postmark API key,
 * Search Console token) is ever placed in a check.
 */
final class PlatformStatusService {

  use StringTranslationTrait;

  /**
   * Overall platform badge states.
   */
  public const HEALTHY = 'healthy';
  public const WARNINGS = 'warnings';
  public const CRITICAL = 'critical';

  public function __construct(
    private readonly AnalyticsService $analytics,
    private readonly WaitlistAnalyticsService $waitlistAnalytics,
    private readonly PlatformHealthService $platformHealth,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly Settings $settings,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly TimeInterface $time,
    private readonly StorageInterface $activeStorage,
    private readonly StorageInterface $syncStorage,
    private readonly LoggerInterface $logger,
    private readonly string $appRoot,
    private readonly ?Generator $sitemapGenerator = NULL,
  ) {}

  /**
   * All status checks, in the order they should appear on the dashboard.
   *
   * @return array<int, StatusCheck>
   */
  public function getChecks(): array {
    return [
      $this->checkVersion(),
      $this->checkEnvironment(),
      $this->checkGoogleAnalytics(),
      $this->checkSearchConsole(),
      $this->checkPostmark(),
      $this->checkEmail(),
      $this->checkWaitlist(),
      $this->checkCron(),
      $this->checkSitemap(),
      $this->checkRobots(),
      $this->checkConfig(),
    ];
  }

  /**
   * Overall platform health rolled up from every check.
   *
   * @param array<int, StatusCheck>|null $checks
   *   Pre-fetched checks to roll up. When NULL they are fetched here; callers
   *   that already hold the list (e.g. the controller) should pass it so the
   *   checks are not evaluated twice per request.
   *
   * @return array{overall: string, emoji: string, label: \Drupal\Core\StringTranslation\TranslatableMarkup, passing: int, total: int, checked: string}
   */
  public function getSummary(?array $checks = NULL): array {
    $checks ??= $this->getChecks();
    $total = count($checks);
    $checked = $this->dateFormatter->format($this->time->getRequestTime(), 'short');
    $passing = 0;
    $hasWarning = FALSE;
    $hasCritical = FALSE;
    foreach ($checks as $check) {
      if ($check->isPassing()) {
        $passing++;
      }
      if ($check->state === StatusCheck::WARNING) {
        $hasWarning = TRUE;
      }
      if ($check->state === StatusCheck::CRITICAL) {
        $hasCritical = TRUE;
      }
    }

    if ($hasCritical) {
      return [
        'overall' => self::CRITICAL,
        'emoji' => '🔴',
        'label' => $this->t('Critical'),
        'passing' => $passing,
        'total' => $total,
        'checked' => $checked,
      ];
    }
    if ($hasWarning) {
      return [
        'overall' => self::WARNINGS,
        'emoji' => '🟡',
        'label' => $this->t('Needs attention'),
        'passing' => $passing,
        'total' => $total,
        'checked' => $checked,
      ];
    }
    return [
      'overall' => self::HEALTHY,
      'emoji' => '🟢',
      'label' => $this->t('Healthy'),
      'passing' => $passing,
      'total' => $total,
      'checked' => $checked,
    ];
  }

  private function checkVersion(): StatusCheck {
    $environment = (string) ($this->settings->get('mel.environment') ?: 'local');
    // Deployment metadata only — never a runtime `git` call. The deploy
    // pipeline sets MEL_GIT_COMMIT, which settings.php maps to this setting.
    $commit = trim((string) ($this->settings->get('mel.deploy.commit') ?: ''));
    $shortCommit = $commit !== '' ? substr($commit, 0, 12) : $this->t('Not recorded');

    return new StatusCheck(
      'version',
      $this->t('Version'),
      StatusCheck::INFO,
      $this->t('Drupal @version', ['@version' => \Drupal::VERSION]),
      [
        ['label' => $this->t('Drupal core'), 'value' => \Drupal::VERSION],
        ['label' => $this->t('Environment'), 'value' => ucfirst($environment)],
        ['label' => $this->t('Deployed commit'), 'value' => $shortCommit],
      ],
    );
  }

  private function checkEnvironment(): StatusCheck {
    $environment = (string) ($this->settings->get('mel.environment') ?: 'local');
    return new StatusCheck(
      'environment',
      $this->t('Environment'),
      StatusCheck::INFO,
      ucfirst($environment),
      [
        [
          'label' => $this->t('Analytics tracking'),
          'value' => $environment === 'local'
            ? $this->t('Suppressed locally')
            : $this->t('Active per configuration'),
        ],
      ],
    );
  }

  private function checkGoogleAnalytics(): StatusCheck {
    $enabled = (bool) $this->configFactory->get('myeventlane_analytics.settings')->get('enabled');
    $measurementId = $this->analytics->getMeasurementId();

    if (!$enabled) {
      $state = StatusCheck::WARNING;
      $summary = $this->t('Disabled');
    }
    elseif ($measurementId !== '') {
      $state = StatusCheck::OK;
      $summary = $this->t('Configured');
    }
    else {
      $state = StatusCheck::WARNING;
      $summary = $this->t('Not configured');
    }

    return new StatusCheck(
      'google_analytics',
      $this->t('Google Analytics (GA4)'),
      $state,
      $summary,
      [
        [
          'label' => $this->t('Measurement ID'),
          'value' => $measurementId !== '' ? $measurementId : $this->t('Not set'),
        ],
        [
          'label' => $this->t('Master switch'),
          'value' => $enabled ? $this->t('Enabled') : $this->t('Disabled'),
        ],
      ],
      'myeventlane_analytics.settings',
    );
  }

  private function checkSearchConsole(): StatusCheck {
    // Presence only — the verification token is a secret and is never shown.
    $verified = $this->analytics->getSearchConsoleVerification() !== '';
    return new StatusCheck(
      'search_console',
      $this->t('Search Console'),
      $verified ? StatusCheck::OK : StatusCheck::WARNING,
      $verified ? $this->t('Verified') : $this->t('Not configured'),
      [
        [
          'label' => $this->t('Verification meta tag'),
          'value' => $verified ? $this->t('Present') : $this->t('Not set'),
        ],
      ],
      'myeventlane_analytics.settings',
    );
  }

  private function checkPostmark(): StatusCheck {
    $moduleEnabled = $this->moduleHandler->moduleExists('postmark');
    // Presence only — the API key is a secret and is never shown.
    $hasApiKey = (string) ($this->configFactory->get('postmark.settings')->get('postmark_api_key') ?: '') !== '';
    $sender = (string) ($this->configFactory->get('mailsystem.settings')->get('defaults.sender') ?: '');
    $transportIsPostmark = stripos($sender, 'postmark') !== FALSE;

    if (!$moduleEnabled) {
      $state = StatusCheck::CRITICAL;
      $summary = $this->t('Module not enabled');
    }
    elseif ($hasApiKey && $transportIsPostmark) {
      $state = StatusCheck::OK;
      $summary = $this->t('Connected');
    }
    else {
      $state = StatusCheck::WARNING;
      $summary = $this->t('Not fully configured');
    }

    return new StatusCheck(
      'postmark',
      $this->t('Postmark'),
      $state,
      $summary,
      [
        ['label' => $this->t('Module'), 'value' => $moduleEnabled ? $this->t('Enabled') : $this->t('Disabled')],
        ['label' => $this->t('API key'), 'value' => $hasApiKey ? $this->t('Present') : $this->t('Missing')],
        ['label' => $this->t('Mail transport'), 'value' => $transportIsPostmark ? $this->t('Postmark') : ($sender !== '' ? $sender : $this->t('Not set'))],
      ],
      'postmark.settings',
    );
  }

  private function checkEmail(): StatusCheck {
    $mailConfig = $this->configFactory->get('mailsystem.settings');
    $sender = (string) ($mailConfig->get('defaults.sender') ?: $this->t('default'));
    $formatter = (string) ($mailConfig->get('defaults.formatter') ?: $this->t('default'));
    $queueSize = $this->platformHealth->getWaitlistMailQueueSize();

    return new StatusCheck(
      'email',
      $this->t('Email delivery'),
      StatusCheck::INFO,
      $queueSize > 0
        ? $this->t('@count queued', ['@count' => $queueSize])
        : $this->t('Idle'),
      [
        ['label' => $this->t('Delivery transport'), 'value' => $sender],
        ['label' => $this->t('Mail backend'), 'value' => $formatter],
        ['label' => $this->t('Waitlist mail queue'), 'value' => $this->formatPlural($queueSize, '1 item', '@count items')],
      ],
      'mailsystem.settings',
    );
  }

  private function checkWaitlist(): StatusCheck {
    try {
      $counts = $this->waitlistAnalytics->getSubscriberCountsByStatus();
      $total = $this->waitlistAnalytics->getTotalSubscribers();
      $last = $this->waitlistAnalytics->getLastSignupTimestamp();
    }
    catch (\Exception $e) {
      $this->logger->error('Waitlist status read failed: @message', ['@message' => $e->getMessage()]);
      return new StatusCheck(
        'waitlist',
        $this->t('Waitlist'),
        StatusCheck::CRITICAL,
        $this->t('Unavailable'),
      );
    }

    $confirmed = $counts['confirmed'] ?? 0;
    $unconfirmed = $counts['pending'] ?? 0;

    return new StatusCheck(
      'waitlist',
      $this->t('Waitlist'),
      StatusCheck::INFO,
      $this->formatPlural($total, '1 subscriber', '@count subscribers'),
      [
        ['label' => $this->t('Last signup'), 'value' => $this->relativeTime($last)],
        ['label' => $this->t('Confirmed'), 'value' => (string) $confirmed],
        ['label' => $this->t('Unconfirmed (pending)'), 'value' => (string) $unconfirmed],
      ],
      'myeventlane_waitlist.admin_dashboard',
    );
  }

  private function checkCron(): StatusCheck {
    $last = $this->platformHealth->getCronLastRun();
    $interval = (int) ($this->configFactory->get('automated_cron.settings')->get('interval') ?? 0);
    $now = $this->time->getRequestTime();

    if ($last === NULL) {
      $state = StatusCheck::CRITICAL;
      $summary = $this->t('Never run');
    }
    elseif ($interval > 0 && ($now - $last) > ($interval * 2)) {
      $state = StatusCheck::WARNING;
      $summary = $this->t('Overdue');
    }
    else {
      $state = StatusCheck::OK;
      $summary = $this->t('Healthy');
    }

    $details = [
      ['label' => $this->t('Last run'), 'value' => $this->relativeTime($last)],
    ];
    if ($interval > 0) {
      // An automated interval is configured. Show the next expected run once
      // cron has actually run; before the first run there is no baseline to
      // project from, so report the configured cadence instead — never
      // "external / manual", which only applies when no interval is set.
      $details[] = $last !== NULL
        ? [
          'label' => $this->t('Next expected'),
          'value' => $this->dateFormatter->formatTimeDiffUntil($last + $interval),
        ]
        : [
          'label' => $this->t('Schedule'),
          'value' => $this->t('Every @interval', ['@interval' => $this->dateFormatter->formatInterval($interval)]),
        ];
    }
    else {
      $details[] = ['label' => $this->t('Schedule'), 'value' => $this->t('External / manual')];
    }

    return new StatusCheck('cron', $this->t('Cron'), $state, $summary, $details, 'system.cron_settings');
  }

  private function checkSitemap(): StatusCheck {
    if (!$this->moduleHandler->moduleExists('simple_sitemap') || $this->sitemapGenerator === NULL) {
      return new StatusCheck(
        'sitemap',
        $this->t('XML Sitemap'),
        StatusCheck::WARNING,
        $this->t('Module not enabled'),
      );
    }

    try {
      $sitemap = $this->sitemapGenerator->getDefaultSitemap();
      if ($sitemap === NULL) {
        return new StatusCheck(
          'sitemap',
          $this->t('XML Sitemap'),
          StatusCheck::WARNING,
          $this->t('No sitemap configured'),
        );
      }
      $status = $sitemap->contentStatus();
      $cronGenerate = (bool) $this->sitemapGenerator->getSetting('cron_generate', FALSE);
    }
    catch (\Exception $e) {
      $this->logger->warning('Sitemap status read failed: @message', ['@message' => $e->getMessage()]);
      return new StatusCheck('sitemap', $this->t('XML Sitemap'), StatusCheck::WARNING, $this->t('Unavailable'));
    }

    if ($status === SimpleSitemap::SITEMAP_PUBLISHED) {
      $state = StatusCheck::OK;
      $summary = $this->t('Accessible');
    }
    elseif ($status === SimpleSitemap::SITEMAP_PUBLISHED_GENERATING) {
      $state = StatusCheck::OK;
      $summary = $this->t('Regenerating');
    }
    else {
      $state = StatusCheck::WARNING;
      $summary = $this->t('Not generated');
    }

    return new StatusCheck(
      'sitemap',
      $this->t('XML Sitemap'),
      $state,
      $summary,
      [
        ['label' => $this->t('Default variant'), 'value' => $sitemap->id()],
        ['label' => $this->t('Regenerate on cron'), 'value' => $cronGenerate ? $this->t('Enabled') : $this->t('Disabled')],
      ],
      'simple_sitemap.settings',
    );
  }

  private function checkRobots(): StatusCheck {
    $path = $this->appRoot . '/robots.txt';
    $present = is_file($path) && is_readable($path);
    $hasSitemap = FALSE;
    if ($present) {
      $contents = (string) file_get_contents($path);
      $hasSitemap = stripos($contents, 'Sitemap:') !== FALSE;
    }

    if (!$present) {
      $state = StatusCheck::WARNING;
      $summary = $this->t('Missing');
    }
    elseif ($hasSitemap) {
      $state = StatusCheck::OK;
      $summary = $this->t('Present');
    }
    else {
      $state = StatusCheck::WARNING;
      $summary = $this->t('No sitemap reference');
    }

    return new StatusCheck(
      'robots',
      $this->t('robots.txt'),
      $state,
      $summary,
      [
        ['label' => $this->t('File'), 'value' => $present ? $this->t('Present') : $this->t('Missing')],
        ['label' => $this->t('Sitemap directive'), 'value' => $hasSitemap ? $this->t('Present') : $this->t('Absent')],
      ],
    );
  }

  private function checkConfig(): StatusCheck {
    if ($this->syncStorage->listAll() === []) {
      return new StatusCheck(
        'config',
        $this->t('Configuration'),
        StatusCheck::INFO,
        $this->t('No sync export'),
        [['label' => $this->t('Sync directory'), 'value' => $this->t('Empty')]],
      );
    }

    try {
      $comparer = new StorageComparer($this->activeStorage, $this->syncStorage);
      $comparer->createChangelist();
      $hasChanges = $comparer->hasChanges();
    }
    catch (\Exception $e) {
      $this->logger->warning('Config drift check failed: @message', ['@message' => $e->getMessage()]);
      return new StatusCheck('config', $this->t('Configuration'), StatusCheck::WARNING, $this->t('Comparison failed'));
    }

    return new StatusCheck(
      'config',
      $this->t('Configuration'),
      $hasChanges ? StatusCheck::WARNING : StatusCheck::OK,
      $hasChanges ? $this->t('Drift detected') : $this->t('Clean'),
      [
        [
          'label' => $this->t('Active vs sync'),
          'value' => $hasChanges ? $this->t('Differs') : $this->t('In sync'),
        ],
      ],
      'config.sync',
    );
  }

  /**
   * Human-readable "x ago" for a timestamp, or a dash when there is none.
   */
  private function relativeTime(?int $timestamp): string|\Stringable {
    if ($timestamp === NULL) {
      return $this->t('None yet');
    }
    return $this->t('@ago (@date)', [
      '@ago' => $this->dateFormatter->formatTimeDiffSince($timestamp),
      '@date' => $this->dateFormatter->format($timestamp, 'short'),
    ]);
  }

}
