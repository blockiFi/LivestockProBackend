<?php

namespace Tests;

use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Run the seeder class directly. `$this->seed()` goes through artisan
        // and can leave sqlite with an extra open transaction.
        if (Schema::hasTable('subscription_plans')) {
            (new SubscriptionPlanSeeder)->run();
        }
    }
}