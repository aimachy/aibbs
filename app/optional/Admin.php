<?php

declare(strict_types=1);

namespace app\optional;

if (!defined('APP_ROOT')) exit;

final class Admin
{
    public static function flag(int $yes, bool $danger = false): string
    {
        return '<span class="admin-flag' . ($yes ? ($danger ? ' danger' : ' on') : '') . '">' . ($yes ? 'Yes' : 'No') . '</span>';
    }

    public static function clear_opcache_cache(): bool
    {
        if (!function_exists('opcache_reset')) return false;
        try {
            return (bool)opcache_reset();
        } catch (\Throwable) {
            return false;
        }
    }

    public static function opcache_refresh_route(): void
    {
        $lock_file = DATA_DIR . '/opcache-refresh.lock';
        if (!is_file($lock_file) || !self::clear_opcache_cache()) {
            http_response_code(403);
            exit('failed');
        }
        @unlink($lock_file);
        exit('ok');
    }

    public static function save_settings(): void
    {
        $site_name = post('site_name', DB_STRING_MAX_LENGTH);
        if ($site_name === '') err('Site name cannot be empty');
        $gid = max(1, (int)($_POST['default_group_id'] ?? 2));
        if (!group_by_id($gid)) err('The default user group does not exist');
        $values = ['site_name' => $site_name, 'site_base_url' => clean_site_base_url((string)($_POST['site_base_url'] ?? '')), 'pinned_topic_ids' => preg_replace('/[^\d,]/', '', (string)($_POST['pinned_topic_ids'] ?? '')) ?: '', 'default_group_id' => (string)$gid];
        foreach (['site_name_title', 'site_keywords'] as $key) $values[$key] = post($key, DB_STRING_MAX_LENGTH);
        $values['site_description'] = post('site_description', DB_TEXT_MAX_LENGTH);
        foreach (['site_closed', 'debug_mode', 'ignore_ssl_errors', 'pretty_url', 'allow_register'] as $key) $values[$key] = isset($_POST[$key]) ? '1' : '0';
        foreach (['pc_nav_forum_count' => [0, 20, 6], 'topics_per_page' => [1, 200, 30], 'replies_per_page' => [1, 200, 50], 'max_pagination_pages' => [1, 1000, 50], 'post_interval_seconds' => [0, 3600, 5]] as $key => [$min, $max, $default]) $values[$key] = (string)min($max, max($min, (int)($_POST[$key] ?? $default)));
        $length_fields = [
            'username' => [1, DB_STRING_MAX_LENGTH, DB_STRING_MAX_LENGTH],
            'email' => [0, DB_STRING_MAX_LENGTH, DB_STRING_MAX_LENGTH],
            'bio' => [0, DB_TEXT_MAX_LENGTH, DB_TEXT_MAX_LENGTH],
            'search' => [2, DB_STRING_MAX_LENGTH, DB_STRING_MAX_LENGTH],
            'title' => [1, DB_STRING_MAX_LENGTH, DB_STRING_MAX_LENGTH],
            'topic_body' => [1, DB_TEXT_MAX_LENGTH, DB_TEXT_MAX_LENGTH],
            'reply_body' => [1, DB_TEXT_MAX_LENGTH, DB_TEXT_MAX_LENGTH],
        ];
        foreach ($length_fields as $key => [$default_min, $default_max, $hard_max]) {
            $min = min($hard_max, max(0, (int)($_POST[$key . '_min_length'] ?? $default_min)));
            $max = min($hard_max, max($min, (int)($_POST[$key . '_max_length'] ?? $default_max)));
            $values[$key . '_min_length'] = (string)$min;
            $values[$key . '_max_length'] = (string)$max;
        }
        $values['excerpt_length'] = (string)min(DB_TEXT_MAX_LENGTH, max(0, (int)($_POST['excerpt_length'] ?? 200)));
        save_settings_values($values);
    }

    public static function save_forum(): void
    {
        $name = post('name', DB_STRING_MAX_LENGTH);
        if ($name === '') err('Board name cannot be empty');
        $description = post('description', DB_TEXT_MAX_LENGTH);
        $sort = (int)$_POST['sort'];
        $permissions = [];
        foreach (['allow_view_groups', 'allow_post_groups', 'allow_reply_groups'] as $field) $permissions[$field] = implode(',', array_values(array_unique(array_filter(array_map('intval', (array)($_POST[$field] ?? []))))));
        id() ? q("UPDATE app_forums SET name=?,description=?,sort=?,allow_view_groups=?,allow_post_groups=?,allow_reply_groups=? WHERE id=?", [$name, $description, $sort, ...array_values($permissions), id()]) : q("INSERT INTO app_forums(name,description,sort,allow_view_groups,allow_post_groups,allow_reply_groups) VALUES(?,?,?,?,?,?)", [$name, $description, $sort, ...array_values($permissions)]);
        forums_cache(true);
    }

