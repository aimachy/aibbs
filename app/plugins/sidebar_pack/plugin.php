<?php
if (!defined('APP_ROOT')) exit;

/*
 * sidebar_pack — sidebar cards for AIBOT Play: Boards, Hot Topics,
 * New Members and Site Stats, appended to the core sidebar stack.
 *
 * Performance: the four aggregates are computed at most once per
 * SIDEBAR_PACK_TTL seconds and stored as one JSON settings row (the
 * core's sanctioned cross-request cache). A normal page view reads the
 * decoded cache through settings_cache() and runs zero extra queries.
 * Board visibility is NOT baked into the cache: per-board topic counts
 * and hot topics for all boards are cached, and the guest-visible set
 * (forums_cache + forum_group_allowed, same filter seo_pack uses) is
 * applied per request in memory, so a restricted board never leaks to
 * viewers who may not see it.
 *
 * Every callback is defensive: any failure returns the core sidebar
 * unchanged so this plugin can never break a page.
 */

const SIDEBAR_PACK_TTL = 120;
const SIDEBAR_PACK_CACHE_KEY = 'plugin_sidebar_pack_cache';

/** Aggregated sidebar data, from the settings cache or freshly computed. */
function sidebar_pack_data(): array
{
    if (isset($GLOBALS['__sidebar_pack_data'])) return (array)$GLOBALS['__sidebar_pack_data'];
    $empty = ['boards' => [], 'hot_week' => [], 'hot_all' => [], 'members' => [], 'stats' => ['topics' => 0, 'replies' => 0, 'members' => 0]];
    try {
        $raw = json_decode(setting(SIDEBAR_PACK_CACHE_KEY), true);
        if (is_array($raw) && (int)($raw['ts'] ?? 0) > time() - SIDEBAR_PACK_TTL && is_array($raw['data'] ?? null)) {
            return $GLOBALS['__sidebar_pack_data'] = (array)$raw['data'] + $empty;
        }
        $data = sidebar_pack_compute();
        save_settings_values([SIDEBAR_PACK_CACHE_KEY => json_encode(['ts' => time(), 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
        return $GLOBALS['__sidebar_pack_data'] = $data;
    } catch (Throwable) {
        return $GLOBALS['__sidebar_pack_data'] = $empty;
    }
}

/** The only place queries run: 4 cheap aggregates, on cache miss only. */
function sidebar_pack_compute(): array
{
    $boards = [];
    foreach (q("SELECT forum_id, COUNT(*) AS c FROM app_topics GROUP BY forum_id")->fetchAll() as $row) {
        $boards[(int)$row['forum_id']] = (int)$row['c'];
    }
    $hot_cols = "id, forum_id, title, reply_count, view_count";
    $hot_week = q("SELECT $hot_cols FROM app_topics WHERE last_reply_at >= ? ORDER BY reply_count DESC, view_count DESC, id DESC LIMIT 10", [time() - 7 * 86400])->fetchAll();
    $hot_all = q("SELECT $hot_cols FROM app_topics ORDER BY reply_count DESC, view_count DESC, id DESC LIMIT 10")->fetchAll();
    $members = q("SELECT id, username, created_at FROM app_users WHERE is_banned = 0 ORDER BY id DESC LIMIT 5")->fetchAll();
    $totals = q("SELECT (SELECT COUNT(*) FROM app_topics) AS topics, (SELECT COUNT(*) FROM app_replies) AS replies, (SELECT COUNT(*) FROM app_users) AS members")->fetch();
    return [
        'boards' => $boards,
        'hot_week' => $hot_week,
        'hot_all' => $hot_all,
        'members' => $members,
        'stats' => [
            'topics' => (int)($totals['topics'] ?? 0),
            'replies' => (int)($totals['replies'] ?? 0),
            'members' => (int)($totals['members'] ?? 0),
        ],
    ];
}

/** Boards the current viewer may see, in display order (mirrors seo_pack). */
function sidebar_pack_visible_forums(): array
{
    try {
        return array_values(array_filter(forums_cache(), fn($f) => forum_group_allowed($f, 'allow_view_groups')));
    } catch (Throwable) {
        return [];
    }
}

function sidebar_pack_card(string $title, string $inner): string
{
    return '<div class="card sidebar-card sidebar-pack-card"><div class="quick-wrap"><div class="quick-title">' . h($title) . '</div>' . $inner . '</div></div>';
}

function sidebar_pack_boards_card(array $data, array $forums): string
{
    if (!$forums) return '';
    $items = '';
    foreach ($forums as $f) {
        $fid = (int)$f['id'];
        $items .= '<li><a href="' . h(route_url('forum', ['id' => $fid])) . '"><span class="sidebar-pack-name">' . h((string)$f['name']) . '</span><span class="sidebar-pack-count">' . number_format((int)($data['boards'][$fid] ?? 0)) . '</span></a></li>';
    }
    return sidebar_pack_card('Boards', '<ul class="quick-links sidebar-pack-list">' . $items . '</ul>');
}

function sidebar_pack_hot_card(array $data, array $forums): string
{
    $visible = [];
    foreach ($forums as $f) $visible[(int)$f['id']] = true;
    $pick = static function (array $rows) use ($visible): array {
        $out = [];
        foreach ($rows as $t) {
            if (!isset($visible[(int)$t['forum_id']])) continue;
            $out[] = $t;
            if (count($out) >= 5) break;
        }
        return $out;
    };
    $topics = $pick(is_array($data['hot_week'] ?? null) ? $data['hot_week'] : []);
    if (count($topics) < 5) $topics = $pick(is_array($data['hot_all'] ?? null) ? $data['hot_all'] : []);
    if (!$topics) return '';
    $items = '';
    foreach ($topics as $t) {
        $replies = (int)$t['reply_count'];
        $items .= '<li><a href="' . h(route_url('topic', ['id' => (int)$t['id']])) . '"><span class="sidebar-pack-name">' . h((string)$t['title']) . '</span><span class="sidebar-pack-count">' . number_format($replies) . ($replies === 1 ? ' reply' : ' replies') . '</span></a></li>';
    }
    return sidebar_pack_card('Hot Topics', '<ul class="quick-links sidebar-pack-list">' . $items . '</ul>');
}

function sidebar_pack_members_card(array $data): string
{
    $members = is_array($data['members'] ?? null) ? $data['members'] : [];
    if (!$members) return '';
    $items = '';
    foreach ($members as $u) {
        $joined = (int)$u['created_at'] > 0 ? date('M j, Y', (int)$u['created_at']) : '';
        $items .= '<li><a href="' . h(route_url('user', ['id' => (int)$u['id']])) . '"><span class="sidebar-pack-name">' . h((string)$u['username']) . '</span>' . ($joined !== '' ? '<span class="sidebar-pack-count">' . $joined . '</span>' : '') . '</a></li>';
    }
    return sidebar_pack_card('New Members', '<ul class="quick-links sidebar-pack-list">' . $items . '</ul>');
}

function sidebar_pack_stats_card(array $data): string
{
    $stats = is_array($data['stats'] ?? null) ? $data['stats'] : [];
    $rows = '';
    foreach (['Topics' => 'topics', 'Replies' => 'replies', 'Members' => 'members'] as $label => $key) {
        $rows .= '<li><span>' . $label . '</span><strong>' . number_format((int)($stats[$key] ?? 0)) . '</strong></li>';
    }
    return sidebar_pack_card('Site Stats', '<ul class="sidebar-pack-stats">' . $rows . '</ul>');
}

/** sidebar.stack hook: append the four cards after the core cards. */
function sidebar_pack_stack($parts, array $ctx): array
{
    if (!is_array($parts)) return $parts;
    try {
        $data = sidebar_pack_data();
        $forums = sidebar_pack_visible_forums();
        foreach ([sidebar_pack_boards_card($data, $forums), sidebar_pack_hot_card($data, $forums), sidebar_pack_members_card($data), sidebar_pack_stats_card($data)] as $card) {
            if ($card !== '') $parts[] = $card;
        }
    } catch (Throwable) {
        // Never break the sidebar: fall through with the core parts only.
    }
    return $parts;
}

function sidebar_pack_uninstall(array $plugin): void
{
    q("DELETE FROM app_settings WHERE name = ?", [SIDEBAR_PACK_CACHE_KEY]);
}

function sidebar_pack_css(): string
{
    return <<<'CSS'
.sidebar-pack-list .sidebar-pack-name { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap }
.sidebar-pack-list .sidebar-pack-count { margin-left: auto; padding-left: 8px; color: var(--text-subtle); font-size: var(--font-size-xs); white-space: nowrap }
.sidebar-pack-stats { list-style: none; margin: 0; padding: 0 }
.sidebar-pack-stats li { display: flex; align-items: center; justify-content: space-between; min-height: 28px; border-bottom: 1px solid var(--bg); font-size: var(--font-size-sm); color: var(--text-muted) }
.sidebar-pack-stats li:last-child { border-bottom: none }
.sidebar-pack-stats strong { color: var(--text); font-weight: 700 }
CSS;
}

return [
    'id' => 'sidebar_pack',
    'name' => 'Sidebar Pack',
    'version' => '1.0.1',
    'description' => 'Shows Boards, Hot Topics, New Members and Site Stats cards in the sidebar.',
    'author' => 'AIBOT Play',
    'assets' => ['css' => 'sidebar_pack_css'],
    'hooks' => ['sidebar.stack' => 'sidebar_pack_stack'],
    'uninstall' => 'sidebar_pack_uninstall',
];
