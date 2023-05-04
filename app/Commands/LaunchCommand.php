<?php

namespace App\Commands;

use App\Providers\AppServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Command\Command as CommandAlias;
use function PHPUnit\Framework\throwException;

class LaunchCommand extends Command
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
    protected $description = 'LaunchCommand an application on Fly.io';

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

        // 2. detect Node and PHP versions

        $result = Process::run("node -v");
        if (!preg_match('/v(\d+)./', $result->output(), $matches))
        {
            $this->error('could not detect Node version');
            return Command::FAILURE;
        }

        $nodeVersion = $matches[1];
        $this->line("Detected Node version: $nodeVersion");

        // Determine and include in-line the PHP version
        $phpVersion = (new \App\Services\GetPhpVersion)->get( $this );

        // 3. Generate fly.toml file
        (new \App\Services\GenerateFlyToml( $appName, $nodeVersion, $phpVersion ))->get( $this );

        // 4. Copy over .fly folder, .dockerignore and DockerFile

        $result = Process::run("cp -r " . __DIR__ . "/../../resources/templates/.fly/ .fly");
        if ($result->successful()) $this->line('Added folder .fly in project root');
        else
        {
            $this->error($result->output());
            return Command::FAILURE;
        }

        $result = Process::run("cp -r " . __DIR__ . "/../../resources/templates/.dockerignore .dockerignore");
        if ($result->successful()) $this->line('Added .dockerignore in project root');
        else
        {
            $this->error($result->output());
            return Command::FAILURE;
        }

        // The dockerfile is hardcoded and copied over from resources/templates/Dockerfile
        if (file_exists('Dockerfile'))
        {
            $this->line("Existing Dockerfile found, using that instead of the default Dockerfile.");
        }
        else
        {

            copy(__DIR__ . '/../../resources/templates/Dockerfile', 'Dockerfile');
            $this->line("Added Dockerfile");
        }

        // 5 set the APP_KEY secret
        $APP_KEY = "base64:" . base64_encode(random_bytes(32)); // generate random app key, and encrypt it
        $result = Process::run("fly secrets set APP_KEY=$APP_KEY -a $appName --stage");
        if ($result->successful()) $this->line('Set APP_KEY as secret.');
        else
        {
            $this->error($result->output());
            return Command::FAILURE;
        }

        //finalize
        $this->info("App '$appName' is ready to go!" );
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
