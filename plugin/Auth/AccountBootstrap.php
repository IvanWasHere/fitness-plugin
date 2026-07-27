<?php

namespace FitnessClub\Auth;

use FitnessClub\Support\AppRouter;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The first accounts, created once, with their credentials shown once.
 *
 * Because the plugin owns its identity, a fresh install has nobody who can sign
 * in — not even the WordPress administrator who just activated it. So
 * activation mints an administrator account plus one example of each role, and
 * shows the generated credentials to whoever activated the plugin.
 *
 * ## Why this is not in activation.php
 *
 * wpBones includes `plugin/activation.php` **before** it runs the migrations, so
 * `fc_accounts` does not exist yet at that point. It is also not an upgrade
 * step: `Manager::run()` short-circuits on a fresh install, so a bootstrap hung
 * off a `to<N>()` method would work on a developer's existing database and
 * silently do nothing on every new site — the worst of both outcomes, because it
 * would test clean.
 *
 * So it has its own option guard and runs from UpgradeProvider on `init`, which
 * is the earliest hook guaranteed to be after the tables exist.
 *
 * ## The one-time display, and what it costs
 *
 * The generated passwords are held in a **transient with a short TTL**, rendered
 * once in an admin notice, and deleted in the same request that renders them —
 * so a refresh loses them, which is the requested behaviour.
 *
 * Stating the trade honestly: that means plaintext passwords sit in `wp_options`
 * for up to fifteen minutes. It is why the TTL is short, why the delete is
 * unconditional rather than conditional on anything rendering correctly, and why
 * the notice tells the reader to change them. Anyone who refreshes too fast
 * recovers with `wp fitnessclub account reset-password <login>`.
 */
final class AccountBootstrap
{
    public const DONE_OPTION      = 'fitnessclub_auth_bootstrapped';
    public const ACTIVATOR_OPTION = 'fitnessclub_bootstrap_activator';
    public const CREDENTIALS_TRANSIENT = 'fitnessclub_bootstrap_credentials';

    /**
     * Ids of the accounts this class created.
     *
     * An option rather than a column on `fc_accounts`, because "the installer
     * generated this one" is a fact about the *installation*, not about the
     * account — the row is an ordinary account in every other respect, and a
     * flag on it would invite code to treat it as a lesser one. The settings
     * screen intersects this list with the rows that still exist, so deleting an
     * account is all it takes to make it disappear from the list.
     */
    public const GENERATED_OPTION = 'fitnessclub_generated_accounts';

    /** How long the plaintext credentials may sit in the options table. */
    private const CREDENTIALS_TTL = 15 * MINUTE_IN_SECONDS;