    public static function forum_group_select_options(?array $forum = null, string $field = '', string $label = ''): string
    {
        $selected = [];
        if ($forum && $field !== '') $selected = forum_group_ids($forum, $field);
        $html = '<div class="grid"><span>' . h($label) . '</span><div class="forum-group-checks">';
        foreach (groups_cache() as $group) {
            $gid = (int)$group['id'];
            $html .= '<label class="check"><input type="checkbox" name="' . h($field) . '[]" value="' . $gid . '"' . (in_array($gid, $selected, true) ? ' checked' : '') . '><span>' . h($group['name']) . '</span></label>';
        }
        return $html . '</div></div>';
    }

    public static function save_group(): void
    {
        $name = post('name', DB_STRING_MAX_LENGTH);
        if ($name === '') err('Group name cannot be empty');
        $allow_manage = isset($_POST['allow_manage']) ? 1 : 0;
        $allow_admin = isset($_POST['allow_admin']) ? 1 : 0;
        id() ? q("UPDATE app_groups SET name=?,allow_manage=?,allow_admin=? WHERE id=?", [$name, $allow_manage, $allow_admin, id()]) : q("INSERT INTO app_groups(name,allow_manage,allow_admin) VALUES(?,?,?)", [$name, $allow_manage, $allow_admin]);
        groups_cache(true);
    }

    public static function deletable_post_row(string $type, int $id): ?array
    {
        if ($type === 'topics') return row('app_topics', 'id', $id);
        if ($type === 'replies') return row('app_replies', 'id', $id);
        return null;
    }

    public static function can_delete(string $type, int $id): bool
    {
        if ($type === 'users') return can_manage() && $id !== uid() && ($id !== 1 || is_super_user());
        if (in_array($type, ['groups', 'forums'], true)) return can_manage() && is_super_user();
        $row = self::deletable_post_row($type, $id);
        if ($type === 'topics') return $row && can_manage_topic($row);
        if ($type === 'replies') return $row && can_manage_reply($row);
        return false;
    }

    public static function layout(string $tab, string $body): string
    {
        return shell_html(admin_tabs($tab) . $body, sidebar_stack_html([sidebar_user_card_html()], ['is_admin' => true, 'admin_tab' => $tab]));
    }

    public static function settings_handle_post(): never
    {
        if ((string)($_POST['debug_log_action'] ?? '') === 'clear') {
            if (!is_dir(dirname(DEBUG_LOG_FILE))) mkdir(dirname(DEBUG_LOG_FILE), 0755, true);
            file_put_contents(DEBUG_LOG_FILE, '', LOCK_EX);
            set_flash('Debug log cleared');
            go(admin_url(['tab' => 'settings']));
        }
        if (isset($_POST['clear_opcache'])) {
            self::clear_opcache_cache();
            set_flash('OPcache cleared');
            go(admin_url(['tab' => 'settings']));
        }
        self::save_settings();
        go(admin_url(['tab' => 'settings']));
    }

