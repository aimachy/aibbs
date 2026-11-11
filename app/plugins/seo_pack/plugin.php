<?php
if (!defined('APP_ROOT')) exit;

/*
 * seo_pack — search-engine & AI discovery pack for AIBOT Play.
 *
 * - Route `sitemap.xml`: home + guest-visible boards + all live topics,
 *   generated from the database on every request (lastmod = last activity).
 * - Route `llms.txt`: plain-text site summary for AI crawlers — site
 *   description, board list and the latest topics.
 * - Hook `robots.txt`: appends the Sitemap line to the core robots body.
 * - Hook `page.seo`: fills the home-page canonical (the core only sets a
 *   canonical on topic/forum/user pages) and a fallback meta description.
 * - Hook `page.head`: Organization + WebSite (with SearchAction) JSON-LD
 *   on every page.
 *
 * Every callback is defensive: any failure falls back to core behaviour so
 * this plugin can never break a page.
 */

function seo_pack_base(): string
{
    $base = absolute_url(route_url('home'));
    // route_url('home') ends in /index.php — the site's public root is the bare domain.
    $base = preg_replace('#index\.php/?$#', '', $base) ?? $base;
    return rtrim($base, '/');
}

function seo_pack_site_name(): string
{
    $name = trim((string)(settings_cache()['site_name'] ?? ''));
    return $name !== '' ? $name : 'AIBOT Play';
}

function seo_pack_description(): string
{
    $desc = trim(setting('site_description'));
    if ($desc !== '') return $desc;
    return 'An English-language community for AI news, free resources and deals, models, tools and apps, tutorials and guides, prompts and workflows, showcases, and help and discussion.';
}

/** Boards a guest may view, in display order. */
function seo_pack_visible_forums(): array
{
    try {
        return array_values(array_filter(forums_cache(), fn($f) => forum_group_allowed($f, 'allow_view_groups')));
    } catch (Throwable) {
        return [];
    }
}

/** YYYY-MM-DD for a stored timestamp (unix epoch or datetime string), '' when unusable. */
function seo_pack_date(string $dt): string
{
    $dt = trim($dt);
    if ($dt === '') return '';
    if (ctype_digit($dt)) return gmdate('Y-m-d', (int)$dt);
    $d = substr($dt, 0, 10);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1 ? $d : '';
}

function seo_pack_sitemap(): void
{
    header('Content-Type: application/xml; charset=utf-8');
    $base = seo_pack_base();
    $forums = seo_pack_visible_forums();
    $forum_ids = array_map(fn($f) => (int)$f['id'], $forums);

    $topics = [];
    $forum_lastmod = [];
    $home_lastmod = '';
    if ($forum_ids !== []) {
        try {
            $in = implode(',', $forum_ids);
            $topics = q("SELECT id,forum_id,last_reply_at,created_at FROM app_topics WHERE forum_id IN ($in) ORDER BY id")->fetchAll();
        } catch (Throwable) {
            $topics = [];
        }
        foreach ($topics as $t) {
            $d = seo_pack_date((string)($t['last_reply_at'] ?: $t['created_at']));
            if ($d === '') continue;
            $fid = (int)$t['forum_id'];
            if (($forum_lastmod[$fid] ?? '') < $d) $forum_lastmod[$fid] = $d;
            if ($home_lastmod < $d) $home_lastmod = $d;
        }
    }

    $out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $out .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    $entry = static function (string $loc, string $lastmod, string $freq, string $prio) use (&$out): void {
        $out .= '<url><loc>' . h($loc) . '</loc>';
        if ($lastmod !== '') $out .= '<lastmod>' . $lastmod . '</lastmod>';
        $out .= '<changefreq>' . $freq . '</changefreq><priority>' . $prio . '</priority></url>' . "\n";
    };
    $entry($base . '/', $home_lastmod, 'daily', '1.0');
    foreach ($forums as $f) {
        $fid = (int)$f['id'];
        $entry(absolute_url(route_url('forum', ['id' => $fid])), $forum_lastmod[$fid] ?? '', 'daily', '0.8');
    }
    foreach ($topics as $t) {
        $d = seo_pack_date((string)($t['last_reply_at'] ?: $t['created_at']));
        $entry(absolute_url(route_url('topic', ['id' => (int)$t['id']])), $d, 'weekly', '0.6');
    }
    $out .= '</urlset>' . "\n";
    echo $out;
    exit;
}

