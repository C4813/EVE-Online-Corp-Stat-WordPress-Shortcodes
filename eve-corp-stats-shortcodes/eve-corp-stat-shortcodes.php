<?php
/*
Plugin Name: EVE Corp & Alliance Stat Shortcodes
Description: Adds shortcodes [zkill_stats_members id="x"], [zkill_stats_ships id="x"], and [zkill_stats_isk id="x"] to display member count, total ships destroyed and total ISK destroyed. Combine stats with [zkill_stats_members id="x,y"], or alliance stats with [zkill_stats_members id="x" type="alliance"].
Version: 1.0.1
Author: C4813
*/

defined('ABSPATH') or die('No script kiddies please!');

// Enqueue animation script and styles
function enqueue_zkill_animated_script() {
    ?>
    <script>
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
    </script>
    <style>
    .zkill-stat {
        display: inline-block;
        text-align: center;
        margin: 10px;
        font-family: sans-serif;
        vertical-align: top;
    }
    .zkill-stat .label {
        font-weight: bold;
        margin-bottom: 5px;
        display: block;
    }
    .zkill-stat .number {
        font-size: 1.5em;
    }
    </style>
    <?php
}
add_action('wp_footer', 'enqueue_zkill_animated_script');

// Fetch and cache zKillboard stats (corp or alliance)
function get_zkillboard_data($id, $type = 'corp') {
    $typeKey = $type === 'alliance' ? 'allianceID' : 'corporationID';
    $transient_key = "zkillboard_data_{$type}_{$id}";
    $cached_data = get_transient($transient_key);

    if ($cached_data !== false) return $cached_data;

    $url = "https://zkillboard.com/api/stats/{$typeKey}/{$id}/";
    $response = wp_remote_get($url);

    if (is_wp_error($response)) return false;

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (!$data) return false;

    set_transient($transient_key, $data, 3600); // Cache 1 hour
    return $data;
}

// Output wrapper
function zkill_stat_output($label, $value, $decimals = false, $suffix = '') {
    $raw = $decimals ? round($value, 2) : intval($value);
    return "<div class='zkill-stat'>
                <span class='label'>{$label}</span>
                <span class='number' data-count='{$raw}' data-suffix='{$suffix}'>0</span>
            </div>";
}

// Combine stats for one or more IDs
function get_combined_zkill_data($ids, $type = 'corp') {
    $ids = array_map('trim', explode(',', $ids));
    $combined = [
        'memberCount' => 0,
        'shipsDestroyed' => 0,
        'iskDestroyed' => 0
    ];

    foreach ($ids as $id) {
        $data = get_zkillboard_data($id, $type);
        if (!$data) continue;

        if (isset($data['info']['memberCount'])) {
            $combined['memberCount'] += $data['info']['memberCount'];
        }
        if (isset($data['shipsDestroyed'])) {
            $combined['shipsDestroyed'] += $data['shipsDestroyed'];
        }
        if (isset($data['iskDestroyed'])) {
            $combined['iskDestroyed'] += $data['iskDestroyed'];
        }
    }

    return $combined;
}

// Shortcodes

function corp_member_count_shortcode($atts) {
    $atts = shortcode_atts([
        'id' => '',
        'type' => 'corp'
    ], $atts);
    if (!$atts['id']) return 'No ID specified.';
    $total = get_combined_zkill_data($atts['id'], $atts['type']);
    return $total['memberCount']
        ? zkill_stat_output('Members', $total['memberCount'], false)
        : 'N/A';
}
add_shortcode('zkill_stats_members', 'corp_member_count_shortcode');

function corp_ships_destroyed_shortcode($atts) {
    $atts = shortcode_atts([
        'id' => '',
        'type' => 'corp'
    ], $atts);
    if (!$atts['id']) return 'No ID specified.';
    $total = get_combined_zkill_data($atts['id'], $atts['type']);
    return $total['shipsDestroyed']
        ? zkill_stat_output('Ships Destroyed', $total['shipsDestroyed'], false)
        : 'N/A';
}
add_shortcode('zkill_stats_ships', 'corp_ships_destroyed_shortcode');

function corp_isk_destroyed_shortcode($atts) {
    $atts = shortcode_atts([
        'id' => '',
        'type' => 'corp'
    ], $atts);
    if (!$atts['id']) return 'No ID specified.';
    $total = get_combined_zkill_data($atts['id'], $atts['type']);
    $isk = $total['iskDestroyed'];
    if ($isk === 0) return 'N/A';

    $suffix = '';
    $numeric = 0;

    if ($isk >= 1_000_000_000_000) {
        $numeric = $isk / 1_000_000_000_000;
        $suffix = 't';
    } elseif ($isk >= 1_000_000_000) {
        $numeric = $isk / 1_000_000_000;
        $suffix = 'b';
    } elseif ($isk >= 1_000_000) {
        $numeric = $isk / 1_000_000;
        $suffix = 'm';
    } else {
        $numeric = $isk;
        $suffix = '';
    }

    return zkill_stat_output('ISK Destroyed', $numeric, true, $suffix);
}
add_shortcode('zkill_stats_isk', 'corp_isk_destroyed_shortcode');
