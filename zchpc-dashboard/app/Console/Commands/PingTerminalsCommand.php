<?php

namespace App\Console\Commands;

use App\Models\DeviceMonitorSettings;
use App\Models\Terminal;
use App\Services\DeviceHealthService;
use Illuminate\Console\Command;
use Shaykhnazar\HikvisionIsapi\Client\DeviceManager;

class PingTerminalsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'terminals:ping
        {--force : Check every terminal now, ignoring the configured monitoring interval}
        {--device= : Limit this run to one terminal name}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Checks terminal connectivity, stores device health and refreshes dashboard metrics.';

    /**
     * Execute the console command.
     */
    public function handle(DeviceManager $deviceManager, DeviceHealthService $health)
    {
        $settings = DeviceMonitorSettings::current();

        if (! $settings->enabled && ! $this->option('force')) {
            $this->info('Automatic device monitoring is paused. Enable it on the device dashboard, or run with --force.');

            return self::SUCCESS;
        }

        $interval = max(1, (int) $settings->interval_seconds);
        $attempts = $this->option('force') ? 1 : max(1, (int) config('devices.monitor.retry_attempts', 2));

        $terminals = Terminal::apiManaged()
            ->when($this->option('device'), fn ($query, $name) => $query->where('name', $name))
            ->orderBy('phase')
            ->orderBy('name')
            ->get();

        $checked = 0;

        foreach ($terminals as $terminal) {
            $due = $this->option('force')
                || $terminal->last_checked_at === null
                || $terminal->last_checked_at->lt(now()->subSeconds(max(1, $interval - 5)));

            if (! $due) {
                continue;
            }

            try {
                // Offline stays recorded until a real authenticated response
                // arrives; this call is the only thing that can mark a device
                // online.
                $health->check($terminal, 'scheduled', null, $attempts);
                $checked++;
            } catch (\Throwable $error) {
                $this->warn($terminal->name.': '.$error->getMessage());
            }
        }

        if ($this->option('device') === null) {
            $settings->forceFill(['last_run_at' => now()])->save();
        }

        $onlineCount = Terminal::apiManaged()->get()->filter->isOnline()->count();

        $this->info("Checked {$checked} of {$terminals->count()} terminal(s); {$onlineCount} online.");

        return self::SUCCESS;
    }
}
