<?php

namespace App\Commands;

use App\Services\FlyIoService;
use App\Services\TomlGenerator;
use Exception;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Process\Exceptions\ProcessFailedException;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Command\Command as CommandAlias;
use Yosymfony\Toml\Toml;

class LaunchRedisCommand extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'launch:redis';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Create a Redis app on Fly.io';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle(FlyIoService $flyIoService)
    {
        try
        {
            $userInput = [];
            $this->inputRedis($userInput, $flyIoService);
            $this->setUpRedis($userInput, $flyIoService);
        }
        catch (ProcessFailedException $e)
        {
            $this->error($e->result->errorOutput());
            return CommandAlias::FAILURE;
        }

        // finalize
        $this->info("Redis app '" . $userInput['app_name'] . "' is ready to go! Run 'fly-laravel deploy:redis' to deploy it.");
        return CommandAlias::SUCCESS;
    }

    /**
     * Define the command's schedule.
     *
     * @param Schedule $schedule
     * @return void
     */
    public function schedule(Schedule $schedule): void
    {
        // $schedule->command(static::class)->everyMinute();
    }

    private function inputRedis(array &$userInput, FlyIoService $flyIoService)
    {
        $organizationsPromise = $flyIoService->getOrganizations();

        $laravelAppName = $flyIoService->getLaravelAppName();
        $useLaravelAppConfig = false;
        if ($laravelAppName !== '') $useLaravelAppConfig = $this->confirm("Laravel app '$laravelAppName' detected. Use this app's configuration for organization & primary region?");

        $userInput['app_name'] = $this->ask("What should the Redis app be called?", $useLaravelAppConfig ? $laravelAppName . "-redis" : null);

        if ($useLaravelAppConfig) $userInput['organization'] = $flyIoService->getLaravelOrganization();
        else
        {
            $organizations = $organizationsPromise->wait()
                ->throw()
                ->collect("data.organizations.nodes")
                ->toArray();
            $userInput['organization'] = $flyIoService->askOrganizationName($organizations, $this);
        }

        $userInput['volume_name'] = $this->ask("What should the Redis volume be called?", str_replace("-", "_", $laravelAppName . "-redisdata"));
    }

    private function setUpRedis(array &$userInput, FlyIoService $flyIoService)
    {
        $this->task("Redis: Create app on Fly.io", function () use ($flyIoService, &$userInput) {
            $flyIoService->createApp($userInput['app_name'], $userInput['organization']);
        });

        $this->task("Redis: Create directories", function () {
            if (!file_exists(".fly")) Process::run("mkdir .fly")
                                             ->throw(); // should never be true, but doesn't hurt to check
            if (!file_exists(".fly/redis")) Process::run("mkdir .fly/redis")
                                                   ->throw();
            return true;
        });

        $this->task("Redis: Generate fly.toml app configuration file", function () use (&$userInput) {
            $this->generateFlyTomlRedis($userInput, new TomlGenerator());
            return true;
        });

        $this->task("Redis: Create random passwords and set as secrets on the app", function () use ($flyIoService, $userInput) {
            $this->setSecretsRedis($userInput, $flyIoService);
            return true;
        });
        $this->warn("the Laravel app's secrets have been updated but not deployed yet.");
    }

    private function generateFlyTomlRedis(array $userInput, TomlGenerator $generator)
    {
        // REDIS fly.toml update
        $redisTomlArray = Toml::parseFile(__DIR__ . "/../../resources/templates/redis/fly.toml");

        $redisTomlArray['app'] = $userInput['app_name'];
        $redisTomlArray['mounts']['source'] = $userInput['volume_name'];

        $generator->generateToml($redisTomlArray, ".fly/redis/fly.toml");

        // LARAVEL fly.toml update
        $laravelTomlArray = Toml::parseFile("fly.toml");

        $laravelTomlArray['env']['CACHE_DRIVER'] = 'redis';
        $laravelTomlArray['env']['SESSION_DRIVER'] = 'redis';
        $laravelTomlArray['env']['REDIS_HOST'] = $userInput['app_name'] . ".internal";

        $generator->generateToml($laravelTomlArray, "fly.toml");
    }

    private function setSecretsRedis($userInput, FlyIoService $flyIoService)
    {
        try
        {
            $secrets = array("REDIS_PASSWORD" => base64_encode(random_bytes(32)));
        }
        catch (Exception $e)
        {
            throw new ProcessFailedException(Process::result("", $e->getMessage(), -1));
        }

        // REDIS secrets
        $flyIoService->setAppSecrets($userInput['app_name'], $secrets);

        // LARAVEL secrets
        $flyIoService->setAppSecrets($flyIoService->getLaravelAppName(), $secrets);
    }
}
