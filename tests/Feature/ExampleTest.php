<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_signed_out_visit_to_the_home_page_goes_to_the_sign_in_page(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_the_health_route_answers_without_a_session(): void
    {
        $this->get('/up')->assertOk();
    }
}
