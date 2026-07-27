<?php

namespace FitnessClub\Payments;

use FitnessClub\Support\DomainException;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Which adapter is in play (W3.2).
 *
 * Adapters register through a filter rather than a hardcoded match, so the
 * Stripe adapter — and PayPal after it — is added without editing this file.
 * That is what D6's "PayPal becomes a later adapter, not a rewrite" means in
 * practice.
 *
 * `ManualGateway` is always present and is the configured default: an install
 * with no processor set up must still be able to record a bank transfer, and a
 * registry that could resolve to nothing would make every billing call a
 * conditional at the call site.
 */
final class GatewayRegistry
{
    /** @var array<string,PaymentGateway>|null */
    private static ?array $gateways = null;

    /**
     * Every adapter this install knows about, keyed by name.
     *
     * @return array<string,PaymentGateway>
     */
    public static function all(): array
    {
        if (null !== self::$gateways) {
            return self::$gateways;
        }

        $manual = new ManualGateway();

        /**
         * Register a payment adapter.
         *
         * @param array<string,PaymentGateway> $gateways
         */
        $gateways = apply_filters('fitnessclub/payment_gateways', [$manual->name() => $manual]);

        $valid = [];

        foreach ((array) $gateways as $name => $gateway) {
            // A filter is a public extension point, so what comes back is
            // checked rather than trusted: a plugin returning a string here
            // would otherwise fail much later, inside a checkout.
            if ($gateway instanceof PaymentGateway) {
                $valid[(string) $name] = $gateway;
            }
        }

        // Manual can never be filtered away — it is the floor.
        $valid[$manual->name()] = $valid[$manual->name()] ?? $manual;

        return self::$gateways = $valid;
    }

    /**
     * The adapter for a name, or the configured one when none is given.
     */
    public static function get(?string $name = null): PaymentGateway
    {
        $name ??= (string) FitnessClub()->options->get('billing.gateway', 'manual');

        $gateway = self::all()[$name] ?? null;

        if (null === $gateway) {
            throw new DomainException(
                'fc_unknown_gateway',
                __('That payment method is not available.', 'fitnessclub'),
                400
            );
        }

        return $gateway;
    }

    /**
     * The adapter checkout should actually use.
     *
     * Falls back to manual when the configured one is not set up, rather than
     * failing: an administrator who has selected Stripe but not yet entered keys
     * should still be able to record a payment by hand, and the alternative is
     * an install that cannot take money at all until the keys land.
     */
    public static function active(): PaymentGateway
    {
        $configured = self::get();

        return $configured->isConfigured() ? $configured : self::get('manual');
    }

    /** Tests and the settings screen both need to re-resolve after a change. */
    public static function flush(): void
    {
        self::$gateways = null;
    }
}
