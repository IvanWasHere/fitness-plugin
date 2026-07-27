<?php

namespace FitnessClub\Auth;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * One row of fc_accounts, as an immutable value object.
 *
 * **`password_hash` is not on this object.** It is read only inside
 * AccountRepository's verification path and never travels with the identity, so
 * there is no route by which it reaches a response — including the accidental
 * one where somebody adds an account to a JSON payload two years from now.
 */
final class Account
{
    public const STATUS_ACTIVE    = 'active';
    public const STATUS_PENDING   = 'pending';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_DELETED   = 'deleted';

    /**
     * @param string[] $extraCaps Per-account capability grants.
     */
    private function __construct(
        public readonly int $id,
        public readonly string $login,
        public readonly ?string $email,
        public readonly string $displayName,
        public readonly string $role,
        public readonly string $status,
        public readonly ?string $locale,
        public readonly ?string $timezone,
        public readonly ?string $avatarUrl,
        public readonly array $extraCaps,
        public readonly ?string $lastLoginAt
    ) {
    }

    /**
     * @param array<string,mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $caps = json_decode((string) ($row['extra_caps'] ?? ''), true);

        return new self(
            (int) $row['id'],
            (string) $row['login'],
            '' === (string) ($row['email'] ?? '') ? null : (string) $row['email'],
            (string) ($row['display_name'] ?? ''),
            (string) ($row['role'] ?? Capabilities::ROLE_USER),
            (string) ($row['status'] ?? self::STATUS_ACTIVE),
            $row['locale'] ?? null,
            $row['timezone'] ?? null,
            $row['avatar_url'] ?? null,
            is_array($caps) ? array_map('strval', $caps) : [],
            $row['last_login_at'] ?? null
        );
    }

    public function can(string $capability): bool
    {
        return Capabilities::grants($this->role, $this->extraCaps, $capability);
    }

    /**
     * Only an active account may hold a session. A pending account has been
     * created but has not set a password; a suspended one has been switched off
     * by an administrator and must not be able to keep using a session it
     * already had.
     */
    public function isActive(): bool
    {
        return self::STATUS_ACTIVE === $this->status;
    }

    public function isAdmin(): bool
    {
        return Capabilities::ROLE_ADMIN === $this->role;
    }

    public function isTrainer(): bool
    {
        return Capabilities::ROLE_TRAINER === $this->role;
    }

    /**
     * The first name, for greetings. Falls back to the login rather than to an
     * empty string — "Hey, adminFalcon" is odd but "Hey, " is broken.
     */
    public function firstName(): string
    {
        $name  = trim($this->displayName);
        $first = '' === $name ? '' : (explode(' ', $name)[0] ?? '');

        return '' === $first ? $this->login : $first;
    }
}