function seo_pack_llms(): void
{
    header('Content-Type: text/plain; charset=utf-8');
    $base = seo_pack_base();
    $forums = seo_pack_visible_forums();
    $out = '# ' . seo_pack_site_name() . "\n\n";
    $out .= '> ' . seo_pack_description() . "\n\n";
    $out .= 'AIBOT Play is a discussion forum. Content is organized into boards (below); each topic is a thread with tree-structured replies. All board and topic pages are public and server-rendered.' . "\n\n";
    $out .= '## Boards' . "\n\n";
    foreach ($forums as $f) {
        $url = absolute_url(route_url('forum', ['id' => (int)$f['id']]));
        $desc = trim((string)($f['description'] ?? ''));
        $out .= '- [' . (string)$f['name'] . '](' . $url . ')' . ($desc !== '' ? ': ' . $desc : '') . "\n";
    }
    $out .= "\n" . '## Latest topics' . "\n\n";
    try {
        $forum_ids = array_map(fn($f) => (int)$f['id'], $forums);
        $latest = $forum_ids === [] ? [] : q('SELECT id,title FROM app_topics WHERE forum_id IN (' . implode(',', $forum_ids) . ') ORDER BY last_reply_at DESC,id DESC LIMIT 20')->fetchAll();
        foreach ($latest as $t) {
            $out .= '- [' . trim((string)$t['title']) . '](' . absolute_url(route_url('topic', ['id' => (int)$t['id']])) . ")\n";
        }
    } catch (Throwable) {
        // Board list above is the essential part; topics are best-effort.
    }
    $out .= "\n" . '## Key pages' . "\n\n";
    $out .= '- [Home](' . $base . "/)\n";
    $out .= '- [Sitemap](' . $base . "/sitemap.xml)\n";
    echo $out;
    exit;
}

function seo_pack_robots($value, array $ctx = [])
{
    try {
        $body = rtrim((string)$value);
        if (!str_contains($body, 'Sitemap:')) {
            $body .= "\n\nSitemap: " . seo_pack_base() . '/sitemap.xml';
        }
        return $body . "\n";
    } catch (Throwable) {
        return $value;
    }
}

function seo_pack_seo($seo, array $ctx = [])
{
    try {
        if (!is_array($seo)) $seo = [];
        if (($ctx['page_type'] ?? '') === 'home') {
            if (empty($seo['canonical'])) $seo['canonical'] = seo_pack_base() . '/';
            if (empty($seo['description'])) $seo['description'] = seo_pack_description();
        }
        return $seo;
    } catch (Throwable) {
        return $seo;
    }
}

function seo_pack_head($value, array $ctx = [])
{
    try {
        $base = seo_pack_base();
        $name = seo_pack_site_name();
        $data = [
            '@context' => 'https://schema.org',
            '@graph' => [
                [
                    '@type' => 'Organization',
                    'name' => $name,
                    'url' => $base . '/',
                    'logo' => absolute_url(asset_url('app/assets/index.svg')),
                ],
                [
                    '@type' => 'WebSite',
                    'name' => $name,
                    'url' => $base . '/',
                    'potentialAction' => [
                        '@type' => 'SearchAction',
                        'target' => [
                            '@type' => 'EntryPoint',
                            'urlTemplate' => $base . '/index.php?a=search&q={search_term_string}',
                        ],
                        'query-input' => 'required name=search_term_string',
                    ],
                ],
            ],
        ];
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        if (!is_string($json) || $json === '') return $value;
        return (string)$value . '<script type="application/ld+json">' . $json . '</script>';
    } catch (Throwable) {
        return $value;
    }
}

return [
    'id' => 'seo_pack',
    'name' => 'SEO Pack',
    'version' => '1.0.1',
    'description' => 'Dynamic sitemap.xml and llms.txt, Sitemap line in robots.txt, home canonical and Organization/WebSite structured data.',
    'author' => 'AIBOT Play',
    'hooks' => [
        'robots.txt' => 'seo_pack_robots',
        'page.seo' => 'seo_pack_seo',
        'page.head' => 'seo_pack_head',
    ],
    'routes' => [
        'sitemap.xml' => 'seo_pack_sitemap',
        'llms.txt' => 'seo_pack_llms',
    ],
];