    public static function settings_html(): string
    {
        $notice_state = Setup::update_state_data();
        $pending_notice = is_array($notice_state['update_notice'] ?? null) ? $notice_state['update_notice'] : [];
        $notice_sha = (string)($pending_notice['sha'] ?? ($notice_state['update_notice_sent_sha'] ?? ''));
        Setup::deliver_update_notice();
        $settings = settings_cache();
        $fields = [
            'site_name' => ['label' => 'Site name', 'required' => true],
            'site_name_title' => ['label' => 'Site name (title)', 'help' => 'Uses the site name when empty.'],
            'site_base_url' => ['label' => 'Site base URL', 'type' => 'url', 'help' => 'Enter the site domain starting with https://.'],
            'site_keywords' => ['label' => 'Keywords'],
            'site_description' => ['label' => 'Site description', 'type' => 'textarea'],
            'pinned_topic_ids' => ['label' => 'Pinned topic IDs'],
            'pc_nav_forum_count' => ['label' => 'Boards shown in PC top nav', 'type' => 'number', 'min' => 0, 'max' => 20, 'help' => 'Number of boards shown in the PC top navigation by default (default 6). Set to 0 to show only "All Boards".'],
            'topics_per_page' => ['label' => 'Topics per page', 'type' => 'number', 'min' => 1, 'max' => 200],
            'replies_per_page' => ['label' => 'Replies per page', 'type' => 'number', 'min' => 1, 'max' => 200],
            'max_pagination_pages' => ['label' => 'Max pagination pages', 'type' => 'number', 'min' => 1, 'max' => 1000, 'help' => 'Limits all pagination except topic replies. Default is 50.'],
            'username_min_length' => ['label' => 'Username min length', 'type' => 'number', 'min' => 0, 'max' => DB_STRING_MAX_LENGTH],
            'username_max_length' => ['label' => 'Username max length', 'type' => 'number', 'min' => 1, 'max' => DB_STRING_MAX_LENGTH],
            'email_min_length' => ['label' => 'Email min length', 'type' => 'number', 'min' => 0, 'max' => DB_STRING_MAX_LENGTH],
            'email_max_length' => ['label' => 'Email max length', 'type' => 'number', 'min' => 0, 'max' => DB_STRING_MAX_LENGTH],
            'bio_min_length' => ['label' => 'Bio min length', 'type' => 'number', 'min' => 0, 'max' => DB_TEXT_MAX_LENGTH],
            'bio_max_length' => ['label' => 'Bio max length', 'type' => 'number', 'min' => 0, 'max' => DB_TEXT_MAX_LENGTH],
            'search_min_length' => ['label' => 'Search keyword min length', 'type' => 'number', 'min' => 0, 'max' => DB_STRING_MAX_LENGTH],
            'search_max_length' => ['label' => 'Search keyword max length', 'type' => 'number', 'min' => 0, 'max' => DB_STRING_MAX_LENGTH],
            'title_min_length' => ['label' => 'Title min length', 'type' => 'number', 'min' => 0, 'max' => DB_STRING_MAX_LENGTH],
            'title_max_length' => ['label' => 'Title max length', 'type' => 'number', 'min' => 0, 'max' => DB_STRING_MAX_LENGTH],
            'topic_body_min_length' => ['label' => 'Topic body min length', 'type' => 'number', 'min' => 0, 'max' => DB_TEXT_MAX_LENGTH],
            'topic_body_max_length' => ['label' => 'Topic body max length', 'type' => 'number', 'min' => 0, 'max' => DB_TEXT_MAX_LENGTH],
            'reply_body_min_length' => ['label' => 'Reply body min length', 'type' => 'number', 'min' => 0, 'max' => DB_TEXT_MAX_LENGTH],
            'reply_body_max_length' => ['label' => 'Reply body max length', 'type' => 'number', 'min' => 0, 'max' => DB_TEXT_MAX_LENGTH],
            'excerpt_length' => ['label' => 'Excerpt length', 'type' => 'number', 'min' => 0, 'max' => DB_TEXT_MAX_LENGTH],
            'pretty_url' => ['label' => 'Enable rewrite (pretty URLs)', 'type' => 'checkbox'],
            'site_closed' => ['label' => 'Close site for maintenance', 'type' => 'checkbox'],
            'debug_mode' => ['label' => 'Debug mode', 'type' => 'checkbox'],
            'ignore_ssl_errors' => ['label' => 'Ignore SSL certificate errors', 'type' => 'checkbox', 'help' => 'Warning: tampering risk'],
            'allow_register' => ['label' => 'Allow registration', 'type' => 'checkbox'],
            'default_group_id' => ['label' => 'Default group for new users', 'type' => 'select', 'options' => array_column(groups_cache(), 'name', 'id')],
            'post_interval_seconds' => ['label' => 'Post/reply interval (seconds)', 'type' => 'number', 'min' => 0, 'max' => 3600, 'help' => 'Set to 0 to disable the limit. Default is 5 seconds.'],
        ];
        $tools = '<div class="settings-tool-card"><div><strong>Clear OPcache</strong><span>Refresh the compiled script cache. Useful to trigger manually after a code update.</span></div>' . post_action_form(admin_url(['tab' => 'settings']), 'Clear', ['clear_opcache' => '1'], 'settings-tool-action') . '</div>';
        if ((string)($settings['debug_mode'] ?? '0') === '1') $tools .= '<div class="settings-tool-card"><div><strong>Debug Log</strong><span>' . h(DEBUG_LOG_FILE) . '</span></div><div class="settings-tool-actions">' . post_action_form(admin_url(['tab' => 'settings']), 'Clear', ['debug_log_action' => 'clear'], 'settings-tool-action', 'Clear the debug log?') . '<a class="settings-tool-action" href="' . h(admin_url(['tab' => 'settings', 'debug_log' => 'view'])) . '" target="_blank">View</a></div></div>';
        $update_state = is_file(UPDATE_STATE_FILE) ? json_decode((string)file_get_contents(UPDATE_STATE_FILE), true) : [];
        $update_sha = is_array($update_state) ? (string)($update_state['sha'] ?? '') : '';
        $update_time = is_array($update_state) ? (string)($update_state['updated_at'] ?? '') : '';
        $update_meta = $update_sha !== '' ? 'Current version ' . $update_sha . ($update_time !== '' ? ' / ' . $update_time : '') : 'No online upgrade history yet';
        $update_action = is_file(APP_DIR . '/optional/Setup.php') ? '<a class="settings-tool-action" href="' . h(route_url('update')) . '">Upgrade</a>' : '<button class="settings-tool-action" type="button" disabled>Upgrade</button>';
        $update_dot = preg_match('/^[a-f0-9]{64}$/', $notice_sha) === 1 ? '<i class="settings-update-dot" title="New version available" aria-label="New version available"></i>' : '';
        $tools .= '<div class="settings-tool-card"><div><strong class="settings-tool-title" data-update-tool-title>System Upgrade' . $update_dot . '</strong><span>' . h($update_meta) . '</span></div>' . $update_action . '</div>';
        return '<span hidden data-settings-update-check-url="' . h(route_url('update', ['notice_check' => 1])) . '"></span><div class="form-panel settings-form"><form method="post">' . form_token() . render_form_fields($fields, $settings) . '<div class="row settings-actions"><button type="submit">Save</button></div></form><div class="settings-tool-grid">' . $tools . '</div></div>';
    }

