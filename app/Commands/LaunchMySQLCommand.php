<?php

namespace App\Commands;

use App\Services\TomlGenerator;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Process\Exceptions\ProcessFailedException;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;
use PhpSchool\CliMenu\CliMenu;
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
    public function handle()
    {
        try {
            $userInput = [];
            $this->inputMySQL($userInput);
            $this->setUpMySQL($userInput);
        }
        catch (ProcessFailedException $e)
        {
            $this->error($e->result->errorOutput());
            return Command::FAILURE;
        }

        // finalize
        $this->info("MySQL app '" . $userInput['app_name'] . "' is ready to go! Run 'fly-laravel deploy:mysql' to deploy it.");
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

    private function inputMySQL(array &$userInput)
    {
        $laravelAppName = $this->getLaravelAppName();
        $userInput['app_name'] = $this->ask("What should the MySQL app be called?", $laravelAppName . "-mysql");
        $userInput['database_name'] = $this->ask("What should the MySQL database be called?", $laravelAppName);
        $userInput['user_name'] = $this->ask("What should the MySQL user be called?",);
        if ($userInput['user_name'] == "") throw new ProcessFailedException(Process::result("", "MySQL user name cannot be empty."));
        $userInput['volume_name'] = $this->ask("What should the MySQL volume be called?", str_replace("-", "_", $laravelAppName . "-mysqldata"));
        $userInput['password'] = $this->secret("What should the MySQL password be?");
        $userInput['root_password'] = $this->secret("What should the MySQL root password be?");
    }

    private function setUpMySQL($userInput)
    {
        $this->task("MySQL: Create app on Fly.io", function () use (&$userInput)
        {
            $this->createApp($userInput['app_name'], 'personal');
        });

        $this->task("MySQL: Create directories", function () use(&$userInput) {
            $this->createDirectories($userInput);
            return true;
        });

        $this->task("MySQL: Generate fly.toml app configuration file", function () use(&$userInput) {
            $this->generateFlyTomlMySQL($userInput, new TomlGenerator());
            return true;
        });

        $this->task("MySQL: Set secrets", function() use(&$userInput) {
            $this->setSecretsMySQL($userInput);
            return true;
        });

        $this->info("MySQL app '" . $userInput['app_name'] . "' is ready to go! run 'fly-laravel deploy:mysql' to deploy it.");
        return Command::SUCCESS;
    }

    private function getLaravelAppName() : string
    {
        if (!file_exists('fly.toml')) return '';
        $tomlArray = Toml::parseFile('fly.toml');
        return array_key_exists('app', $tomlArray) ? $tomlArray['app'] : '';
    }

    private function createDirectories()
    {
        if (!file_exists(".fly")) Process::run("mkdir .fly")->throw(); // should never be true, but doesn't hurt to check
        if (!file_exists(".fly/mysql")) Process::run("mkdir .fly/mysql")->throw();
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

    private function setSecretsMySQL(array $mysqlUserInput)
    {
        // MYSQL Secrets update
        Process::run("fly secrets set MYSQL_PASSWORD=" . $mysqlUserInput['password'] . " MYSQL_ROOT_PASSWORD=" . $mysqlUserInput['root_password'] .
            " MYSQL_USER=" . $mysqlUserInput['user_name'] . " -a " . $mysqlUserInput['app_name']. " --stage")->throw();

        // LARAVEL Secrets update
        $laravelTomlArray = Toml::parseFile("fly.toml");
        Process::run("fly secrets set DB_USERNAME=" . $mysqlUserInput['user_name'] . " DB_PASSWORD=" . $mysqlUserInput['password'] . " -a " . $laravelTomlArray['app']. " --stage")->throw();
    }

    private function createApp($appName, $organizationName): string
    {
        if (!$appName) $appName = "--generate-name";

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
}
