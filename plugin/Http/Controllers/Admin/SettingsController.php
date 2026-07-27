<?php

namespace FitnessClub\Http\Controllers\Admin;

use FitnessClub\Auth\AccountBootstrap;
use FitnessClub\Auth\AccountRepository;
use FitnessClub\Auth\Capabilities;
use FitnessClub\Auth\SessionStore;
use FitnessClub\Http\Controllers\Controller;
use FitnessClub\Providers\RewriteServiceProvider;
use FitnessClub\Support\AppRouter;
use FitnessClub\WPBones\View\View;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The wp-admin settings screen — `FitnessClub` in the sidebar.
 *
 * Two jobs, both of which have to live in wp-admin rather than in the SPA:
 *
 *   1. **The app's URL.** The SPA cannot own the setting that decides where the
 *      SPA is served; getting it wrong there would move the app out from under
 *      the person editing it.
 *   2. **The generated accounts.** They exist precisely because nobody could
 *      sign in yet, so the screen that lists them cannot require signing in.
 *
 * Gated on `manage_options`. That is a WordPress capability, and it is the one
 * place the plugin still defers to WordPress for authorisation — see the note in
 * config/menus.php for why there is no alternative.
 */
class SettingsController extends Controller
{
    private const NONCE_ACTION = 'fitnessclub_settings';

    /** Paths WordPress or a common plugin already answers on. */
    private const RESERVED_SLUGS = [
        'wp-admin', 'wp-content', 'wp-includes', 'wp-json', 'wp-login',
        'feed', 'rss', 'rss2', 'atom', 'embed', 'trackback', 'comments',
        'search', 'author', 'category', 'tag', 'page', 'author', 'date',
        'robots.txt', 'sitemap.xml', 'favicon.ico', 'xmlrpc.php', 'index.php',
    ];

    /**
     * GET — render the screen.
     *
     * Returns the View rather than echoing it: wpBones' `Controller::render()`
     * calls this method and renders whatever View comes back, which keeps the
     * one place that produces output inside the framework where the escaping
     * rules are already understood.
     */
    public function index(): View
    {
        $this->guard();

        return FitnessClub()->view('admin.settings', $this->viewData());
    }

