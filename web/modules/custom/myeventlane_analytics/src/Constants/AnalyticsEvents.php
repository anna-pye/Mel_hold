<?php

declare(strict_types=1);

namespace Drupal\myeventlane_analytics\Constants;

/**
 * GA4 event names tracked on the HOLD site.
 *
 * Deliberately excludes Commerce/Vendor/Event/Revenue event names — those
 * belong to a future myeventlane_commerce_analytics submodule built against
 * AnalyticsService::track(), once that platform actually exists.
 */
final class AnalyticsEvents {

  public const LOGIN = 'login';

  public const SIGN_UP = 'sign_up';

  public const WAITLIST_JOIN = 'waitlist_join';

  public const CONTACT_FORM_SUBMIT = 'contact_form_submit';

  public const SEARCH = 'search';

  public const NOT_FOUND = '404';

}
