<?php

namespace App\Commands;

use App\Services\GenerateFlyToml;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Process\Exceptions\ProcessFailedException;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

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
    protected $description = 'Launch an application on Fly.io';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        try {
            // 1. create a fly app, including asking the app name and setting a region
            $currentDirectoryName = array_reverse(explode("/", getcwd()))[0];
            //turn "ExampleApp" into "example-app"
            strtolower(preg_replace("([A-Z])", "-$0", lcfirst($currentDirectoryName)));

            $appName = $this->ask("Choose an app name (use '--generate-name' to generate one)", $currentDirectoryName);
            if (!$appName) $appName = "--generate-name"; //not putting this as the default answer in $this->ask so '--generate-name' is not displayed in the prompt

            if (!preg_match("/^[a-z0-9-]+$/", $appName))
            {
                $this->error("App names are only allowed to contain lowercase, numbers and hyphens.");
                return Command::FAILURE;
            }

            $result = Process::run("flyctl apps create -o personal --machines $appName")->throw();

            $this->info($result->output());

            // 2. detect Node and PHP versions
            $result = Process::run("node -v");
            if (!preg_match('/v(\d+)./', $result->output(), $matches)) {
                $this->error('could not detect Node version');
                return Command::FAILURE;
            }

            $nodeVersion = $matches[1];
            $this->line("Detected Node version: $nodeVersion");

            // Determine and include in-line the PHP version
            $phpVersion = (new \App\Services\GetPhpVersion)->get($this);

            // 3. Generate fly.toml file
            (new GenerateFlyToml($appName, $nodeVersion, $phpVersion))->get($this);

            // 4. Copy over .fly folder, .dockerignore and DockerFile

            Process::run("cp -r " . __DIR__ . "/../../resources/templates/.fly/ .fly")->throw();
            $this->line('Added folder .fly in project root');

            Process::run("cp -r " . __DIR__ . "/../../resources/templates/.dockerignore .dockerignore")->throw();
            $this->line('Added .dockerignore in project root');

            // The dockerfile is hardcoded and copied over from resources/templates/Dockerfile
            if (file_exists('Dockerfile')) {
                $this->line("Existing Dockerfile found, using that instead of the default Dockerfile.");
            } else {
                copy(__DIR__ . '/../../resources/templates/Dockerfile', 'Dockerfile');
                $this->line("Added Dockerfile in project root");
            }

            // 5. set the APP_KEY secret
            $APP_KEY = "base64:" . base64_encode(random_bytes(32)); // generate random app key, and encrypt it
            Process::run("fly secrets set APP_KEY=$APP_KEY -a $appName --stage")->throw();
            $this->line('Set APP_KEY as secret.');

            // 6. ask if user wants to deploy. If so, call the DeployCommand. Else, finalize here.
            if ($this->confirm("Do you want to deploy your app?")) {
                $this->call(DeployCommand::class);
            }
        }
        catch (ProcessFailedException $e)
        {
            $this->error($e->result->errorOutput());
            return Command::FAILURE;
        }

        // finalize
        $this->info("App '$appName' is ready to go! Run 'fly-laravel deploy' to deploy it.");
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
