<?php

/**
 * @file
 * Idempotent builder for the MyEventLane HOLD public information architecture.
 *
 * Run with:
 *   ddev drush php:script scripts/hold-ia-build.php
 *
 * Safe to run repeatedly. It:
 *  - reuses existing nodes 1/2/3 (retitles + clean aliases, no duplicates),
 *  - migrates node 3's Privacy Policy body into the module /privacy page,
 *  - creates the missing Basic page nodes with Australian-English copy,
 *  - sets clean URL aliases,
 *  - adds 301 redirects for legacy aliases,
 *  - builds the main and footer menus.
 *
 * Content lives in the database (not config), so this script is the reviewable,
 * reproducible source of that content.
 */

declare(strict_types=1);

use Drupal\node\Entity\Node;
use Drupal\redirect\Entity\Redirect;
use Drupal\menu_link_content\Entity\MenuLinkContent;

$body_format = 'full_html';

/**
 * Ensure a clean alias for an internal system path (idempotent).
 */
$ensure_alias = static function (string $system_path, string $alias): void {
  $storage = \Drupal::entityTypeManager()->getStorage('path_alias');
  // Remove any existing aliases pointing at this system path.
  $existing = $storage->loadByProperties(['path' => $system_path]);
  foreach ($existing as $a) {
    if ($a->getAlias() !== $alias) {
      $a->delete();
    }
  }
  // Remove aliases that already use this alias for a different path.
  $clash = $storage->loadByProperties(['alias' => $alias]);
  foreach ($clash as $a) {
    if ($a->getPath() !== $system_path) {
      $a->delete();
    }
  }
  $current = $storage->loadByProperties(['path' => $system_path, 'alias' => $alias]);
  if (!$current) {
    $storage->create(['path' => $system_path, 'alias' => $alias, 'langcode' => 'en'])->save();
  }
  echo "  alias: {$alias} -> {$system_path}\n";
};

/**
 * Ensure a 301 redirect from a legacy path to a destination (idempotent).
 */
$ensure_redirect = static function (string $from, string $to): void {
  $from = ltrim($from, '/');
  $hash = Redirect::generateHash($from, [], 'en');
  $existing = \Drupal::entityTypeManager()->getStorage('redirect')
    ->loadByProperties(['hash' => $hash]);
  if ($existing) {
    echo "  redirect exists: /{$from} -> {$to}\n";
    return;
  }
  Redirect::create([
    'redirect_source' => ['path' => $from, 'query' => []],
    'redirect_redirect' => ['uri' => 'internal:' . $to],
    'language' => 'en',
    'status_code' => 301,
  ])->save();
  echo "  redirect: /{$from} -> {$to} (301)\n";
};

/**
 * Ensure a published Basic page exists with the given alias (idempotent).
 *
 * Identity key is the alias: if a node already owns it, reuse+update it.
 */
$ensure_page = static function (string $alias, string $title, string $body_html) use ($body_format): Node {
  $aliasStorage = \Drupal::entityTypeManager()->getStorage('path_alias');
  $match = $aliasStorage->loadByProperties(['alias' => $alias]);
  $node = NULL;
  foreach ($match as $a) {
    if (preg_match('#^/node/(\d+)$#', $a->getPath(), $m)) {
      $node = Node::load((int) $m[1]);
      break;
    }
  }
  if (!$node) {
    $node = Node::create(['type' => 'page']);
  }
  $node->setTitle($title);
  $node->set('body', ['value' => $body_html, 'format' => $body_format]);
  $node->setPublished(TRUE);
  $node->set('promote', 0);
  $node->save();
  echo "  page: {$title} (node/{$node->id()})\n";
  return $node;
};

/**
 * Ensure a menu link exists (idempotent by menu + title).
 *
 * Returns the plugin id for parenting (menu_link_content:UUID).
 */
$ensure_link = static function (string $menu, string $title, ?string $uri, int $weight, ?string $parent = NULL, bool $expanded = FALSE): string {
  $storage = \Drupal::entityTypeManager()->getStorage('menu_link_content');
  $found = $storage->loadByProperties(['menu_name' => $menu, 'title' => $title]);
  /** @var \Drupal\menu_link_content\Entity\MenuLinkContent $link */
  $link = $found ? reset($found) : MenuLinkContent::create(['menu_name' => $menu, 'title' => $title]);
  $link->set('title', $title);
  $link->set('menu_name', $menu);
  $link->set('link', ['uri' => $uri ?? 'route:<nolink>']);
  $link->set('weight', $weight);
  $link->set('expanded', $expanded);
  $link->set('parent', $parent);
  $link->set('enabled', TRUE);
  $link->save();
  return 'menu_link_content:' . $link->uuid();
};

