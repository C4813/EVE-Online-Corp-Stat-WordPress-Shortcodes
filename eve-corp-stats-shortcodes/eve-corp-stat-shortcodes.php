<?php
/*
Plugin Name: EVE Corp & Alliance Stat Shortcodes
Description: Adds shortcodes [zkill_stats_members id="x"], [zkill_stats_ships id="x"], and [zkill_stats_isk id="x"] to display member count, total ships destroyed and total ISK destroyed. Combine stats with [zkill_stats_members id="x,y"], or alliance stats with [zkill_stats_members id="z" type="alliance"].
Version: 1.1.0
Author: C4813
*/

defined('ABSPATH') or exit; // No direct access

// ================================
// Helpers: validation & sanitizing
// ================================

/**
 * Keep only digits and commas; trim, dedupe; cap length to prevent abuse.
 */
function ecs_normalize_ids($ids_raw) {
    $clean = preg_replace('/[^0-9,]/', '', (string) $ids_raw);
    $parts = array_filter(array_map('trim', explode(',', $clean)), 'strlen');
    $parts = array_values(array_unique($parts));
    // Cap to a reasonable number of IDs to prevent request bursts
    return array_slice($parts, 0, 10);
}

/**
 * Only allow 'corp' or 'alliance'.
 */
function ecs_normalize_type($type_raw) {
    return ($type_raw === 'alliance') ? 'alliance' : 'corp';
}

/**
 * Build a safe transient key; fallback to a hash if sanitize_key empties it.
 */
function ecs_transient_key($type, $id) {
    $candidate = sanitize_key("zkillboard_data_{$type}_{$id}");
    return $candidate ?: 'zkillboard_' . md5($type . ':' . $id);
}

// =============================
// Front-end assets (JS & CSS)
// =============================

/**
 * Enqueue minimal JS/CSS for number animation using inline handles.
 * Using enqueue hooks instead of raw echo for CSP-friendliness.
 */
function ecs_enqueue_assets() {
    // Register a dummy handle for inline script/style
    $handle = 'ecs-zkill-anim';
    wp_register_script($handle, false, array(), '1.0', true);
    wp_enqueue_script($handle);

    $js = <<<JS
document.addEventListener("DOMContentLoaded", function () {
    const animateNumber = el => {
        const target = +el.getAttribute('data-count');
        const suffix = el.getAttribute('data-suffix') || '';
        const hasDecimal = suffix !== '';
        const duration = 1000;
        const startTime = performance.now();

        function update(currentTime) {
            const progress = Math.min((currentTime - startTime) / duration, 1);
            const value = hasDecimal
                ? (progress * target).toFixed(2)
                : Math.floor(progress * target).toLocaleString();
            el.textContent = value + suffix;
            if (progress < 1) requestAnimationFrame(update);
            else el.textContent = hasDecimal
                ? target.toFixed(2) + suffix
                : target.toLocaleString() + suffix;
        }
        requestAnimationFrame(update);
    };

    const observer = new IntersectionObserver((entries, observer) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const el = entry.target;
                animateNumber(el);
                observer.unobserve(el);
            }
        });
    }, { threshold: 0.5 });

    document.querySelectorAll('.zkill-stat .number').forEach(el => {
        observer.observe(el);
    });
});
JS;

    wp_add_inline_script($handle, $js);

    $style_handle = 'ecs-zkill-style';
    wp_register_style($style_handle, false, array(), '1.0');
    wp_enqueue_style($style_handle);

    $css = <<<CSS
.zkill-stat { display: inline-block; text-align: center; margin: 10px; font-family: sans-serif; vertical-align: top; }
.zkill-stat .label { font-weight: bold; margin-bottom: 5px; display: block; }
.zkill-stat .number { font-size: 1.5em; }
CSS;

    wp_add_inline_style($style_handle, $css);
}
add_action('wp_enqueue_scripts', 'ecs_enqueue_assets');

// =============================
// Remote fetch + caching
// =============================

/**
 * Fetch and cache zKillboard stats (corp or alliance).
 */
