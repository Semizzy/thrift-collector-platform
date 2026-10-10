<?php

namespace Tests\Feature;

use Tests\TestCase;

class DeploymentHealthTest extends TestCase
{
    public function test_laravel_health_route_is_available_for_host_smoke_checks(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_versioned_api_health_route_returns_a_stable_non_sensitive_payload(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertExactJson([
                'status' => 'ok',
                'service' => 'thrift-collector-platform-api',
                'api_version' => 'v1',
            ]);
    }

    public function test_supported_country_endpoint_exposes_configured_currencies_without_secrets(): void
    {
        $response = $this->getJson('/api/v1/countries')->assertOk();

        $countries = collect($response->json('data'))->keyBy('code');

        $this->assertSame('NGN', $countries->get('NG')['currency'] ?? null);
        $this->assertSame('GHS', $countries->get('GH')['currency'] ?? null);
        $this->assertArrayNotHasKey('api_key', $response->json('data.0') ?? []);
        $this->assertArrayNotHasKey('secret', $response->json('data.0') ?? []);
    }
}
