<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Command\Command as CommandAlias;
use function PHPUnit\Framework\throwException;

class Launch extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'launch';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Launch an application on Fly.io';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        // 1. create a fly app, including asking the app name and setting a region

        $appName = $this->ask("Choose an app name (leave blank to generate one)");
        if (!$appName) $appName = "--generate-name"; //not putting this as the default answer in $this->ask so '--generate-name' is not displayed in the prompt

        $result = Process::run("flyctl apps create -o personal --machines $appName");

        if ($result->failed())
        {
            $this->error($result->errorOutput());
            return Command::FAILURE;
        }
        $this->info($result->output());
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
