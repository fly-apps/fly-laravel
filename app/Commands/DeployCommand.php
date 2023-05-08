<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Process\Exceptions\ProcessFailedException;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

class DeployCommand extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'deploy {--open}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Deploy an app on Fly.io. Add the --open flag to open the app after deploying.';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        try
        {
            $result = Process::run("fly deploy")->throw();
            $this->line($result->output());

            //if --open is added, run 'fly open' before quitting.
            if ($this->option('open'))
            {
                $this->info("TODO: open the fly.io app. ");
            }
        }
        catch (ProcessFailedException $e)
        {
            $this->error($e->result->errorOutput());
            return Command::FAILURE;
        }

        //finalize
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
}