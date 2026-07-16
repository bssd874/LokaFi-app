<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DefaultCategoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DefaultCategoryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_categories_are_only_checked_once_per_user_and_service_instance(): void
    {
        $user = User::factory()->create();
        $service = app(DefaultCategoryService::class);

        $service->ensureForUser($user);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $service->ensureForUser($user);

        $this->assertSame([], DB::getQueryLog());
        $this->assertSame(14, $user->categories()->count());
    }
}