    /**
     * Create the first accounts, unless that has already happened.
     *
     * Cheap enough to call on every request: one autoloaded option read in the
     * normal case.
     */
    public static function ensure(): void
    {
        global $wpdb;

        if (get_option(self::DONE_OPTION)) {
            return;
        }

        // Belt and braces for the ordering rule above. On a fresh install this
        // is already true by the time `init` fires; on a half-finished
        // activation it stops us inserting into a table that is not there.
        $table = $wpdb->prefix . 'fc_accounts';
        if ($table !== $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table))) {
            return;
        }

        $accounts = new AccountRepository();

        // Somebody has already set this up — an existing install, or a restored
        // database. Mark it done rather than minting a second administrator.
        if ($accounts->countByRole(Capabilities::ROLE_ADMIN) > 0) {
            update_option(self::DONE_OPTION, 1, false);

            return;
        }

        $created = [];

        $created[] = self::make($accounts, 'admin', Capabilities::ROLE_ADMIN, __('Administrator', 'fitnessclub'));
        $created[] = self::make($accounts, 'admin', Capabilities::ROLE_ADMIN, __('Example Admin', 'fitnessclub'));
        $created[] = self::make($accounts, 'trainer', Capabilities::ROLE_TRAINER, __('Example Trainer', 'fitnessclub'));
        $created[] = self::make($accounts, 'user', Capabilities::ROLE_USER, __('Example Member', 'fitnessclub'));

        update_option(self::DONE_OPTION, 1, false);
        update_option(self::GENERATED_OPTION, array_column($created, 'id'), false);
        set_transient(self::CREDENTIALS_TRANSIENT, $created, self::CREDENTIALS_TTL);
    }

    /**
     * The generated credentials, if they have not been shown yet.
     *
     * **Reading them consumes them.** The delete happens here rather than in the
     * caller so there is no path where they are read and left behind.
     *
     * @return array<int,array{login:string,password:string,role:string,label:string}>
     */
    public static function takeCredentials(): array
    {
        $credentials = get_transient(self::CREDENTIALS_TRANSIENT);

        if (!is_array($credentials) || [] === $credentials) {
            return [];
        }

        delete_transient(self::CREDENTIALS_TRANSIENT);
        delete_option(self::ACTIVATOR_OPTION);

        return $credentials;
    }

    /**
     * Render the credentials once, to whoever activated the plugin.
     *
     * Hooked on `admin_notices`. The audience check is deliberate: these are
     * credentials, and an editor who happens to open wp-admin at the wrong
     * moment has no business reading them.
     */
    public static function renderNotice(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $credentials = self::takeCredentials();
        if ([] === $credentials) {
            return;
        }

        // Written out inline rather than assembled into a string and printed:
        // a pre-built HTML variable is indistinguishable from an unescaped one
        // to any reviewer or linter, even when every value inside it was escaped.
        echo '<div class="notice notice-warning">';
        echo '<h2>' . esc_html__('FitnessClub accounts created', 'fitnessclub') . '</h2>';
        echo '<p>' . esc_html__(
            'FitnessClub has its own accounts — your WordPress login does not work in the app. '
            . 'These were generated for you and are shown only once: refresh this page and they are gone.',
            'fitnessclub'
        ) . '</p>';

        echo '<table class="widefat striped" style="max-width:38rem"><thead><tr>';
        echo '<th>' . esc_html__('Account', 'fitnessclub') . '</th>';
        echo '<th>' . esc_html__('Login', 'fitnessclub') . '</th>';
        echo '<th>' . esc_html__('Password', 'fitnessclub') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($credentials as $entry) {
            echo '<tr><td>' . esc_html($entry['label']) . '</td>';
            echo '<td><code>' . esc_html($entry['login']) . '</code></td>';
            echo '<td><code>' . esc_html($entry['password']) . '</code></td></tr>';
        }

        echo '</tbody></table>';
        echo '<p><strong>' . esc_html__(
            'Write these down now, then sign in and change them.',
            'fitnessclub'
        ) . '</strong></p>';
        echo '<p><a href="' . esc_url(AppRouter::url()) . '" class="button button-primary">'
            . esc_html__('Open FitnessClub', 'fitnessclub') . '</a></p>';
        echo '</div>';
    }

    /**
     * The accounts this class created that still exist.
     *
     * The intersection is the point: an administrator who deletes one — from the
     * settings screen, the CLI or the database directly — makes it vanish from
     * the list without anything having to remember to update a second record.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function generatedAccounts(): array
    {
        global $wpdb;

        $ids = array_map('intval', (array) get_option(self::GENERATED_OPTION, []));
        $ids = array_values(array_filter($ids));

        if ([] === $ids) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders only.
        $sql = "SELECT id, login, email, display_name, role, status, created_at, last_login_at
                  FROM {$wpdb->prefix}fc_accounts
                 WHERE id IN ({$placeholders})
                 ORDER BY id ASC";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared on this line.
        return $wpdb->get_results($wpdb->prepare($sql, ...$ids), ARRAY_A) ?: [];
    }

    /**
     * Was this account created by the bootstrap?
     */
    public static function isGenerated(int $accountId): bool
    {
        $ids = array_map('intval', (array) get_option(self::GENERATED_OPTION, []));

        return in_array($accountId, $ids, true);
    }

    /**
     * A generated password in the readable `pass{Word}{4}` shape.
     *
     * Public because the settings screen regenerates one when an administrator
     * loses the original, and two generators drifting apart is how you end up
     * with one that quietly stops meeting the password-length floor.
     */
    public static function generatePassword(): string
    {
        return 'pass' . self::word() . self::suffix();
    }

    /**
     * Create one account and return its plaintext credentials for display.
     *
     * @return array{id:int,login:string,password:string,role:string,label:string}
     */
    private static function make(
        AccountRepository $accounts,
        string $prefix,
        string $role,
        string $label
    ): array {
        $login    = self::uniqueLogin($accounts, $prefix);
        $password = self::generatePassword();

        $id = $accounts->create([
            'login'        => $login,
            'password'     => $password,
            'display_name' => $label,
            'role'         => $role,
            'status'       => Account::STATUS_ACTIVE,
            'locale'       => determine_locale(),
            'timezone'     => wp_timezone_string(),
        ]);

        return [
            'id'       => $id,
            'login'    => $login,
            'password' => $password,
            'role'     => $role,
            'label'    => $label,
        ];
    }

    private static function uniqueLogin(AccountRepository $accounts, string $prefix): string
    {
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $login = $prefix . self::word();

            if (!$accounts->loginExists($login)) {
                return $login;
            }
        }

        // Thirty-two words exhausted by collision is close to impossible, but a
        // loop that can fail needs an answer that cannot.
        return $prefix . self::word() . self::suffix();
    }

    private static function word(): string
    {
        $words = (array) FitnessClub()->config('fitnessclub.auth.words', []);

        if ([] === $words) {
            return 'Falcon';
        }

        return (string) $words[wp_rand(0, count($words) - 1)];
    }

    /**
     * Four characters of entropy on the end of the password.
     *
     * The readable `pass{Word}` shape on its own is one dictionary word behind a
     * public login endpoint — roughly twelve bits against a format anyone can
     * read in this file. The suffix takes it to about thirty-six, which is
     * beyond a distributed guessing attack, while staying short enough to copy
     * off a screen. The alphabet omits `0/O/1/l` for the same reason.
     */
    private static function suffix(): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $suffix   = '';

        for ($i = 0; $i < 4; $i++) {
            $suffix .= $alphabet[wp_rand(0, strlen($alphabet) - 1)];
        }

        return $suffix;
    }
}
