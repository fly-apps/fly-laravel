<?php

namespace App\Commands;

use App\Services\FlyIoService;
use App\Services\TomlGenerator;
use Exception;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Process\Exceptions\ProcessFailedException;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Command\Command as CommandAlias;
use Yosymfony\Toml\Toml;

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
    protected $description = 'Launch a Laravel application on Fly.io';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle(FlyIoService $flyIoService)
    {
        try
        {
            // First, check if a fly.toml is already present. If so, suggest to use the deployCommand instead.
            if (file_exists('fly.toml'))
            {
                $flyTomlArray = Toml::parseFile('fly.toml');
                $foundAppName = $flyTomlArray['app'];
                $this->line("An existing fly.toml file was found with app name '$foundAppName'");
                if ($this->confirm("Do you want to run the deploy command instead?"))
                {
                    $this->call(DeployCommand::class);
                } else return CommandAlias::SUCCESS;
            }

            // Ask the user all of our questions
            $userInput = [];

            $this->inputLaravel($userInput, $flyIoService);
            if ($userInput['organization'] == "") return CommandAlias::SUCCESS;

            // Set up the Laravel app
            $this->setUpLaravel($userInput, $flyIoService);

            // Ask if user wants to deploy. If so, call the DeployCommand. Else, finalize here.
            if ($this->confirm("Do you want to deploy your app?"))
            {
                return $this->call(DeployCommand::class);
            } else
            {
                // finalize
                $this->info("App " . $userInput['app_name'] . " is ready to go! Run 'fly-laravel deploy' to deploy it.");
                return CommandAlias::SUCCESS;
            }
        }
        catch (ProcessFailedException $e)
        {
            $this->error($e->result->errorOutput());
            return CommandAlias::FAILURE;
        }
        catch (RequestException $e)
        {
            $this->error($e->getMessage());
            return CommandAlias::FAILURE;
        }
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

    private function inputLaravel(array &$userInput, FlyIoService $flyIoService)
    {
        // make slow api calls asynchronously, so they run while the user fills in the prompts.
        $organizationsPromise = $flyIoService->getOrganizations();
        $regionsProcess = Process::start("flyctl platform regions --json");

        $processes = array("cron", "queue worker", "none");

        $userInput['app_name'] = $this->ask("Choose an app name (leave blank to generate one)");
        $userInput['app_name'] = $flyIoService->validateAppName($userInput['app_name']);

        $organizations = $organizationsPromise->wait()
            ->throw()
            ->collect("data.organizations.nodes")
            ->toArray();
        $userInput['organization'] = $flyIoService->askOrganizationName($organizations, $this);

        $regionsJson = $regionsProcess->wait()
            ->throw()
            ->output();
        $userInput['primary_region'] = $flyIoService->askPrimaryRegion($regionsJson, $this);

        $userInput['additional_processes'] = $this->choice("Select additional processes to run (comma-separated)", $processes, 2, null, true);

        $userInput['node_version'] = $this->detectNodeVersion();
        $userInput['php_version'] = $this->detectPhpVersion();
    }

    private function setupLaravel(array &$userInput, FlyIoService $flyIoService)
    {
        // Create a fly app
        $this->task("Create app on Fly.io", function () use ($flyIoService, &$userInput) {
            $userInput['app_name'] = $flyIoService->createApp($userInput['app_name'], $userInput['organization']);
            return true;
        });

        // Generate fly.toml file
        $this->task("Generate fly.toml app configuration file", function () use ($userInput) {
            $this->generateFlyToml($userInput, new TomlGenerator());
            return true;
        });

        // Copy over .fly folder, .dockerignore and DockerFile
        $this->task("Copy over .fly directory, Dockerfile and .dockerignore", function () {
            $this->copyFiles();
            return true;
        });

        // Set the APP_KEY secret
        $this->task("set APP_KEY secret", function () use ($flyIoService, $userInput) {
            $this->setAppKeySecret($userInput['app_name'], $flyIoService);
        });
    }

    private function detectNodeVersion(): string
    {
        $result = Process::run("node -v");
        if (!preg_match('/v(\d+)./', $result->output(), $matches))
        {
            throw new ProcessFailedException(Process::result("", "Could not detect Node version", -1));
        }

        return $matches[1];
    }

    private function detectPhpVersion(): string
    {
        // Default Version
        $defaultVersion = "8.0";
        $minimumVersion = "7.4";

        $resultVersion = phpversion();

        // use default version if no version can be found
        if (!$resultVersion)
        {
            $this->line("Could not find PHP version, using PHP $defaultVersion instead.");
            return $defaultVersion;
        }

        // if version is below $minimumVersion, use that instead
        if (version_compare($resultVersion, $minimumVersion, "<"))
        {
            $this->line("PHP version is below minimum supported version $minimumVersion, using PHP $minimumVersion instead.");
            return $minimumVersion;
        }

        // found version is OK, use it.
        // $resultVersion has 3 digits, like this: 8.1.10 . We only need two so take only the first 2.
        $resultArray = explode(".", $resultVersion);
        return "$resultArray[0].$resultArray[1]";
    }

    private function generateFlyToml(array $userInput, TomlGenerator $generator)
    {
        $tomlArray = Toml::parseFile(__DIR__ . "/../../resources/templates/fly.toml");

        $tomlArray['app'] = $userInput['app_name'];
        $tomlArray['primary_region'] = $userInput['primary_region'];

        $tomlArray['build']['args']['NODE_VERSION'] = $userInput['node_version'];
        $tomlArray['build']['args']['PHP_VERSION'] = $userInput['php_version'];

        if ($userInput['additional_processes'])
        {
            $tomlArray['processes'] += ['app' => ''];
            foreach($userInput['additional_processes'] as $process)
            {
                switch ($process){
                    case 'cron':
                        $tomlArray['processes'] += ['cron' => 'cron -f'];
                        break;
                    case 'queue worker':
                        $tomlArray['processes'] += ['worker' => 'php artisan queue:listen'];
                        break;
                }
            }
        }

        $generator->generateToml($tomlArray, "fly.toml");
    }

    private function copyFiles()
    {
        Process::run("cp -r " . __DIR__ . "/../../resources/templates/.fly/ .fly")
               ->throw();

        Process::run("cp -r " . __DIR__ . "/../../resources/templates/.dockerignore .dockerignore")
               ->throw();

        // The dockerfile is hardcoded and copied over from resources/templates/Dockerfile
        if (file_exists('Dockerfile'))
        {
            $this->line("Existing Dockerfile found, using that instead of the default Dockerfile.");
        } else
        {
            Process::run("cp " . __DIR__ . '/../../resources/templates/Dockerfile Dockerfile')
                   ->throw();
        }
    }

    private function setAppKeySecret(string $appName, FlyIoService $flyIoService)
    {
        try
        {
            $APP_KEY = "base64:" . base64_encode(random_bytes(32)); // generate random app key, and encrypt it
            $flyIoService->setAppSecrets($appName, ["APP_KEY" => $APP_KEY]);
        }
        catch (Exception $e)
        {
            throw new ProcessFailedException(Process::result("", $e->getMessage(), -1));
        }
    }
}