function ecs_get_zkillboard_data($id, $type = 'corp') {
    $type = ecs_normalize_type($type);
    $typeKey = ($type === 'alliance') ? 'allianceID' : 'corporationID';

    $transient_key = ecs_transient_key($type, $id);
    $cached_data = get_transient($transient_key);
    if ($cached_data !== false) {
        return $cached_data;
    }

    $url = sprintf('https://zkillboard.com/api/stats/%s/%s/', $typeKey, rawurlencode((string) $id));

    $args = array(
        'timeout' => 5,
        'redirection' => 3,
        'headers' => array(
            // Please set a contact URL/email for good API citizenship
            'User-Agent' => 'EVE Corp Stats WP Plugin/1.1 (+your-site-or-contact)'
        ),
    );

    $response = wp_remote_get($url, $args);
    if (is_wp_error($response)) {
        return false;
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (!is_array($data)) {
        return false;
    }

    set_transient($transient_key, $data, HOUR_IN_SECONDS);
    return $data;
}

// =============================
// Rendering helpers
// =============================

/**
 * Output a single stat block safely.
 */
function ecs_zkill_stat_output($label, $value, $decimals = false, $suffix = '') {
    $raw = $decimals ? round((float) $value, 2) : (int) $value;

    $label_esc = esc_html($label);
    $count_attr = esc_attr((string) $raw);
    $suffix_attr = esc_attr((string) $suffix);

    return "<div class='zkill-stat'>
                <span class='label'>{$label_esc}</span>
                <span class='number' data-count='{$count_attr}' data-suffix='{$suffix_attr}'>0</span>
            </div>";
}

// =============================
// Aggregation
// =============================

/**
 * Combine stats for one or more IDs.
 */
function ecs_get_combined_zkill_data($ids_raw, $type_raw = 'corp') {
    $type = ecs_normalize_type($type_raw);
    $ids = ecs_normalize_ids($ids_raw);

    $combined = array(
        'memberCount'   => 0,
        'shipsDestroyed'=> 0,
        'iskDestroyed'  => 0.0,
    );

    foreach ($ids as $id) {
        $data = ecs_get_zkillboard_data($id, $type);
        if (!is_array($data)) {
            continue;
        }

        if (isset($data['info']['memberCount'])) {
            $combined['memberCount'] += (int) $data['info']['memberCount'];
        }
        if (isset($data['shipsDestroyed'])) {
            $combined['shipsDestroyed'] += (int) $data['shipsDestroyed'];
        }
        if (isset($data['iskDestroyed'])) {
            $combined['iskDestroyed'] += (float) $data['iskDestroyed'];
        }
    }

    return $combined;
}

// =============================
// Shortcodes
// =============================

function ecs_sc_members($atts) {
    $atts = shortcode_atts(array(
        'id'   => '',
        'type' => 'corp',
    ), $atts);

    if (!$atts['id']) {
        return 'No ID specified.';
    }

    $total = ecs_get_combined_zkill_data($atts['id'], $atts['type']);
    return ($total['memberCount'] > 0)
        ? ecs_zkill_stat_output('Members', $total['memberCount'], false)
        : 'N/A';
}
add_shortcode('zkill_stats_members', 'ecs_sc_members');

function ecs_sc_ships($atts) {
    $atts = shortcode_atts(array(
        'id'   => '',
        'type' => 'corp',
    ), $atts);

    if (!$atts['id']) {
        return 'No ID specified.';
    }

    $total = ecs_get_combined_zkill_data($atts['id'], $atts['type']);
    return ($total['shipsDestroyed'] > 0)
        ? ecs_zkill_stat_output('Ships Destroyed', $total['shipsDestroyed'], false)
        : 'N/A';
}
add_shortcode('zkill_stats_ships', 'ecs_sc_ships');

function ecs_sc_isk($atts) {
    $atts = shortcode_atts(array(
        'id'   => '',
        'type' => 'corp',
    ), $atts);

    if (!$atts['id']) {
        return 'No ID specified.';
    }

    $total = ecs_get_combined_zkill_data($atts['id'], $atts['type']);
    $isk = (float) $total['iskDestroyed'];
    if ($isk <= 0) {
        return 'N/A';
    }

    $suffix = '';
    $numeric = 0.0;

    if ($isk >= 1000000000000) {           // 1 trillion
        $numeric = $isk / 1000000000000;
        $suffix = 't';
    } elseif ($isk >= 1000000000) {        // 1 billion
        $numeric = $isk / 1000000000;
        $suffix = 'b';
    } elseif ($isk >= 1000000) {           // 1 million
        $numeric = $isk / 1000000;
        $suffix = 'm';
    } else {
        $numeric = $isk;
        $suffix = '';
    }

    return ecs_zkill_stat_output('ISK Destroyed', $numeric, true, $suffix);
}
add_shortcode('zkill_stats_isk', 'ecs_sc_isk');
