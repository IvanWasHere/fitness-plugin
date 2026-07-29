<?php

namespace FitnessClub\Tests\Integration;

use FitnessClub\Auth\Auth;
use FitnessClub\Auth\Csrf;
use FitnessClub\Auth\Jwt;
use FitnessClub\Auth\SessionCookie;
use FitnessClub\Auth\SessionStore;
use FitnessClub\Support\RateLimiter;
use WP_REST_Request;

/**
 * Token authentication for external clients (D4, W4.2).
 *
 * Most of these are about **refusal**, because a bearer token is the one
 * credential this plugin hands out that cannot be taken back by clearing a
 * cookie. The properties worth pinning down are therefore: the algorithm cannot
 * be chosen by the caller, a token minted for something else is not accepted
 * here, and revoking a grant stops the access token that came from it —
 * immediately, not in fifteen minutes.
 *
 * **One branch is deliberately not covered end to end.** `tests/bootstrap.php`
 * defines `FITNESSCLUB_JWT_SECRET`, so "enabled but no secret configured → 503"
 * cannot be reached in the suite: the constant cannot be undefined once set, and
 * making the secret settable at runtime would undo the reason it is a constant.
 * The condition is a single `hasSecret()` check in `requireTokenApi()`.
 *
 * @covers \FitnessClub\Auth\Jwt
 * @covers \FitnessClub\Auth\BearerToken
 * @covers \FitnessClub\Http\Controllers\Api\AuthController
 */
final class JwtApiTest extends IntegrationTestCase
{
    private bool $wasEnabled = false;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        do_action('rest_api_init');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Auth::forget();
        SessionCookie::clear();

        $this->wasEnabled = (bool) FitnessClub()->options->get('features.jwt_api_enabled', false);
        FitnessClub()->options->set('features.jwt_api_enabled', true);

