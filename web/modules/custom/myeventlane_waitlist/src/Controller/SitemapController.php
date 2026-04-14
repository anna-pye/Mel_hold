<?php

declare(strict_types=1);

namespace Drupal\myeventlane_waitlist\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\Response;

/**
 * Minimal sitemap for the holding site.
 */
final class SitemapController extends ControllerBase {

  public function index(): Response {
    $urls = [
      Url::fromRoute('<front>', [], ['absolute' => TRUE])->toString(),
      Url::fromRoute('myeventlane_waitlist.contact', [], ['absolute' => TRUE])->toString(),
      Url::fromRoute('myeventlane_waitlist.privacy', [], ['absolute' => TRUE])->toString(),
    ];
    $items = '';
    foreach ($urls as $loc) {
      $items .= '<url><loc>' . htmlspecialchars($loc, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc></url>';
    }
    $xml = '<?xml version="1.0" encoding="UTF-8"?>';
    $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . $items . '</urlset>';
    $response = new Response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    $response->setPublic();
    $response->setMaxAge(3600);
    return $response;
  }

}