echo "== Reusing + updating existing nodes ==\n";

// node/1 "About MyEventLane" -> "What is MyEventLane?" at /what-is-myeventlane.
if ($n1 = Node::load(1)) {
  $n1->setTitle('What is MyEventLane?');
  $n1->setPublished(TRUE);
  $n1->save();
  $ensure_alias('/node/1', '/what-is-myeventlane');
}

// node/2 "Our Story" at /our-story (+ legacy redirect).
if ($n2 = Node::load(2)) {
  $n2->setTitle('Our Story');
  $n2->setPublished(TRUE);
  $n2->save();
  $ensure_alias('/node/2', '/our-story');
  $ensure_redirect('/MyEventLane-Our_Story', '/our-story');
}

// node/3 Privacy Policy -> migrate body into module /privacy, retire the node.
if ($n3 = Node::load(3)) {
  $privacy_html = (string) ($n3->get('body')->value ?? '');
  if ($privacy_html !== '') {
    \Drupal::configFactory()->getEditable('myeventlane_waitlist.settings')
      ->set('privacy_body', $privacy_html)
      ->save();
    echo "  migrated node/3 body -> myeventlane_waitlist.settings:privacy_body (" . strlen($privacy_html) . " chars)\n";
  }
  // Retire the duplicate node; legacy alias redirects to canonical /privacy.
  $n3->setUnpublished();
  $n3->save();
  // Remove the node's alias so it no longer shadows the redirect below.
  foreach (\Drupal::entityTypeManager()->getStorage('path_alias')
    ->loadByProperties(['path' => '/node/3']) as $a) {
    $a->delete();
  }
  $ensure_redirect('/myeventlane-Privacy_Policy', '/privacy');
}

echo "== Creating missing pages ==\n";

