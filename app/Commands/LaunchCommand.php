<?php

namespace App\Commands;

use App\Services\FlyIoService;
use App\Services\TomlGenerator;
use App\Services\VolumeService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Process\Exceptions\ProcessFailedException;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;
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
       
        try {
            // First, check if a fly.toml is already present. If so, suggest to use the deployCommand instead.
            if (file_exists('fly.toml')) {
                $flyTomlArray = Toml::parseFile('fly.toml');
                $foundAppName = $flyTomlArray['app'];
                $this->line("An existing fly.toml file was found with app name '$foundAppName'");
                if ($this->confirm("Do you want to run the deploy command instead?")) {
                    $this->call(DeployCommand::class);
                } else return Command::SUCCESS;
            }

            // Ask the user all of our questions
            $appNameInput = $this->ask("Choose an app name (leave blank to generate one)"); //not putting --generate-name as the default answer to prevent it being displayed in the prompt
            $appName = '';

            $organizationName = $this->getOrganizationName($flyIoService);
            if ($organizationName == "") return Command::SUCCESS;

            // 1. create a fly app, including asking the app name and the organization to deploy the app in
            $this->task("Create app on Fly.io", function() use($flyIoService, $appNameInput, $organizationName, &$appName) {
                $appName = $flyIoService->createApp($appNameInput, $organizationName);
                return true;
            });

            // 2. Ask user for processes to add
            $processes = [];
            $this->task("Set additional process groups", function() use(&$processes) {
                $processes = $this->setProcesses();
                return true;
            });

            // 3. Set Volume
            $mount = [];
            $volumeService = new VolumeService($this);
            $this->task("Set Volume", function() use(&$mount, $volumeService, $appName){
                $mount = $volumeService->promptVolume( $appName );
            });

            // 3. Detect Node and PHP versions
            $nodeVersion = "";
            $phpVersion = "";

            $this->task("Detect Node and PHP versions", function() use(&$nodeVersion, &$phpVersion) {
                $nodeVersion = $this->detectNodeVersion();
                $phpVersion = $this->detectPhpVersion();
                return true;
            });

            // 4. Generate fly.toml file
            $this->task("Generate fly.toml app configuration file", function() use($appName, $nodeVersion, $phpVersion, $processes, $mount) {
                $this->generateFlyToml($appName, $nodeVersion, $phpVersion, $processes, $mount, new TomlGenerator());
                return true;
            });

            // 5. Copy over .fly folder, .dockerignore and DockerFile
            $this->task("Copy over .fly directory, Dockerfile and .dockerignore", function(){
                $this->copyFiles();
                return true;
            });

            // Set Up Volume
            if( $mount ){
                $this->task("Set up script for Volume", function() use($volumeService){
                    $volumeService->setUpVolumeScript();
                    return true;
                });
            }

            // 6. Set the APP_KEY secret
            $this->task("set APP_KEY secret", function() use($flyIoService, $appName) {
                $this->setAppKeySecret($appName, $flyIoService);
            });

            // 7. Ask if user wants to deploy. If so, call the DeployCommand. Else, finalize here.
            if ($this->confirm("Do you want to deploy your app?")) {
                return $this->call(DeployCommand::class,['--cleanVolumeSetup']);
            }
            else
            {
                // finalize
                $this->info("App '$appName' is ready to go! Run 'fly-laravel deploy' to deploy it.");
                return Command::SUCCESS;
            }
        }
        catch (ProcessFailedException $e)
        {
            $this->error($e->result->errorOutput());
            return Command::FAILURE;
        }
    }

    /**
     * Define the command's schedule.
     *
     * @param  Schedule  $schedule
     * @return void
     */
    public function schedule(Schedule $schedule): void
    {
        // $schedule->command(static::class)->everyMinute();
    }

    private function getOrganizationName(FlyIoService $flyIoService) : string
    {
        $organizations = [];
        $this->task("Retrieving your organizations on Fly.io", function() use ($flyIoService, &$organizations) {
            $organizations = $flyIoService->getOrganizations();
            return true;
        });

        $organizationNames = [];
        foreach($organizations as $organization)
        {
            $organizationNames[] = $organization["type"] == "PERSONAL" ? "Personal" : $organization["name"];
        }

        if (sizeOf($organizationNames) == 1)
        {
            $this->line("Auto-selected '$organizationNames[0]' since it is the only organization found on Fly.io .");
            return $organizations[0]['slug'];
        }

        $choice = $this->choice("Select the organization where you want to deploy the app", $organizationNames);
        $index = array_search($choice, $organizationNames);

        if ($choice == "Cancel") return "";

        return $organizations[$index]["slug"];
    }

    private function detectNodeVersion() : string
    {
        $result = Process::run("node -v");
        if (!preg_match('/v(\d+)./', $result->output(), $matches)) {
            throw new ProcessFailedException(Process::result("","Could not detect Node version", -1));
        }

        $nodeVersion = $matches[1];
        return $nodeVersion;
    }

    private function detectPhpVersion()
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
        $resultVersion = "$resultArray[0].$resultArray[1]";
        return $resultVersion;
    }

    private function setProcesses( array $processes=[] ): array
    {
        // Command List
        $none = 'none';
        $commands = [
            'cron'   => ['Scheduler    ', 'cron -f'],
            'worker' => ['Queue Workers', 'php artisan queue:listen'],
            $none    => ['None'],
        ];

        // Set choices to choose from based on Command List
        $selections = $this->choice(
            "Select additional processes to run ( Comma separate keys or Leave blank to run none )",
            (function($choices=[]) use($commands, $none){
                foreach($commands as $key=>$command)
                    $choices[$key] = $key==$none? $command[0]: "$command[0] - This will run '$command[1]' in a separate process group";
                return $choices;
            })(),
            $none, null, true
        );

        // Set processes to run based on selections
        foreach( $selections as $selection ){
            if( $selection == $none ){
                $this->line( "Additional processes to run: ".$commands[$none][0] );
                return [];
            }else
                $processes[$selection] = $commands[$selection][1];
        }

        // Inform user of selected processes
        $this->line( "Additional processes to run: ".implode(", ", array_keys($processes)) );
        $processes = ['app'=>''] + $processes;
        return $processes;
    }

    private function generateFlyToml(string $appName, string $nodeVersion, string $phpVersion, array $processes, array $volume, TomlGenerator $generator)
    {
        $tomlArray = Toml::parseFile(__DIR__ . "/../../resources/templates/fly.toml");

        $tomlArray['app'] = $appName;
        $tomlArray['build']['args']['NODE_VERSION'] = $nodeVersion;
        $tomlArray['build']['args']['PHP_VERSION'] = $phpVersion;

        if( $volume )
            $tomlArray['mounts'] = $volume; 

        if( $processes )
            $tomlArray['processes'] = $processes;

        $generator->generateToml($tomlArray, "fly.toml");
    }

    private function copyFiles()
    {
        Process::run("cp -r " . __DIR__ . "/../../resources/templates/.fly/ .fly")->throw();

        Process::run("cp -r " . __DIR__ . "/../../resources/templates/.dockerignore .dockerignore")->throw();

        // The dockerfile is hardcoded and copied over from resources/templates/Dockerfile
        if (file_exists('Dockerfile'))
        {
            $this->line("Existing Dockerfile found, using that instead of the default Dockerfile.");
        }
        else
        {
            Process::run("cp " . __DIR__ . '/../../resources/templates/Dockerfile Dockerfile')->throw();
        }
    }

    private function setAppKeySecret(string $appName, FlyIoService $flyIoService)
    {
        $APP_KEY = "base64:" . base64_encode(random_bytes(32)); // generate random app key, and encrypt it
        $flyIoService->setAppSecrets($appName, ["APP_KEY" => $APP_KEY]);
    }
}
