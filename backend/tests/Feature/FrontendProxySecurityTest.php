<?php

namespace Tests\Feature;

use Tests\TestCase;

class FrontendProxySecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.frontend_api_proxy', true);
        config()->set('security.frontend_proxy_signing_secret', str_repeat('s', 64));
        config()->set('security.frontend_proxy_signature_ttl_seconds', 90);
    }

    protected function tearDown(): void
    {
        config()->set('app.frontend_api_proxy', false);

        parent::tearDown();
    }

    public function test_unsigned_and_spoofed_direct_api_requests_are_rejected(): void
    {
        $this->withHeaders(['X-Forwarded-For' => '8.8.8.8'])
            ->getJson('/api/auth/me')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'PROXY_VERIFICATION_FAILED');
    }

    public function test_a_valid_recent_proxy_signature_reaches_api_authentication(): void
    {
        $timestamp = (string) now()->timestamp;
        $ip = '8.8.8.8';
        $signature = hash_hmac(
            'sha256',
            implode("\n", [$timestamp, 'GET', '/api/auth/me', $ip]),
            str_repeat('s', 64)
        );

        $this->withHeaders([
            'X-DILG-Client-IP' => $ip,
            'X-DILG-Proxy-Timestamp' => $timestamp,
            'X-DILG-Proxy-Signature' => $signature,
        ])->getJson('/api/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_secret_whitespace_and_explicit_proxy_path_are_supported(): void
    {
        config()->set('security.frontend_proxy_signing_secret', '  '.str_repeat('s', 64)."\n");

        $timestamp = (string) now()->timestamp;
        $ip = '8.8.8.8';
        $signature = hash_hmac(
            'sha256',
            implode("\n", [$timestamp, 'GET', '/api/auth/me', $ip]),
            str_repeat('s', 64)
        );

        $this->withHeaders([
            'X-DILG-Client-IP' => $ip,
            'X-DILG-Proxy-Path' => '/api/auth/me',
            'X-DILG-Proxy-Timestamp' => $timestamp,
            'X-DILG-Proxy-Signature' => $signature,
        ])->getJson('/api/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_an_expired_proxy_signature_is_rejected(): void
    {
        $timestamp = (string) now()->subMinutes(5)->timestamp;
        $ip = '8.8.8.8';
        $signature = hash_hmac(
            'sha256',
            implode("\n", [$timestamp, 'GET', '/api/auth/me', $ip]),
            str_repeat('s', 64)
        );

        $this->withHeaders([
            'X-DILG-Client-IP' => $ip,
            'X-DILG-Proxy-Timestamp' => $timestamp,
            'X-DILG-Proxy-Signature' => $signature,
        ])->getJson('/api/auth/me')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'PROXY_VERIFICATION_FAILED');
    }

    public function test_a_valid_ipv6_proxy_signature_is_supported(): void
    {
        $timestamp = (string) now()->timestamp;
        $ip = '2001:4860:4860:0:0:0:0:8888';
        $signature = hash_hmac(
            'sha256',
            implode("\n", [$timestamp, 'GET', '/api/auth/me', $ip]),
            str_repeat('s', 64)
        );

        $this->withHeaders([
            'X-DILG-Client-IP' => $ip,
            'X-DILG-Proxy-Timestamp' => $timestamp,
            'X-DILG-Proxy-Signature' => $signature,
        ])->getJson('/api/auth/me')
            ->assertUnauthorized();
    }
}
