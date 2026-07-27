<?php

namespace FitnessClub\Auth;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Password hashing for fc_accounts.
 *
 * ## Why not wp_hash_password()
 *
 * It is a **pluggable** function. Any other plugin, or a host's mu-plugin, may
 * redefine it — and if one does, the hashing of the table *this* plugin owns
 * changes underneath it without the plugin knowing. The failure mode is either
 * "nobody can sign in after an unrelated update" or, worse, a weaker algorithm
 * substituted silently. For a credential store whose whole premise is
 * independence from WordPress, delegating the one irreplaceable operation to a
 * function a third party may replace is self-defeating.
 *
 * Two smaller reasons: `wp_check_password()` fires the `check_password` filter
 * with a `$user_id` argument that would be an fc_accounts id in a slot every
 * listener reads as a wp_users id; and on WordPress before 6.8 it produces
 * phpass hashes, so the algorithm would depend on the host's WordPress version.
 *
 * `password_hash()` is guaranteed by the PHP 8.1 floor, `password_verify()` is
 * constant-time, and `password_needs_rehash()` gives a free upgrade path when
 * the cost is raised later.
 *
 * ## The pre-hash, and the salt trap
 *
 * bcrypt silently truncates at 72 bytes and stops at the first NUL byte, so a
 * long passphrase would be quietly cut short. Pre-hashing with SHA-384 fixes
 * both.
 *
 * The HMAC key is a **fixed literal**, deliberately — never `wp_salt()`.
 * Rotating the salts in `wp-config.php` is routine, recommended, and offered as
 * a one-click button by several security plugins; if the pre-hash were keyed on
 * them, every password in fc_accounts would become unverifiable the moment
 * somebody pressed it, with no recovery but a site-wide forced reset. WordPress
 * 6.8 uses a constant (`wp-sha384`) for exactly this reason.
 */
final class PasswordHasher
{
    private const PREHASH_KEY = 'fitnessclub-sha384';

    /**
     * A real bcrypt hash, at the same cost, of a random value nobody knows —
     * used to spend the same time on an unknown login as on a wrong password.
     * See verifyDummy().
     *
     * It has to be a *valid* hash: `password_verify()` against a malformed one
     * returns false immediately, which is precisely the fast path this constant
     * exists to avoid. Verified at ~390 ms on the dev machine, matching a real
     * check at cost 12.
     */
    private const DUMMY_HASH = '$2y$12$yAA97p28jbAu0RqjDDuGwu43qQE44KSJ889KZOmp89jYf0geSqwtW';

    public static function hash(string $password): string
    {
        return password_hash(self::prehash($password), PASSWORD_BCRYPT, self::options());
    }

    public static function verify(string $password, string $hash): bool
    {
        return password_verify(self::prehash($password), $hash);
    }

    /**
     * Burn a comparable amount of time when there is no account to check
     * against.
     *
     * Owning the hash introduces a timing oracle that `wp_signon()` did not
     * have: an unknown login would return immediately while a known one spends
     * ~250 ms in bcrypt, which turns the login endpoint into an account
     * enumerator regardless of how carefully the error message is worded.
     */
    public static function verifyDummy(string $password): void
    {
        password_verify(self::prehash($password), self::DUMMY_HASH);
    }

    /**
     * Should this hash be re-written with the current cost? Checked on every
     * successful sign-in, while the plaintext is still in scope.
     */
    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_BCRYPT, self::options());
    }

    /**
     * An unusable hash: no password verifies against it.
     *
     * Used for accounts that exist but cannot yet sign in — a bootstrapped
     * account awaiting an invitation, say. A sentinel rather than an empty
     * string, because an empty `password_hash` column makes `password_verify()`
     * return false *and* emit a deprecation notice, and because "unusable" is
     * clearer read from the database than "".
     */
    public static function unusable(): string
    {
        return '!' . bin2hex(random_bytes(16));
    }

    private static function prehash(string $password): string
    {
        return base64_encode(hash_hmac('sha384', $password, self::PREHASH_KEY, true));
    }

    /**
     * @return array{cost:int}
     */
    private static function options(): array
    {
        $cost = (int) FitnessClub()->config('fitnessclub.auth.bcrypt_cost', 12);

        return ['cost' => max(10, min(15, $cost))];
    }
}
