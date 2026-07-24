<?php
/**
 * Mount point for the admin SPA.
 *
 * Intentionally minimal: a container and the boot payload. Everything else is
 * React (plans/05-admin-app.md). Styles are scoped to #fc-admin-app so they do
 * not fight WordPress's admin CSS.
 *
 * @var array $boot
 */

if (!defined('ABSPATH')) {
    exit();
}
?>
<div class="wrap">
    <div id="fc-admin-app" data-boot="<?php echo esc_attr(wp_json_encode($boot)); ?>">
        <noscript><?php esc_html_e('FitnessClub requires JavaScript.', 'fitnessclub'); ?></noscript>
    </div>
</div>