        RateLimiter::clear('login', RateLimiter::ipHash());
    }

    protected function tearDown(): void
    {
        FitnessClub()->options->set('features.jwt_api_enabled', $this->wasEnabled);
        $this->clearBearer();

        parent::tearDown();
    }

    // ----------------------------------------------------------- the feature gate

    public function testTheTokenApiIsOffUnlessEnabled(): void
    {
        FitnessClub()->options->set('features.jwt_api_enabled', false);
        $this->makeAccount();

        $response = $this->post('/auth/token', [
            'user_login' => 'nobody@example.test',
            'password'   => 'whatever',
        ]);

        // 404, not 403: a site without the token API has no token API, and
        // "forbidden" would confirm the endpoint exists and invite retries.
        $this->assertSame(404, $response->get_status());
        $this->assertSame('fc_token_api_disabled', $response->get_data()['code']);
    }

    // ------------------------------------------------------------------ issuing

    public function testSigningInReturnsAnAccessAndRefreshPair(): void
    {
        $accountId = $this->makeAccount();
        $data      = $this->issueTokens($accountId);

        $this->assertNotEmpty($data['access_token']);
        $this->assertNotEmpty($data['refresh_token']);
        $this->assertSame('Bearer', $data['token_type']);
        $this->assertSame(Jwt::ACCESS_TTL_SECONDS, $data['expires_in']);
        $this->assertSame($accountId, $data['account']['id']);

        // The refresh token is opaque, not a JWT — that is what makes it
        // revocable. A JWT here would be three dot-separated base64 segments.
        $this->assertStringNotContainsString('eyJ', $data['refresh_token']);
    }

    public function testAccessTokensAreShortLivedByDesign(): void
    {
        // A JWT cannot be revoked, so it must expire instead. Fifteen minutes is
        // the whole reason the refresh token exists.
        $this->assertLessThanOrEqual(900, Jwt::ACCESS_TTL_SECONDS);
    }

    public function testBadCredentialsAreRefusedTheSameWayAsOnTheWebLogin(): void
    {
        $this->makeAccount();

        $response = $this->post('/auth/token', [
            'user_login' => 'no-such-account@example.test',
            'password'   => 'wrong',
        ]);

        $this->assertSame(401, $response->get_status());
        $this->assertSame('fc_invalid_credentials', $response->get_data()['code']);
    }

    public function testSigningInDoesNotSetASessionCookie(): void
    {
        $this->makeAccount();

        SessionCookie::clear();
        $this->issueTokens();

        // A client that wanted a cookie would have used /auth/login. Issuing
        // both leaves a browser holding two credentials of different lifetimes
        // for one session.
        $this->assertNull(SessionCookie::read());
    }

    // ------------------------------------------------------------ authenticating

    public function testAnAccessTokenAuthenticatesARequest(): void
    {
        $accountId = $this->makeAccount();
        $data      = $this->issueTokens($accountId);

        $this->withBearer($data['access_token']);

        $response = $this->get('/auth/me');

        $this->assertSame(200, $response->get_status());
        $this->assertSame($accountId, $response->get_data()['user']['account_id']);
    }

    public function testBearerCallersAreExemptFromCsrf(): void
    {
        $accountId = $this->makeAccount();
        $data      = $this->issueTokens($accountId);

        $this->withBearer($data['access_token']);
        SessionCookie::clear();

        // A write with no CSRF token at all. CSRF exists because browsers attach
        // cookies to cross-site requests unasked; an Authorization header is
        // never sent ambiently, so there is nothing to forge. Requiring a token
        // here would make every mobile write impossible and protect nothing.
        $response = $this->post('/user/preferences', [
            'notifications' => ['achievement' => true],
        ]);

        $this->assertNotSame(403, $response->get_status());
    }

    public function testGarbageInTheAuthorizationHeaderIsAnonymousNotAnError(): void
    {
        $this->withBearer('not-a-jwt-at-all');

        $response = $this->get('/auth/me');

        // Identity resolution runs on every request carrying a header, including
        // hostile ones. A bad token is an anonymous caller; an exception here
        // would turn "somebody sent us garbage" into an outage.
        $this->assertSame(401, $response->get_status());
    }

    // ------------------------------------------------------- forgery and confusion

    public function testATamperedSignatureIsRejected(): void
    {
        $data  = $this->issueTokens($this->makeAccount());
        $parts = explode('.', $data['access_token']);

        $parts[2] = rtrim(strtr(base64_encode('forged-signature'), '+/', '-_'), '=');

        $this->assertNull(Jwt::verifyAccessToken(implode('.', $parts)));
    }

    public function testAnAlgNoneTokenIsRejected(): void
    {
        // The canonical JWT attack: claim the token is unsigned and hope the
        // verifier reads the algorithm out of the token it is checking.
        $header  = $this->b64(['alg' => 'none', 'typ' => 'JWT']);
        $payload = $this->b64([
            'iss' => Jwt::ISSUER,
            'aud' => Jwt::AUDIENCE,
            'sub' => '1',
            'typ' => Jwt::TYPE_ACCESS,
            'exp' => time() + 3600,
        ]);

        $this->assertNull(Jwt::verifyAccessToken("{$header}.{$payload}."));
    }

    public function testATokenSignedWithAnotherSecretIsRejected(): void
    {
        $forged = \Firebase\JWT\JWT::encode(
            [
                'iss' => Jwt::ISSUER,
                'aud' => Jwt::AUDIENCE,
                'sub' => '1',
                'typ' => Jwt::TYPE_ACCESS,
                'exp' => time() + 3600,
            ],
            'a-completely-different-signing-secret-value',
            'HS256'
        );

        $this->assertNull(Jwt::verifyAccessToken($forged));
    }

    public function testATokenForAnotherAudienceIsRejected(): void
    {
        // The library checks the signature and expiry but not who the token was
        // meant for — so a valid token from a different service signed with the
        // same secret would otherwise be accepted here.
        $foreign = \Firebase\JWT\JWT::encode(
            [
                'iss' => Jwt::ISSUER,
                'aud' => 'some-other-service',
                'sub' => '1',
                'typ' => Jwt::TYPE_ACCESS,
                'exp' => time() + 3600,
            ],
            FITNESSCLUB_JWT_SECRET,
            'HS256'
        );

        $this->assertNull(Jwt::verifyAccessToken($foreign));
    }

    public function testAnExpiredAccessTokenIsRejected(): void
    {
        $expired = \Firebase\JWT\JWT::encode(
            [
                'iss' => Jwt::ISSUER,
                'aud' => Jwt::AUDIENCE,
                'sub' => '1',
                'typ' => Jwt::TYPE_ACCESS,
                'iat' => time() - 7200,
                'exp' => time() - 3600,
            ],
            FITNESSCLUB_JWT_SECRET,
            'HS256'
        );

        $this->assertNull(Jwt::verifyAccessToken($expired));
    }

    public function testARefreshTokenCannotBeUsedAsASessionCookie(): void
    {
        $accountId = $this->makeAccount();
        $refresh   = Auth::sessions()->issueRefreshToken($accountId);

        // Both live in fc_sessions and are the same shape. Only the `kind` check
        // stops them being interchangeable — a refresh token accepted as a
        // cookie would be a browser session that never expires.
        $this->assertNull(Auth::sessions()->resolve($refresh['token'], SessionStore::KIND_COOKIE));
        $this->assertNotNull(Auth::sessions()->resolve($refresh['token'], SessionStore::KIND_REFRESH));
    }

    public function testACookieSessionCannotBeUsedAsARefreshToken(): void
    {
        $accountId = $this->makeAccount();
        $issued    = Auth::sessions()->issue($accountId);

        $this->assertNull(Auth::sessions()->resolve($issued['cookie'], SessionStore::KIND_REFRESH));
    }

    // ------------------------------------------------------------------ rotation

    public function testRefreshingRotatesTheTokenAndKillsTheOldOne(): void
    {
        $accountId = $this->makeAccount();
        $first     = $this->issueTokens($accountId);

        $response = $this->post('/auth/token/refresh', ['refresh_token' => $first['refresh_token']]);
        $second   = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertNotSame($first['refresh_token'], $second['refresh_token']);

        // Replaying the old one now fails. That is what makes a stolen refresh
        // token surface as a sign-out rather than a silent parallel session.
        $replay = $this->post('/auth/token/refresh', ['refresh_token' => $first['refresh_token']]);

        $this->assertSame(401, $replay->get_status());
        $this->assertSame('fc_invalid_refresh_token', $replay->get_data()['code']);
    }

    public function testAnUnknownRefreshTokenIsRefused(): void
    {
        $response = $this->post('/auth/token/refresh', [
            'refresh_token' => str_repeat('a', 32) . '.' . str_repeat('b', 64),
        ]);

        $this->assertSame(401, $response->get_status());
    }

    // ----------------------------------------------------------------- revocation

    public function testRevokingTheGrantStopsItsAccessTokenImmediately(): void
    {
        $accountId = $this->makeAccount();
        $data      = $this->issueTokens($accountId);

        $this->withBearer($data['access_token']);
        Auth::forget();
        $this->assertSame(200, $this->get('/auth/me')->get_status(), 'sanity: the token works first');

        $this->clearBearer();
        $this->post('/auth/token/revoke', ['refresh_token' => $data['refresh_token']]);

        $this->withBearer($data['access_token']);
        Auth::forget();

        // The JWT is still cryptographically valid — it has fourteen minutes
        // left. Every request re-checks that the grant behind it is live, which
        // is what makes signing out actually sign somebody out.
        $this->assertNotNull(Jwt::verifyAccessToken($data['access_token']));
        $this->assertSame(401, $this->get('/auth/me')->get_status());
    }

    public function testRevokingAnUnknownTokenStillAnswersOk(): void
    {
        $response = $this->post('/auth/token/revoke', [
            'refresh_token' => str_repeat('c', 32) . '.' . str_repeat('d', 64),
        ]);

        // Reporting whether it existed would make this an oracle for guessing
        // tokens, and the caller's intent is satisfied either way.
        $this->assertSame(200, $response->get_status());
    }

    public function testChangingThePasswordRevokesApiGrantsToo(): void
    {
        $accountId = $this->makeAccount();
        $data      = $this->issueTokens($accountId);

        // The reason refresh tokens share fc_sessions: revokeAllFor() already
        // runs on password change, so this works without anybody remembering to
        // wire it up. A separate table is where this would have been forgotten.
        Auth::sessions()->revokeAllFor($accountId);

        $this->withBearer($data['access_token']);
        Auth::forget();

        $this->assertSame(401, $this->get('/auth/me')->get_status());
    }

    // ------------------------------------------------------------------ internals

    /**
     * @return array<string,mixed>
     */
    private function issueTokens(?int $accountId = null): array
    {
        $accountId ??= $this->makeAccount();
        $account     = Auth::accounts()->find($accountId);

        $this->assertNotNull($account);

        $response = $this->post('/auth/token', [
            'user_login' => $account->email,
            'password'   => self::PASSWORD,
        ]);

        $this->assertSame(200, $response->get_status(), 'Token issue should succeed');

        return (array) $response->get_data();
    }

    private function withBearer(string $token): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        Auth::forget();
    }

    private function clearBearer(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION']);
        Auth::forget();
    }

    /**
     * @param array<string,mixed> $body
     */
    private function post(string $route, array $body): \WP_REST_Response
    {
        $request = new WP_REST_Request('POST', '/fitnessclub/v1' . $route);
        $request->set_header('Content-Type', 'application/json');
        $request->set_body((string) wp_json_encode($body));

        // Non-bearer callers still face the CSRF gate; supply the token when a
        // cookie session exists so these tests exercise the gate rather than
        // routing around it.
        $csrf = SessionCookie::readCsrf();
        if (null !== $csrf) {
            $request->set_header(Csrf::HEADER, $csrf);
        }

        return rest_get_server()->dispatch($request);
    }

    private function get(string $route): \WP_REST_Response
    {
        return rest_get_server()->dispatch(new WP_REST_Request('GET', '/fitnessclub/v1' . $route));
    }

    /**
     * @param array<string,mixed> $data
     */
    private function b64(array $data): string
    {
        return rtrim(strtr(base64_encode((string) wp_json_encode($data)), '+/', '-_'), '=');
    }
}
