<?php

namespace App\Console\Commands;

use App\Models\PendingTerminalSync;
use App\Models\Personnel;
use App\Rules\PersonnelRules;
use App\Services\AccessAuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ReconcilePersonnelNames extends Command
{
    protected $signature = 'personnel:reconcile-names {file : CSV with employee_no,expected_name,name} {--apply : Apply the reviewed changes; otherwise preview only}';

    protected $description = 'Preview or apply name-only corrections to existing identities without creating or removing users.';

    public function handle(AccessAuditLogger $audit): int
    {
        $handle = @fopen($this->argument('file'), 'r');
        if (! $handle) {
            $this->error('Cannot read reconciliation CSV.');

            return self::FAILURE;
        }
        $locks = [];
        try {
            if (fgetcsv($handle) !== ['employee_no', 'expected_name', 'name']) {
                throw new \RuntimeException('CSV header must be employee_no,expected_name,name.');
            }
            $rows = [];
            while (($values = fgetcsv($handle)) !== false) {
                if ($values === [null]) {
                    continue;
                }
                if (count($values) !== 3) {
                    throw new \RuntimeException('Each CSV row must have exactly three fields.');
                }
                $row = array_combine(['employee_no', 'expected_name', 'name'], $values);
                Validator::make($row, [
                    'employee_no' => ['required', 'string', 'max:32'],
                    'expected_name' => ['required', 'string', 'max:64'],
                    'name' => PersonnelRules::name(),
                ])->validate();
                if (isset($rows[$row['employee_no']])) {
                    throw new \RuntimeException('Duplicate employee ID in CSV: '.$row['employee_no']);
                }
                $rows[$row['employee_no']] = $row;
            }
            ksort($rows);
            // Serialize with device writes and removals; never contact hardware here.
            foreach ($rows as $row) {
                $person = Personnel::where('employee_no', $row['employee_no'])->firstOrFail();
                $lock = Cache::lock('person-access:'.$person->id, 600);
                if (! $lock->get()) {
                    throw new \RuntimeException('Person is being updated; retry later: '.$row['employee_no']);
                }
                $locks[] = $lock;
            }
            $changed = DB::transaction(function () use ($rows, $audit): int {
                $changed = 0;
                foreach ($rows as $row) {
                    $person = Personnel::where('employee_no', $row['employee_no'])->firstOrFail();
                    if ($person->name === $row['name']) {
                        continue; // Idempotent re-run.
                    }
                    if ($person->name !== $row['expected_name']) {
                        throw new \RuntimeException('Name changed since review for '.$row['employee_no'].'. No changes applied.');
                    }
                    $this->line($person->employee_no.': '.$person->name.' -> '.$row['name']);
                    $changed++;
                    if (! $this->option('apply')) {
                        continue;
                    }
                    $before = $person->name;
                    $person->update(['name' => $row['name']]);
                    foreach ($person->terminals()->get() as $terminal) {
                        if (! $terminal->supports('personnel_sync')) {
                            continue;
                        }
                        // Preserve any existing permission request; it reads the latest name.
                        PendingTerminalSync::firstOrCreate(
                            ['personnel_id' => $person->id, 'terminal_id' => $terminal->id],
                            ['operation' => 'profile', 'next_attempt_at' => now(), 'last_error' => 'Name correction awaiting device confirmation.'],
                        );
                    }
                    $audit->log('personnel_name_reconciled', 'info', 'Name corrected from approved personnel list.',
                        ['before' => $before, 'after' => $person->name, 'employee_no' => $person->employee_no], personnel: $person);
                }

                return $changed;
            });
            $this->info(($this->option('apply') ? 'Applied' : 'Preview').": {$changed} name correction(s). No users created or removed.");

            return self::SUCCESS;
        } catch (\Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        } finally {
            fclose($handle);
            foreach ($locks as $lock) {
                $lock->release();
            }
        }
    }
}