    /**
     * Handle a submitted form, then redirect.
     *
     * Runs on `load-{$hook}`, before wp-admin has printed a byte — which is the
     * whole reason it is here rather than in the page callback. Always a
     * redirect, never a render: a settings screen that answers POST with HTML
     * re-submits itself on every refresh, and one of these actions deletes an
     * account.
     */
    public function handle(): void
    {
        $method = isset($_SERVER['REQUEST_METHOD'])
            ? strtoupper(sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])))
            : 'GET';

        if ('POST' !== $method) {
            return;
        }

        $this->guard();

        check_admin_referer(self::NONCE_ACTION);

        // Every read of $_POST happens here, immediately after the nonce check,
        // and the actions below take their input as arguments. That is partly so
        // the sniff can see the check, and mostly so there is exactly one place
        // to look for "what does this screen accept".
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- checked on the line above.
        $action    = isset($_POST['fc_action']) ? sanitize_key(wp_unslash($_POST['fc_action'])) : '';
        $appBase   = isset($_POST['app_base']) ? sanitize_title(wp_unslash($_POST['app_base'])) : '';
        $accountId = isset($_POST['account_id']) ? absint(wp_unslash($_POST['account_id'])) : 0;
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        $notice = match ($action) {
            'save_routing'    => $this->saveRouting($appBase),
            'delete_account'  => $this->deleteAccount($accountId),
            'reset_password'  => $this->resetPassword($accountId),
            default           => ['type' => 'error', 'message' => __('Unknown action.', 'fitnessclub')],
        };

        set_transient($this->noticeKey(), $notice, 60);

        wp_safe_redirect(admin_url('admin.php?page=fitnessclub'));
        exit;
    }

    // ------------------------------------------------------------------ actions

    /**
     * @return array{type:string,message:string}
     */
    private function saveRouting(string $submitted): array
    {
        if ('' === $submitted) {
            return [
                'type'    => 'error',
                'message' => __('The app URL cannot be empty.', 'fitnessclub'),
            ];
        }

        if (in_array($submitted, self::RESERVED_SLUGS, true)) {
            return [
                'type'    => 'error',
                'message' => sprintf(
                    /* translators: %s: the rejected slug. */
                    __('"%s" is reserved by WordPress. Choose another.', 'fitnessclub'),
                    $submitted
                ),
            ];
        }

        // A page at the same path would win or lose depending on rule order, and
        // which one you get is not something an administrator should have to
        // discover by clicking.
        if (get_page_by_path($submitted)) {
            return [
                'type'    => 'error',
                'message' => sprintf(
                    /* translators: %s: the rejected slug. */
                    __('A page already lives at /%s. Choose another.', 'fitnessclub'),
                    $submitted
                ),
            ];
        }

        $current = AppRouter::base();

        if ($submitted === $current) {
            return ['type' => 'info', 'message' => __('No change.', 'fitnessclub')];
        }

        FitnessClub()->options->set('routing.app_base', $submitted);

        // Flag, not `flush_rewrite_rules()` here. The rules registered on *this*
        // request were built from the old slug, so flushing now would bake the
        // old ones in. RewriteServiceProvider registers the new rule on the next
        // init and consumes the flag straight after — which is the redirect
        // below.
        update_option(RewriteServiceProvider::FLUSH_OPTION, true);

        return [
            'type'    => 'success',
            'message' => sprintf(
                /* translators: %s: the new app URL. */
                __('The app now lives at %s', 'fitnessclub'),
                home_url('/' . $submitted . '/')
            ),
        ];
    }

    /**
     * @return array{type:string,message:string}
     */
    private function deleteAccount(int $accountId): array
    {
        $refusal = $this->refuseUnlessDeletable($accountId);
        if (null !== $refusal) {
            return $refusal;
        }

        global $wpdb;

        $accounts = new AccountRepository();
        $account  = $accounts->find($accountId);

        // A member profile holds the account with ON DELETE RESTRICT, so it goes
        // first — and taking it means taking that member's whole history, which
        // is why this is only ever offered for the generated accounts.
        $wpdb->delete($wpdb->prefix . 'fc_users', ['account_id' => $accountId]);
        $wpdb->delete($wpdb->prefix . 'fc_trainers', ['account_id' => $accountId]);
        $wpdb->delete($wpdb->prefix . 'fc_accounts', ['id' => $accountId]);

        return [
            'type'    => 'success',
            'message' => sprintf(
                /* translators: %s: the deleted account's login. */
                __('Deleted the account %s.', 'fitnessclub'),
                $account?->login ?? (string) $accountId
            ),
        ];
    }

    /**
     * @return array{type:string,message:string,password?:string,login?:string}
     */
    private function resetPassword(int $accountId): array
    {
        if (!AccountBootstrap::isGenerated($accountId)) {
            return [
                'type'    => 'error',
                'message' => __('That is not a generated account.', 'fitnessclub'),
            ];
        }

        $accounts = new AccountRepository();
        $account  = $accounts->find($accountId);

        if (null === $account) {
            return ['type' => 'error', 'message' => __('That account no longer exists.', 'fitnessclub')];
        }

        $password = AccountBootstrap::generatePassword();

        $accounts->setPassword($accountId, $password);
        $accounts->activate($accountId);

        // A password change evicts every session, here as everywhere else.
        (new SessionStore())->revokeAllFor($accountId);

        return [
            'type'     => 'success',
            'message'  => __('New password generated. It is shown once.', 'fitnessclub'),
            'login'    => $account->login,
            'password' => $password,
        ];
    }

    /**
     * Refuse a deletion that would be a mistake rather than a choice.
     *
     * @return array{type:string,message:string}|null Null when it may proceed.
     */
    private function refuseUnlessDeletable(int $accountId): ?array
    {
        if (!AccountBootstrap::isGenerated($accountId)) {
            // Only the generated accounts are deletable here. Real accounts have
            // history behind them, and a settings screen is the wrong place to
            // destroy it — that belongs in the admin app's user management, with
            // an export and a confirmation.
            return [
                'type'    => 'error',
                'message' => __('Only generated accounts can be removed here.', 'fitnessclub'),
            ];
        }

        $accounts = new AccountRepository();
        $account  = $accounts->find($accountId);

        if (null === $account) {
            return ['type' => 'error', 'message' => __('That account no longer exists.', 'fitnessclub')];
        }

        // The lock-yourself-out check. There is no WordPress account to fall back
        // on, so the last administrator is the last way in short of WP-CLI.
        if (
            Capabilities::ROLE_ADMIN === $account->role
            && $accounts->countByRole(Capabilities::ROLE_ADMIN) <= 1
        ) {
            return [
                'type'    => 'error',
                'message' => __(
                    'This is the only administrator account. Create another one first, '
                    . 'or nobody will be able to sign in to the app.',
                    'fitnessclub'
                ),
            ];
        }

        return null;
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @return array<string,mixed>
     */
    private function viewData(): array
    {
        $notice = get_transient($this->noticeKey());
        if (is_array($notice)) {
            delete_transient($this->noticeKey());
        }

        return [
            'appBase'      => AppRouter::base(),
            'appUrl'       => AppRouter::url(),
            'homeUrl'      => trailingslashit(home_url()),
            'accounts'     => AccountBootstrap::generatedAccounts(),
            'notice'       => is_array($notice) ? $notice : null,
            'nonceAction'  => self::NONCE_ACTION,
            'roleLabels'   => [
                Capabilities::ROLE_ADMIN   => __('Administrator', 'fitnessclub'),
                Capabilities::ROLE_TRAINER => __('Trainer', 'fitnessclub'),
                Capabilities::ROLE_USER    => __('Member', 'fitnessclub'),
            ],
        ];
    }

    /**
     * Notices survive the post-redirect-get in a short transient keyed to the
     * WordPress user, so two administrators working at once do not read each
     * other's messages — one of which may contain a password.
     */
    private function noticeKey(): string
    {
        return 'fitnessclub_admin_notice_' . get_current_user_id();
    }

    private function guard(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to manage FitnessClub.', 'fitnessclub'));
        }
    }
}