    public static function groups_html(): string
    {
        $html = '<table class="list admin-bulk-list"><tr><th>Name</th><th>User & Content Management</th><th>Admin Access</th><th><a class="admin-head-add" href="' . h(admin_url(['do' => 'edit', 'type' => 'group', 'id' => 0])) . '">Add</a></th></tr>';
        foreach (groups_cache() as $group) $html .= '<tr><td><strong class="admin-name">' . h($group['name']) . '</strong></td><td>' . self::flag((int)($group['allow_manage'] ?? 0)) . '</td><td>' . self::flag((int)($group['allow_admin'] ?? 0)) . '</td><td class="ops"><a href="' . h(admin_url(['do' => 'edit', 'type' => 'group', 'id' => (int)$group['id']])) . '">Edit</a>' . post_action_form(admin_url(['do' => 'delete']), 'Delete', ['type' => 'groups', 'id' => (int)$group['id'], 'tab' => 'groups'], 'danger', 'Delete this item?') . '</td></tr>';
        return $html . '</table>';
    }

    public static function forums_html(): string
    {
        $html = '<table class="list admin-bulk-list"><tr><th>Name</th><th>Order</th><th>Permissions</th><th><a class="admin-head-add" href="' . h(admin_url(['do' => 'edit', 'type' => 'forum', 'id' => 0])) . '">Add</a></th></tr>';
        foreach (forums_cache() as $forum) {
            $permissions = [];
            foreach (['allow_view_groups' => 'View', 'allow_post_groups' => 'Post', 'allow_reply_groups' => 'Reply'] as $field => $label) {
                $count = count(forum_group_ids($forum, $field));
                $permissions[] = $label . ': ' . ($count ? $count . ' groups' : 'Unlimited');
            }
            $html .= '<tr><td><strong class="admin-name">' . h($forum['name']) . '</strong></td><td><span class="admin-group-pill">' . (int)$forum['sort'] . '</span></td><td>' . h(implode(' / ', $permissions)) . '</td><td class="ops"><a href="' . h(admin_url(['do' => 'edit', 'type' => 'forum', 'id' => (int)$forum['id']])) . '">Edit</a>' . post_action_form(admin_url(['do' => 'delete']), 'Delete', ['type' => 'forums', 'id' => (int)$forum['id'], 'tab' => 'forums'], 'danger', 'Delete this item?') . '</td></tr>';
        }
        return $html . '</table>';
    }

