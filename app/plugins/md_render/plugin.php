<?php
if (!defined('APP_ROOT')) exit;

/*
 * md_render — safe-subset Markdown renderer for post bodies.
 *
 * Hooks core's `markdown.render`: when this plugin returns a string, the
 * core uses it as-is and skips its own plain-text path, so @mentions are
 * re-applied here through the core's own content_special_links_html()
 * (identical @user and @user #floor behaviour).
 *
 * Safety: every byte of user text is HTML-escaped before any markup is
 * generated; the only tags emitted are the ones built below. Links are
 * restricted to http/https. All patterns are line-oriented or use bounded
 * quantifiers, so a 20 KB body renders in linear-ish time.
 */

/** Store a rendered HTML fragment behind an untypeable placeholder token. */
function md_render_token(array &$tokens, string $html): string
{
    $key = "\x1AMD" . count($tokens) . "\x1A";
    $tokens[$key] = $html;
    return $key;
}

/** Bold / italic on an already-escaped fragment that contains no tokens. */
function md_render_emphasis(string $s): string
{
    $s = preg_replace('/\*\*([^*\n]{1,400})\*\*/', '<strong>$1</strong>', $s) ?? $s;
    $s = preg_replace('/__([^_\n]{1,400})__/', '<strong>$1</strong>', $s) ?? $s;
    $s = preg_replace('/\*([^*\n]{1,400})\*/', '<em>$1</em>', $s) ?? $s;
    $s = preg_replace('/(?<![\p{L}\p{N}_])_([^_\n]{1,400})_(?![\p{L}\p{N}_])/u', '<em>$1</em>', $s) ?? $s;
    return $s;
}

/** Inline rendering for one raw (unescaped) text segment. */
function md_render_inline(string $raw, int $topic_id): string
{
    $s = h($raw);
    $tokens = [];

    // Inline code spans: content stays literal, no formatting inside.
    $s = preg_replace_callback('/`([^`\n]{1,1000})`/', static function (array $m) use (&$tokens): string {
        return md_render_token($tokens, '<code>' . $m[1] . '</code>');
    }, $s) ?? $s;

    // Markdown links, http/https only. Anything else stays literal text,
    // so [x](javascript:...) can never become an anchor.
    $s = preg_replace_callback('/\[([^\]\n]{1,300})\]\((https?:\/\/[^\s()]{1,512})\)/i', static function (array $m) use (&$tokens): string {
        $label = md_render_emphasis($m[1]);
        return md_render_token($tokens, '<a href="' . $m[2] . '" target="_blank" rel="noopener nofollow">' . $label . '</a>');
    }, $s) ?? $s;

    // Bare URL autolink. The text is already escaped, so a quote typed next
    // to a URL arrives as &quot; — cut the URL there and drop trailing
    // sentence punctuation.
    $s = preg_replace_callback('/(^|[\s(])((?:https?:\/\/)[^\s<]{1,512})/', static function (array $m) use (&$tokens): string {
        $url = preg_split('/&(?:quot|lt|gt|#0?39);/', $m[2])[0];
        $trail = '';
        while ($url !== '' && str_contains('.,;:!?', substr($url, -1))) {
            $trail = substr($url, -1) . $trail;
            $url = substr($url, 0, -1);
        }
        if (str_ends_with($url, ')') && !str_contains($url, '(')) {
            $trail = ')' . $trail;
            $url = substr($url, 0, -1);
        }
        if ($url === '' || !preg_match('#^https?://#i', $url)) return $m[0];
        return $m[1] . md_render_token($tokens, '<a href="' . $url . '" target="_blank" rel="noopener nofollow">' . $url . '</a>') . $trail;
    }, $s) ?? $s;

    // @mentions exactly like the core plain path (covers @user #floor too).
    $s = content_special_links_html($s, $topic_id);

    $s = md_render_emphasis($s);

    return strtr($s, $tokens);
}

/** Split off fenced code blocks, replacing each with a placeholder line. */
function md_render_extract_fences(string $text, array &$code_blocks): string
{
    $code_blocks = [];
    $out = [];
    $in_fence = false;
    $buf = [];
    foreach (explode("\n", $text) as $line) {
        if (!$in_fence && preg_match('/^```[a-zA-Z0-9_-]*\s*$/', $line)) {
            $in_fence = true;
            $buf = [];
            continue;
        }
        if ($in_fence && preg_match('/^```\s*$/', $line)) {
            $code_blocks[] = implode("\n", $buf);
            $out[] = "\x1AMDCODE" . (count($code_blocks) - 1) . "\x1A";
            $in_fence = false;
            continue;
        }
        if ($in_fence) {
            $buf[] = $line;
            continue;
        }
        $out[] = $line;
    }
    if ($in_fence) { // unclosed fence: the rest is code
        $code_blocks[] = implode("\n", $buf);
        $out[] = "\x1AMDCODE" . (count($code_blocks) - 1) . "\x1A";
    }
    return implode("\n", $out);
}

