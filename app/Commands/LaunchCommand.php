<?php

namespace App\Commands;

use App\Services\TomlGenerator;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Process\Exceptions\ProcessFailedException;
use Illuminate\Support\Facades\Http;
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
    protected $description = 'Launch an application on Fly.io';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
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

            // 1. create a fly app, including asking the app name and the organization to deploy the app in
            $appNameInput = $this->ask("Choose an app name (leave blank to generate one)"); //not putting --generate-name as the default answer to prevent it being displayed in the prompt
            $appName = '';

            $organizationName = $this->getOrganizationName();
            if ($organizationName == "") return Command::SUCCESS;

            $this->task("Create app on Fly.io", function() use($appNameInput, $organizationName, &$appName) {
                $appName = $this->createApp($appNameInput, $organizationName);
                return true;
            });

            $nodeVersion = "";
            $phpVersion = "";

            // 2. detect Node and PHP versions
            $this->task("Detect Node and PHP versions", function() use(&$nodeVersion, &$phpVersion) {
                $nodeVersion = $this->detectNodeVersion();
                $phpVersion = $this->detectPhpVersion();
                return true;
            });

            // 3. Generate fly.toml file
            $this->task("Generate fly.toml app configuration file", function() use($appName, $nodeVersion, $phpVersion) {
                $this->generateFlyToml($appName, $nodeVersion, $phpVersion, new TomlGenerator());
                return true;
            });

            // 4. Copy over .fly folder, .dockerignore and DockerFile
            $this->task("Copy over .fly directory, Dockerfile and .dockerignore", function(){
                $this->copyFiles();
                return true;
            });

            // 5. set the APP_KEY secret
            $this->task("set APP_KEY secret", function() use($appName) {
                $this->setAppKeySecret($appName);
            });

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

    private function getOrganizationName() : string
    {
        $organizations = [];
        $this->task("Retrieving your organizations on Fly.io", function() use (&$organizations) {
            $response = Http::withToken($this->getAuthToken())
                ->acceptJson()
                ->contentType("application/json")
                ->post('https://api.fly.io/graphql', ["query" => "query {currentUser {email} organizations {nodes{id slug name type viewerRole}}}"]);

            // organizations will be an array of arrays that look like this: array("id" => , "slug" => , "name" => , "type" => , "viewerRole" => )
            $organizations = $response->collect("data.organizations.nodes")->toArray();

            return $response->successful();
        });

        $organizationNames = [];
        foreach($organizations as $organization)
        {
            $organizationNames[] = $organization["type"] == "PERSONAL" ? "Personal" : $organization["name"];
        }
        if (sizeOf($organizationNames) == 1)
        {
            $this->line("Auto-selected '$organizationNames[0]' since it is the only organization found on Fly.io .");
            return $organizationNames[0];
        }

        $organizationNames[] = "Cancel";

        $choice = $this->choice("Select the organization where you want to deploy the app", $organizationNames);
        $index = array_search($choice, $organizationNames);

        if ($choice == "Cancel") return "";

        return $organizations[$index]["slug"];
    }

    private function createApp($appName, $organizationName): string
    {
        if (!$appName) $appName = "--generate-name"; //not putting this as the default answer in $this->ask so '--generate-name' is not displayed in the prompt

        if (!preg_match("/^[a-z0-9-]+$/", $appName))
        {
            throw new ProcessFailedException(Process::result("", "App names are only allowed to contain lowercase, numbers and hyphens.", -1));
        }

        $result = Process::run("flyctl apps create -o $organizationName --machines $appName")->throw();

        // In case app name is auto generated, extract app name from creation message
        if( $appName == '--generate-name' ){
            $appName = explode('New app created:', $result->output())[ 1 ];
            $appName = str_replace(array("\r", "\n", ' '), '', $appName);
        }

        return $appName;
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

    private function generateFlyToml(string $appName, string $nodeVersion, string $phpVersion, TomlGenerator $generator)
    {
        $tomlArray = Toml::parseFile(__DIR__ . "/../../resources/templates/fly.toml");

        $tomlArray['app'] = $appName;
        $tomlArray['build']['args']['NODE_VERSION'] = $nodeVersion;
        $tomlArray['build']['args']['PHP_VERSION'] = $phpVersion;

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

    private function setAppKeySecret(string $appName)
    {
        $APP_KEY = "base64:" . base64_encode(random_bytes(32)); // generate random app key, and encrypt it
        Process::run("fly secrets set APP_KEY=$APP_KEY -a $appName --stage")->throw();
    }

    private function getAuthToken() : string
    {
        $result = Process::run("fly auth token")->throw();
        return $result->output();
    }
}