    public static function page(): void
    {
        need_admin();
        $tab = (string)($_GET['tab'] ?? 'settings');
        if ($tab === 'settings' && (string)($_GET['debug_log'] ?? '') === 'view') {
            header('Content-Type: text/plain; charset=utf-8');
            echo is_file(DEBUG_LOG_FILE) ? (string)file_get_contents(DEBUG_LOG_FILE) : '';
            exit;
        }
        if ($tab === 'plugins' && is_post_request()) Plugin::admin_plugins_handle_post();
        if ($tab === 'plugins' && (string)($_GET['plugin_action'] ?? '') === 'enable_uploaded') Plugin::plugin_enable_uploaded_page();
        if ($tab === 'settings' && is_post_request()) self::settings_handle_post();
        $html = match ($tab) {
            'settings' => self::settings_html(),
            'groups' => self::groups_html(),
            'forums' => self::forums_html(),
            'plugins' => admin_plugins_html(),
            default => Plugin::admin_plugin_tab_html($tab)
        };
        if ($html === null) err('The page you requested does not exist', 404);
        page('Admin', self::layout($tab, $html));
    }

    public static function edit_page(): void
    {
        need_admin();
        $type = $_GET['type'] ?? $_POST['type'] ?? '';
        if (is_post_request()) {
            if ($type === 'group') self::save_group();
            elseif ($type === 'forum') self::save_forum();
            else err('Invalid parameters');
            go(admin_url(['tab' => $type . 's']));
        }
        if ($type === 'group') {
            $g = id() ? (group_by_id(id()) ?: err('User group does not exist')) : ['id' => 0, 'name' => '', 'allow_manage' => 0, 'allow_admin' => 0];
            $tab = 'groups';
            $body = input('Name', 'name', $g['name'], 'text', true) . checkbox('Allow user and content management', 'allow_manage', (bool)(int)($g['allow_manage'] ?? 0)) . checkbox('Allow admin access', 'allow_admin', (bool)(int)($g['allow_admin'] ?? 0));
        } elseif ($type === 'forum') {
            $f = id() ? forum_by_id(id()) : ['id' => 0, 'name' => '', 'description' => '', 'sort' => 0, 'allow_view_groups' => '', 'allow_post_groups' => '', 'allow_reply_groups' => ''];
            if (!$f) err('Board does not exist');
            $tab = 'forums';
            $body = input('Name', 'name', $f['name'], 'text', true) . number_input('Sort order', 'sort', $f['sort']) . textarea('Description', 'description', $f['description']) . self::forum_group_select_options($f, 'allow_view_groups', 'Groups allowed to view') . self::forum_group_select_options($f, 'allow_post_groups', 'Groups allowed to post') . self::forum_group_select_options($f, 'allow_reply_groups', 'Groups allowed to reply');
        } else err('Invalid parameters');
        $is_new = id() === 0;
        $label = $type === 'group' ? 'User Group' : 'Board';
        $title = ($is_new ? 'Add ' : 'Edit ') . $label;
        $summary = '<div class="admin-plugin-summary"><strong>' . h($title) . '</strong><span>' . h($is_new
            ? 'Fill in and save; the new ' . $label . ' will appear in the list.'
            : ($type === 'group' ? 'Change the name and management permissions. Takes effect immediately after saving.' : 'Change the name, order and access permissions. Takes effect immediately after saving.')) . '</span></div>';
        page($title, self::layout($tab, '<div class="form-panel admin-form-panel"><div class="admin-form-head">' . $summary . '</div><form method="post">' . form_token() . '<input type="hidden" name="type" value="' . h($type) . '"><input type="hidden" name="id" value="' . id() . '">' . $body . '<button>Save</button></form></div>'));
    }

    public static function route(): void
    {
        $do = (string)($_GET['do'] ?? '');
        if ($do === 'edit') {
            self::edit_page();
            return;
        }
        if ($do === '') {
            self::page();
            return;
        }
        if ($do !== 'delete') err('The page you requested does not exist', 404);
        require_post();
        need_admin();
        $type = ['group' => 'groups', 'groups' => 'groups', 'forum' => 'forums', 'forums' => 'forums'][$_POST['type'] ?? ''] ?? '';
        if (!in_array($type, ['groups', 'forums'], true)) err('Invalid parameters');
        if (!self::can_delete($type, id())) err('No permission');
        del($type, id());
        go(admin_url(['tab' => $type]));
    }
}