/** Block-level rendering. $text is core-normalized (trimmed, \n endings). */
function md_render_blocks(string $text, int $topic_id): string
{
    $code_blocks = [];
    $text = md_render_extract_fences($text, $code_blocks);

    $html = [];
    $para = [];   // raw lines of the current paragraph
    $quote = [];  // raw lines of the current blockquote
    $list_type = ''; // '', 'ul' or 'ol'
    $list_items = [];

    $flush_para = static function () use (&$html, &$para, $topic_id): void {
        if (!$para) return;
        $lines = array_map(static fn(string $l): string => md_render_inline($l, $topic_id), $para);
        $html[] = '<p>' . implode('<br>', $lines) . '</p>';
        $para = [];
    };
    $flush_quote = static function () use (&$html, &$quote, $topic_id): void {
        if (!$quote) return;
        $lines = array_map(static fn(string $l): string => md_render_inline($l, $topic_id), $quote);
        $html[] = '<blockquote><p>' . implode('<br>', $lines) . '</p></blockquote>';
        $quote = [];
    };
    $flush_list = static function () use (&$html, &$list_type, &$list_items, $topic_id): void {
        if ($list_type === '') return;
        $items = array_map(static fn(string $l): string => '<li>' . md_render_inline($l, $topic_id) . '</li>', $list_items);
        $html[] = '<' . $list_type . ' class="md-render-list">' . implode('', $items) . '</' . $list_type . '>';
        $list_type = '';
        $list_items = [];
    };
    $flush_all = static function () use ($flush_para, $flush_quote, $flush_list): void {
        $flush_para();
        $flush_quote();
        $flush_list();
    };

    foreach (explode("\n", $text) as $line) {
        if (preg_match('/^\x1AMDCODE(\d+)\x1A$/', $line, $m)) {
            $flush_all();
            $html[] = '<pre><code>' . h($code_blocks[(int)$m[1]] ?? '') . '</code></pre>';
            continue;
        }
        if (trim($line) === '') {
            $flush_all();
            continue;
        }
        if (preg_match('/^(#{1,6})\s+(.+?)\s*#*\s*$/', $line, $m)) {
            $flush_all();
            $tag = 'h' . min(6, strlen($m[1]) + 2); // # -> h3 ... #### and up -> h6
            $html[] = '<' . $tag . ' class="md-render-h">' . md_render_inline($m[2], $topic_id) . '</' . $tag . '>';
            continue;
        }
        if (preg_match('/^\s{0,3}(?:-{3,}|\*{3,}|_{3,})\s*$/', $line)) {
            $flush_all();
            $html[] = '<hr class="md-render-hr">';
            continue;
        }
        if (preg_match('/^\s{0,3}>\s?(.*)$/', $line, $m)) {
            $flush_para();
            $flush_list();
            $quote[] = $m[1];
            continue;
        }
        if (preg_match('/^\s{0,3}[-*+]\s+(.*)$/', $line, $m)) {
            $flush_para();
            $flush_quote();
            if ($list_type !== 'ul') {
                $flush_list();
                $list_type = 'ul';
            }
            $list_items[] = $m[1];
            continue;
        }
        if (preg_match('/^\s{0,3}\d{1,9}[.)]\s+(.*)$/', $line, $m)) {
            $flush_para();
            $flush_quote();
            if ($list_type !== 'ol') {
                $flush_list();
                $list_type = 'ol';
            }
            $list_items[] = $m[1];
            continue;
        }
        $flush_quote();
        $flush_list();
        $para[] = $line;
    }
    $flush_all();

    return implode("\n", $html);
}

/** markdown.render hook: ($value, $ctx) with $ctx text/quote_depth/topic_id. */
function md_render_markdown($value, array $ctx): ?string
{
    $text = (string)($ctx['text'] ?? '');
    if (trim($text) === '') return '';
    $topic_id = (int)($ctx['topic_id'] ?? 0);
    try {
        return md_render_blocks($text, $topic_id);
    } catch (Throwable) {
        // Never break a page (the core disables a plugin whose hook throws):
        // fall back to the core's own plain rendering.
        return '<p>' . str_replace("\n", '<br>', content_special_links_html(h(trim($text)), $topic_id)) . '</p>';
    }
}

function md_render_css(): string
{
    return <<<'CSS'
.md-render-h { margin: 14px 0 6px; line-height: 1.4; font-weight: 700; color: var(--text) }
h3.md-render-h { font-size: var(--font-size-xl) }
h4.md-render-h { font-size: var(--font-size-lg) }
h5.md-render-h, h6.md-render-h { font-size: var(--font-size-md) }
.md-render-list { margin: 4px 0 10px; padding-left: 22px }
.md-render-list li { margin: 2px 0 }
.md-render-hr { border: 0; border-top: 1px solid var(--line); margin: 12px 0 }
CSS;
}

return [
    'id' => 'md_render',
    'name' => 'Markdown Format',
    'version' => '1.0.0',
    'description' => 'Write posts with headings, lists, quotes and code blocks using simple Markdown formatting.',
    'author' => 'AIBOT Play',
    'assets' => ['css' => 'md_render_css'],
    'hooks' => ['markdown.render' => 'md_render_markdown'],
];
