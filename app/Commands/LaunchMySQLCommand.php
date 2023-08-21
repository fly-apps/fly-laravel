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

class LaunchMySQLCommand extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'launch:mysql';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Create a MySQL app on Fly.io.';

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
            $this->inputMySQL($userInput, $flyIoService);
            $this->setUpMySQL($userInput, $flyIoService);
        }
        catch (ProcessFailedException $e)
        {
            $this->error($e->result->errorOutput());
            return CommandAlias::FAILURE;
        }

        // finalize
        $this->info("MySQL app '" . $userInput['app_name'] . "' is ready to go! Run 'fly-laravel deploy:mysql' to deploy it.");
        $this->line("Also, don't forget to redeploy your laravel app and run the migrations 😉");
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

    private function inputMySQL(array &$userInput, FlyIoService $flyIoService)
    {
        // make slow api calls asynchronously, so they run while the user fills in the prompts.
        $organizationsPromise = $flyIoService->getOrganizations();
        $regionsProcess = Process::start("flyctl platform regions --json");

        $laravelAppName = $flyIoService->getLaravelAppName();
        $useLaravelAppConfig = false;
        if ($laravelAppName !== '') $useLaravelAppConfig = $this->confirm("Laravel app '$laravelAppName' detected. Use this app's configuration for organization & primary region?");

        $userInput['app_name'] = $this->ask("What should the MySQL app be called?", $useLaravelAppConfig ? $laravelAppName . "-mysql" : null);

        if ($useLaravelAppConfig) $userInput['organization'] = $flyIoService->getLaravelOrganization();
        else
        {
            $organizations = $organizationsPromise->wait()
                ->throw()
                ->collect("data.organizations.nodes")
                ->toArray();
            $userInput['organization'] = $flyIoService->askOrganizationName($organizations, $this);
        }

        if ($useLaravelAppConfig) $userInput['primary_region'] = $flyIoService->getLaravelPrimaryRegion();
        else
        {
            $regionsJson = $regionsProcess->wait()
                ->throw()
                ->output();
            $userInput['primary_region'] = $flyIoService->askPrimaryregion($regionsJson, $this);
        }

        $userInput['database_name'] = $this->ask("What should the MySQL database be called?", $laravelAppName);

        $userInput['volume_name'] = $this->ask("What should the MySQL volume be called?", str_replace("-", "_", $laravelAppName . "-mysqldata"));
    }


    private function setUpMySQL($userInput, FlyIoService $flyIoService)
    {
        $this->task("MySQL: Create app on Fly.io", function () use ($flyIoService, &$userInput) {
            $flyIoService->createApp($userInput['app_name'], $userInput['organization']);
        });

        $this->task("MySQL: Create directories", function () use (&$userInput) {
            if (!file_exists(".fly")) Process::run("mkdir .fly")
                ->throw(); // should never be true, but doesn't hurt to check
            if (!file_exists(".fly/mysql")) Process::run("mkdir .fly/mysql")
                ->throw();
            return true;
        });

        $this->task("MySQL: Generate fly.toml app configuration file", function () use (&$userInput) {
            $this->generateFlyTomlMySQL($userInput, new TomlGenerator());
            return true;
        });

        $this->task("MySQL: Create random passwords and set as secrets on the app", function () use ($flyIoService, &$userInput) {
            $this->setSecretsMySQL($userInput, $flyIoService);
            return true;
        });
        $this->warn("the Laravel app's secrets have been updated but not deployed yet.");
    }

    private function generateFlyTomlMySQL(array $mysqlUserInput, TomlGenerator $generator)
    {
        // MYSQL fly.toml update
        $mysqlTomlArray = Toml::parseFile(__DIR__ . "/../../resources/templates/mysql/fly.toml");

        $mysqlTomlArray['app'] = $mysqlUserInput['app_name'];
        $mysqlTomlArray['env']['MYSQL_DATABASE'] = $mysqlUserInput['database_name'];
        $mysqlTomlArray['mounts']['source'] = $mysqlUserInput['volume_name'];

        $generator->generateToml($mysqlTomlArray, ".fly/mysql/fly.toml");

        // LARAVEL fly.toml update
        $laravelTomlArray = Toml::parseFile("fly.toml");

        $laravelTomlArray['env']['DB_CONNECTION'] = 'mysql';
        $laravelTomlArray['env']['DB_HOST'] = $mysqlUserInput['app_name'] . ".internal";
        $laravelTomlArray['env']['DB_DATABASE'] = $mysqlUserInput['database_name'];

        $generator->generateToml($laravelTomlArray, "fly.toml");
    }

    private function setSecretsMySQL(array $mysqlUserInput, FlyIoService $flyIoService)
    {
        try
        {
            //create random passwords and username
            $password = base64_encode(random_bytes(32));
            $username = base64_encode(random_bytes(32));
            $rootPassword = base64_encode(random_bytes(32));
        }
        catch (Exception $e)
        {
            throw new ProcessFailedException(Process::result("", $e->getMessage(), -1));
        }

        // MYSQL Secrets update
        $mysqlSecrets = array(
            "MYSQL_PASSWORD" => $password,
            "MYSQL_ROOT_PASSWORD" => $rootPassword,
            "MYSQL_USER" => $username
        );
        $flyIoService->setAppSecrets($mysqlUserInput['app_name'], $mysqlSecrets);

        // LARAVEL Secrets update
        $laravelSecrets = array(
            "DB_PASSWORD" => $password,
            "DB_USERNAME" => $username
        );
        $flyIoService->setAppSecrets($flyIoService->getLaravelAppName(), $laravelSecrets);
    }
}
