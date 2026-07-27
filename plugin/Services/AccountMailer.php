<?php

namespace FitnessClub\Services;

use FitnessClub\Auth\Account;
use FitnessClub\Support\AppRouter;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Mail for the account lifecycle: reset a password, accept an invitation, and
 * the notice that a password changed.
 *
 * This replaces WordPress' `retrieve_password()` machinery, which the plugin no
 * longer goes through — and with it the old `usesApp()` branch that sent
 * administrators to `wp-login.php` and everyone else to the app. There are no
 * WordPress users in this flow now, so every account gets the app link.
 *
 * Plain text on purpose. An HTML mail here buys nothing and costs a rendering
 * surface, and reset mail is the one message that must survive every client.
 */
final class AccountMailer
{
    public function sendPasswordReset(Account $account, string $token): bool
    {
        if (null === $account->email) {
            return false;
        }

        $url = add_query_arg('token', rawurlencode($token), AppRouter::url('reset'));

        $message = sprintf(
            /* translators: 1: display name, 2: site name, 3: reset URL, 4: number of minutes. */
            __(
                "Hi %1\$s,\n\n"
                . "Someone asked to reset the password for your %2\$s account.\n\n"
                . "%3\$s\n\n"
                . "The link works once and expires in %4\$d minutes. If you did not ask for "
                . "this, you can ignore this message — your password will not change.\n",
                'fitnessclub'
            ),
            $account->firstName(),
            $this->siteName(),
            $url,
            (int) FitnessClub()->config('fitnessclub.auth.reset_ttl_minutes', 60)
        );

        return $this->send(
            $account->email,
            sprintf(
                /* translators: %s: site name. */
                __('Reset your %s password', 'fitnessclub'),
                $this->siteName()
            ),
            $message
        );
    }

    /**
     * The "set your password" mail for an account that has one but has never
     * used it — the same mechanics as a reset, different words.
     */
    public function sendInvite(Account $account, string $token): bool
    {
        if (null === $account->email) {
            return false;
        }

        $url = add_query_arg('token', rawurlencode($token), AppRouter::url('reset'));

        $message = sprintf(
            /* translators: 1: display name, 2: site name, 3: invite URL, 4: number of hours. */
            __(
                "Hi %1\$s,\n\n"
                . "An account has been created for you on %2\$s. Choose a password to get "
                . "started:\n\n"
                . "%3\$s\n\n"
                . "The link works once and expires in %4\$d hours.\n",
                'fitnessclub'
            ),
            $account->firstName(),
            $this->siteName(),
            $url,
            (int) FitnessClub()->config('fitnessclub.auth.invite_ttl_hours', 72)
        );

        return $this->send(
            $account->email,
            sprintf(
                /* translators: %s: site name. */
                __('Your %s account', 'fitnessclub'),
                $this->siteName()
            ),
            $message
        );
    }

    /**
     * Tell an account its password changed.
     *
     * This is the tripwire: if the owner did not do it, this message is how they
     * find out their email has been compromised. It is sent after the change,
     * never instead of it.
     */
    public function sendPasswordChanged(Account $account): bool
    {
        if (null === $account->email) {
            return false;
        }

        $message = sprintf(
            /* translators: 1: display name, 2: site name, 3: sign-in URL. */
            __(
                "Hi %1\$s,\n\n"
                . "The password for your %2\$s account was just changed, and every device "
                . "that was signed in has been signed out.\n\n"
                . "If this was not you, reset your password immediately:\n%3\$s\n",
                'fitnessclub'
            ),
            $account->firstName(),
            $this->siteName(),
            AppRouter::url('forgot')
        );

        return $this->send(
            $account->email,
            sprintf(
                /* translators: %s: site name. */
                __('Your %s password was changed', 'fitnessclub'),
                $this->siteName()
            ),
            $message
        );
    }

    private function send(string $to, string $subject, string $message): bool
    {
        return (bool) wp_mail($to, $subject, $message);
    }

    private function siteName(): string
    {
        return wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);
    }
}
