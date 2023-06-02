<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Process\Exceptions\ProcessFailedException;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

class DeployMySQLCommand extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'deploy:mysql';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Deploy a MySQL application on Fly.io.';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        try
        {
            $process = Process::timeout(180)->start("fly deploy -c .fly/mysql/fly.toml", function (string $type, string $output) {
                $this->line($output);
            });
            $result = $process->wait()->throw();
            $this->line($result->output());

            $this->checkResources();
        }
        catch (ProcessFailedException $e)
        {
            $this->error($e->result->errorOutput());
            return Command::FAILURE;
        }

        //finalize
        $this->info("MySQL app deployed successfully!");
        return Command::SUCCESS;
    }

    /**
     * Define the command's schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    public function schedule(Schedule $schedule): void
    {
        // $schedule->command(static::class)->everyMinute();
    }

    private function checkResources()
    {
        $result = Process::run("fly scale show --json -c .fly/mysql/fly.toml")->throw();
        $resources = json_decode($result->output(), true);
//        $resources will look like this:
//        {
//        "Process": "app",
//        "Count": 2,
//        "CPUKind": "shared",
//        "CPUs": 1,
//        "Memory": 256,
//        "Regions": {
//            "ams": 2
//            }
//        }

        foreach($resources as $resource)
        {
            $process = $resource['Process'];
            $count = $resource['Count'];
            $cpuKind = $resource['CPUKind'];
            $cpus = $resource['CPUs'];
            $memory = $resource['Memory'];
            $regions = $resource['Regions'];

            // display scale info for each process group
            $this->line("Resources of Process Group '$process': Machines: $count | CPU: $cpus, $cpuKind | Memory: $memory Mb");

            //Show a warning if the database has less than 1GB of ram
            if ($memory < 1048) $this->warn("Warning: Process group $process only has $memory MB of ram configured. Consider giving the database more breathing room by scaling the app: https://fly.io/docs/apps/scale-machine");
        }
    }
}