$pages = [
  '/our-mission' => ['Our Mission', <<<'HTML'
<h2>Our mission</h2>
<p>MyEventLane exists to make local events easier to find, easier to run, and easier to trust. We want more people at more community events — with less friction for the people who make them happen.</p>
<h3>What we believe</h3>
<ul>
  <li><strong>Community events matter.</strong> They are where people meet, belong, and look out for one another.</li>
  <li><strong>Organisers deserve fair tools.</strong> Ticketing and promotion should be affordable and simple, not a barrier.</li>
  <li><strong>Discovery should be local first.</strong> The best event for you is often just around the corner.</li>
  <li><strong>Trust is earned.</strong> Clear information, honest pricing, and careful handling of your details are the baseline, not a bonus.</li>
</ul>
<h3>How we measure success</h3>
<p>We will know we are doing our job when organisers spend less time wrestling with tools and more time running great events, and when people can find and back the events happening around them with confidence.</p>
<p>MyEventLane is in final development ahead of launch. <a href="/">Join the waitlist</a> to hear when we open.</p>
HTML],

  '/organisers' => ['For Event Organisers', <<<'HTML'
<h2>For event organisers</h2>
<p>Whether you run a monthly meet-up, a fundraiser, or a ticketed festival, MyEventLane is being built to give you one straightforward place to publish, promote, and manage your events.</p>
<h3>What you will be able to do</h3>
<ul>
  <li>Create clear event pages that are easy to share.</li>
  <li>Sell tickets or take free RSVPs, with a simple checkout for attendees.</li>
  <li>Manage attendees, capacity, and communications in one place.</li>
  <li>Reach people looking for events in your area.</li>
</ul>
<h3>Fair, simple pricing</h3>
<p>We are finalising pricing and are committed to keeping it fair and transparent, especially for community groups and grassroots organisers. See <a href="/pricing">Pricing</a> for details as they are confirmed.</p>
<h3>Be an early organiser</h3>
<p>We are working with a small group of organisers, venues, and community groups to prepare for launch. If that sounds like you, <a href="/">join the waitlist</a> or <a href="/contact">get in touch</a>.</p>
HTML],

  '/community' => ['Community', <<<'HTML'
<h2>Our community</h2>
<p>MyEventLane is built around the people who make local events happen — volunteers, small teams, independent promoters, venues, and the communities who show up. We want a platform that feels approachable and puts those people first.</p>
<h3>Being part of it</h3>
<p>A few short documents set out what we expect of one another and how we look after each other:</p>
<ul>
  <li><a href="/community-guidelines">Community Guidelines</a> — how we treat one another here.</li>
  <li><a href="/trust-safety">Trust &amp; Safety</a> — how we help keep events and people safe.</li>
  <li><a href="/accessibility">Accessibility Statement</a> — our commitment to an inclusive experience.</li>
</ul>
<p>Have an idea, or want to introduce your community group? <a href="/contact">Contact us</a> — we would love to hear from you.</p>
HTML],

  '/community-guidelines' => ['Community Guidelines', <<<'HTML'
<h2>Community guidelines</h2>
<p>These guidelines apply to everyone who uses MyEventLane — organisers and attendees alike. They are here to keep MyEventLane welcoming, safe, and useful.</p>
<h3>The basics</h3>
<ul>
  <li><strong>Be respectful.</strong> Treat others as you would want to be treated. No harassment, hate, or abuse.</li>
  <li><strong>Be honest.</strong> Describe your events accurately — dates, prices, location, and what attendees can expect.</li>
  <li><strong>Keep it lawful and safe.</strong> Do not use MyEventLane for anything illegal, dangerous, or misleading.</li>
  <li><strong>Look after attendees.</strong> Honour bookings, communicate changes promptly, and handle people's details with care.</li>
</ul>
<h3>Reporting a concern</h3>
<p>If you see something that breaches these guidelines, please <a href="/contact">let us know</a>. We review reports and may remove content or accounts that put the community at risk.</p>
<p>This is a launch-ready draft and may be updated before and after launch.</p>
HTML],

  '/trust-safety' => ['Trust & Safety', <<<'HTML'
<h2>Trust &amp; safety</h2>
<p>Trust is the foundation of a good events platform. We are building MyEventLane so that people can find events, book with confidence, and know their information is handled responsibly.</p>
<h3>Keeping events trustworthy</h3>
<p>We expect organisers to describe events accurately and honour bookings. Events that mislead attendees or breach our <a href="/community-guidelines">Community Guidelines</a> can be removed.</p>
<h3>Protecting your information</h3>
<p>We collect only what we need and protect it appropriately. See our <a href="/privacy">Privacy Policy</a> for how personal information is handled.</p>
<h3>Reporting a problem</h3>
<p>If something does not look right — an event, a message, or an account — please <a href="/contact">report it to us</a>. We take reports seriously and will investigate.</p>
<p>This is a launch-ready draft and may be updated as the platform grows.</p>
HTML],

  '/accessibility' => ['Accessibility Statement', <<<'HTML'
<h2>Accessibility statement</h2>
<p>We want MyEventLane to be usable by everyone, including people who use assistive technologies. We are working towards conformance with the Web Content Accessibility Guidelines (WCAG) 2.1 Level AA.</p>
<h3>What we are doing</h3>
<ul>
  <li>Using clear heading structure, meaningful link text, and sufficient colour contrast.</li>
  <li>Supporting keyboard navigation and visible focus indicators.</li>
  <li>Providing a skip link and semantic landmarks on every page.</li>
  <li>Designing forms with labels and helpful error messages.</li>
</ul>
<h3>Known limitations</h3>
<p>MyEventLane is still in development, so some areas are a work in progress. We are testing as we build and fixing issues as we find them.</p>
<h3>Contact us about accessibility</h3>
<p>If you encounter an accessibility barrier, please <a href="/contact">tell us</a> so we can put it right. Your feedback helps us improve.</p>
HTML],

  '/terms' => ['Terms of Use', <<<'HTML'
<h2>Terms of use</h2>
<p><em>Launch-ready draft — please review before production legal sign-off.</em></p>
<p>These terms govern your use of the MyEventLane website while we prepare for launch. By using this website, you agree to use it lawfully and in good faith.</p>
<h3>Using this website</h3>
<ul>
  <li>Do not misuse the site, attempt to disrupt it, or access it in unauthorised ways.</li>
  <li>Information on this site is provided for general information ahead of launch and may change.</li>
  <li>If you join our waitlist, you consent to us contacting you about the launch. You can unsubscribe at any time.</li>
</ul>
<h3>Content and intellectual property</h3>
<p>The MyEventLane name, logo, and site content are owned by MyEventLane or its licensors and may not be used without permission.</p>
<h3>Changes</h3>
<p>We may update these terms as the product develops. Full marketplace terms will be published before launch.</p>
<p>Questions? <a href="/contact">Contact us</a>.</p>
HTML],

  '/cookies' => ['Cookie Policy', <<<'HTML'
<h2>Cookie policy</h2>
<p><em>Launch-ready draft — please review before production legal sign-off.</em></p>
<p>This policy explains how MyEventLane uses cookies and similar technologies on this website.</p>
<h3>What cookies are</h3>
<p>Cookies are small text files stored on your device. They help websites work, remember preferences, and understand how the site is used.</p>
<h3>How we use them</h3>
<ul>
  <li><strong>Essential cookies</strong> — needed for the site to function, such as security and session handling.</li>
  <li><strong>Preference cookies</strong> — remember choices you make.</li>
  <li><strong>Analytics</strong> — where enabled, help us understand how the site is used so we can improve it.</li>
</ul>
<h3>Managing cookies</h3>
<p>You can control or delete cookies through your browser settings. Blocking some cookies may affect how the site works.</p>
<p>For how we handle personal information more broadly, see our <a href="/privacy">Privacy Policy</a>.</p>
HTML],

  '/pricing' => ['Pricing (Coming Soon)', <<<'HTML'
<h2>Pricing</h2>
<p><strong>Coming soon.</strong> We are finalising pricing ahead of launch.</p>
<p>Our aim is simple: fair, transparent pricing that works for community organisers as well as larger events. Free RSVPs should stay free to run, and ticketed events should not be weighed down by hidden fees.</p>
<h3>What to expect</h3>
<ul>
  <li>Clear pricing with no surprises.</li>
  <li>Sensible options for free and paid events.</li>
  <li>Consideration for community groups and grassroots organisers.</li>
</ul>
<p>Want the details as soon as they are confirmed? <a href="/">Join the waitlist</a> or read more <a href="/organisers">for event organisers</a>.</p>
HTML],
];

