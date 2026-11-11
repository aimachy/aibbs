<?php
if (!defined('APP_ROOT')) exit;

/*
 * rate_guard — implements the core's security.rate_allow / security.rate_hit
 * hooks (the core calls them around login and registration but ships no
 * handler). Only hits are counted: failed logins (bucket "login_fail") and
 * completed registrations (bucket "register"), per IP, in a one-hour
 * window stored in a single app_settings row. Successful logins never
 * consume quota. Once an IP reaches the limit, the core itself refuses
 * further attempts with its "Too many attempts" error.
 *
 * Limits default to 60 per bucket per hour and can be overridden with the
 * site setting "security.rate_limits" as JSON, e.g. {"login_fail":60}.
 * Any internal error fails open: a broken limiter must never lock the
 * whole site out.
 */

function rate_guard_limits(): array
{
    $limits = ['login_fail' => 60, 'register' => 60];
    $custom = json_decode(setting('security.rate_limits'), true);
    if (is_array($custom)) {
        foreach ($limits as $bucket => $limit) {
            if (isset($custom[$bucket]) && (int)$custom[$bucket] > 0) $limits[$bucket] = (int)$custom[$bucket];
        }
    }
    return $limits;
}

/** Current window's counters; a stale stored window reads as empty. */
function rate_guard_state(): array
{
    $window = intdiv(time(), 3600);
    $state = json_decode(setting('rate_guard'), true);
    return is_array($state) && (int)($state['w'] ?? 0) === $window ? $state : ['w' => $window];
}

function rate_guard_allow($value, array $ctx)
{
    try {
        $bucket = (string)($ctx['bucket'] ?? '');
        $limits = rate_guard_limits();
        if (!isset($limits[$bucket])) return $value;
        $state = rate_guard_state();
        return (int)($state[$bucket][(string)($ctx['ip'] ?? '')] ?? 0) < $limits[$bucket];
    } catch (Throwable) {
        return $value;
    }
}

function rate_guard_hit($value, array $ctx)
{
    try {
        $bucket = (string)($ctx['bucket'] ?? '');
        $ip = (string)($ctx['ip'] ?? '');
        if ($ip === '' || !isset(rate_guard_limits()[$bucket])) return null;
        $state = rate_guard_state();
        $state[$bucket][$ip] = (int)($state[$bucket][$ip] ?? 0) + 1;
        save_settings_values(['rate_guard' => json_encode($state, JSON_THROW_ON_ERROR)]);
    } catch (Throwable) {
        // Counting must never break the login/registration flow itself.
    }
    return null;
}

return [
    'id' => 'rate_guard',
    'name' => 'Rate Guard',
    'version' => '1.0.0',
    'description' => 'Slows down repeated failed sign-in attempts and mass sign-ups from the same address.',
    'author' => 'AIBOT Play',
    'hooks' => [
        'security.rate_allow' => 'rate_guard_allow',
        'security.rate_hit' => 'rate_guard_hit',
    ],
];
