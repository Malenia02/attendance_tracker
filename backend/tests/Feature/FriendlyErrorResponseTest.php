<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class FriendlyErrorResponseTest extends TestCase
{
    public function test_unhandled_api_errors_never_expose_exception_details_even_with_debug_enabled(): void
    {
        config(['app.debug' => true]);

        Route::get('/api/test-unhandled-error', function (): never {
            throw new RuntimeException('Private database connection details');
        });

        $response = $this->withHeader('X-Request-ID', 'friendly-api-error-test')
            ->getJson('/api/test-unhandled-error');

        $response->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonPath('request_id', 'friendly-api-error-test')
            ->assertJsonMissingPath('exception')
            ->assertJsonMissingPath('trace')
            ->assertDontSee('Private database connection details');
        $this->assertStringContainsString('Please try again', $response->json('message'));
    }

    public function test_unhandled_web_errors_show_help_instead_of_debug_details(): void
    {
        config(['app.debug' => true]);

        Route::get('/health/test-unhandled-error', function (): never {
            throw new RuntimeException('Private database connection details');
        });

        $this->withHeader('X-Request-ID', 'friendly-web-error-test')
            ->get('/health/test-unhandled-error')
            ->assertStatus(500)
            ->assertSee('Something did not load correctly')
            ->assertSee('friendly-web-error-test')
            ->assertDontSee('Private database connection details')
            ->assertDontSee('Stack trace');
    }

    public function test_missing_web_page_shows_next_step_without_an_error_code(): void
    {
        config(['app.debug' => true]);

        $this->get('/health/page-does-not-exist')
            ->assertStatus(404)
            ->assertSee('We could not find that page')
            ->assertSee('Return to AttendanceHub')
            ->assertDontSee('>404<');
    }

    public function test_web_validation_errors_keep_their_status_without_showing_field_data(): void
    {
        Route::get('/health/test-validation-error', function (): never {
            throw ValidationException::withMessages(['private_field' => 'Private field value']);
        });

        $this->get('/health/test-validation-error')
            ->assertStatus(422)
            ->assertSee('Please check your information')
            ->assertDontSee('Private field value');
    }
}