$created = [];
foreach ($pages as $alias => [$title, $html]) {
  $node = $ensure_page($alias, $title, $html);
  $ensure_alias('/node/' . $node->id(), $alias);
  $created[$alias] = $node;
}

echo "== Building MAIN menu ==\n";
$ensure_link('main', 'Home', 'internal:/', -50);
$ensure_link('main', 'Our Story', 'internal:/our-story', -40);
$ensure_link('main', 'What is MyEventLane?', 'internal:/what-is-myeventlane', -30);
$ensure_link('main', 'For Event Organisers', 'internal:/organisers', -20);
$ensure_link('main', 'Community', 'internal:/community', -10);
$ensure_link('main', 'Contact', 'internal:/contact', 0);
$ensure_link('main', 'Privacy', 'internal:/privacy', 10);

echo "== Building FOOTER menu (columns = top-level, links = children) ==\n";
$about = $ensure_link('footer', 'About', NULL, -40, NULL, TRUE);
$ensure_link('footer', 'Our Story', 'internal:/our-story', 0, $about);
$ensure_link('footer', 'What is MyEventLane?', 'internal:/what-is-myeventlane', 1, $about);
$ensure_link('footer', 'Our Mission', 'internal:/our-mission', 2, $about);
$ensure_link('footer', 'Contact', 'internal:/contact', 3, $about);

$community = $ensure_link('footer', 'Community', NULL, -30, NULL, TRUE);
$ensure_link('footer', 'Community Guidelines', 'internal:/community-guidelines', 0, $community);
$ensure_link('footer', 'Trust & Safety', 'internal:/trust-safety', 1, $community);
$ensure_link('footer', 'Accessibility Statement', 'internal:/accessibility', 2, $community);

$organisers = $ensure_link('footer', 'Organisers', NULL, -20, NULL, TRUE);
$ensure_link('footer', 'Sell on MyEventLane', 'internal:/organisers', 0, $organisers);
$ensure_link('footer', 'Pricing (Coming Soon)', 'internal:/pricing', 1, $organisers);

$legal = $ensure_link('footer', 'Legal', NULL, -10, NULL, TRUE);
$ensure_link('footer', 'Privacy Policy', 'internal:/privacy', 0, $legal);
$ensure_link('footer', 'Terms of Use', 'internal:/terms', 1, $legal);
$ensure_link('footer', 'Cookie Policy', 'internal:/cookies', 2, $legal);

echo "== Done. Rebuilding caches is recommended (drush cr). ==\n";
