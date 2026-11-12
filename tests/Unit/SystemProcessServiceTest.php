<?php

namespace Tests\Unit;

use App\Services\SystemProcessService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SystemProcessServiceTest extends TestCase
{
    protected SystemProcessService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SystemProcessService();
        Cache::flush();
    }

    public function test_run_with_lock_executes_callback_and_records_completion(): void
    {
        $executed = false;

        $res = $this->service->runWithLock('test_proc', function () use (&$executed) {
            $executed = true;
            return 'sample_result';
        });

        $this->assertTrue($res['executed']);
        $this->assertEquals('sample_result', $res['result']);
        $this->assertTrue($executed);

        $status = $this->service->getStatus('test_proc');
        $this->assertFalse($status['is_running']);
        $this->assertEquals('success', $status['last_status']);
        $this->assertNotNull($status['last_finished_at']);
    }

    public function test_run_with_lock_prevents_overlapping_parallel_execution(): void
    {
        $this->service->updateStatus('test_overlap', [
            'is_running' => true,
            'started_at' => now()->toIso8601String(),
            'last_status' => 'running',
        ]);

        $secondExecuted = false;
        $res = $this->service->runWithLock('test_overlap', function () use (&$secondExecuted) {
            $secondExecuted = true;
        });

        $this->assertFalse($res['executed']);
        $this->assertEquals('already_running', $res['status']);
        $this->assertFalse($secondExecuted);
        $this->assertStringContainsString('Dublēta palaišana novērsta', $res['message']);
    }

    public function test_get_disk_space_returns_valid_metrics(): void
    {
        $disk = $this->service->getDiskSpace();

        $this->assertTrue($disk['available']);
        $this->assertGreaterThan(0, $disk['total_bytes']);
        $this->assertGreaterThan(0, $disk['free_bytes']);
        $this->assertNotEmpty($disk['free_human']);
        $this->assertNotEmpty($disk['total_human']);
        $this->assertIsNumeric($disk['percentage']);
        $this->assertContains($disk['status_color'], ['emerald', 'amber', 'rose']);
    }

    public function test_stale_running_process_auto_resets_after_timeout(): void
    {
        // Simulate process that was marked running 3 hours ago and crashed
        $threeHoursAgo = Carbon::now()->subHours(3)->toIso8601String();
        $this->service->updateStatus('stale_proc', [
            'is_running' => true,
            'started_at' => $threeHoursAgo,
            'last_status' => 'running',
        ]);

        $status = $this->service->getStatus('stale_proc');
        $this->assertFalse($status['is_running']);
        $this->assertEquals('timed_out', $status['last_status']);
    }
}

