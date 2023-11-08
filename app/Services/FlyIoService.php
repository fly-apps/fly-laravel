<?php

namespace App\Services;

use GuzzleHttp\Promise\Promise;
use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;
use Illuminate\Process\Exceptions\ProcessFailedException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Yosymfony\Toml\Toml;

class FlyIoService
{
    /**
     * @throws RequestException
     */
    public function getOrganizations(): \GuzzleHttp\Promise\PromiseInterface|\Illuminate\Http\Client\Response
    {
        // use
        // $flyIoService->getOrganizations()
        //    ->wait()
        //    ->collect("data.organizations.nodes")
        //    ->toArray();
        // to wait for the response and collect the results.
        // the result will be an array of arrays that look like this: array("id" => , "slug" => , "name" => , "type" => , "viewerRole" => )

        return Http::async()
            ->withToken($this->getAuthToken())
            ->acceptJson()
            ->contentType("application/json")
            ->post('https://api.fly.io/graphql', ["query" => "query {currentUser {email} organizations {nodes{id slug name type viewerRole}}}"]);
    }

    public function askOrganizationName(array $organizations, Command $command): string
    {
        $organizationNames = [];
        foreach ($organizations as $organization)
        {
            $organizationNames[] = $organization["type"] == "PERSONAL" ? "Personal" : $organization["name"];
        }

        if (sizeOf($organizationNames) == 1)
        {
            $command->line("Auto-selected '$organizationNames[0]' since it is the only organization found on Fly.io .");
            return $organizations[0]['slug'];
        }

        $choice = $command->choice("Select the organization where you want to deploy the app", $organizationNames);
        $index = array_search($choice, $organizationNames);

        if ($choice == "Cancel") return "";

        return $organizations[$index]["slug"];
    }

    public function askPrimaryRegion(string $regionsJson, Command $command)
    {
        $regions = [];
        foreach (json_decode($regionsJson, true) as $region)
        {
            $regions[] = $region['Code'] . " - " . $region['Name'];
        }
        return substr($command->choice("Select your app's primary region", $regions), 0, 3);
    }


    public function validateAppName($appName): string
    {
        if (!$appName) $appName = "--generate-name";

        if (!preg_match("/^[a-z0-9-]+$/", $appName))
        {
            throw new ProcessFailedException(Process::result("", "App names are only allowed to contain lowercase, numbers and hyphens.", -1));
        }
        return $appName;
    }

    public function validateVolumeName($volName): string
    {
        if (!preg_match("/^[a-z0-9_]+$/", $volName) || strlen($volName)>30 )
        {
            throw new ProcessFailedException(Process::result("", "Volume names are only allowed to contain lowercase, numbers and underscores, with a maximum of 30 characters.", -1));
        }
        return $volName;
    }

    public function createApp($appName, $organizationName): string
    {
        $result = Process::run("flyctl apps create -o $organizationName --machines $appName")
            ->throw();

        // In case app name is auto generated, extract app name from creation message
        if ($appName == '--generate-name')
        {
            $appName = explode('New app created:', $result->output())[1];
            $appName = str_replace(array("\r", "\n", ' '), '', $appName);
        }

        return $appName;
    }

    public function setAppSecrets(string $appName, array $secrets): void
    {
        // $secrets should be an array of key-value pairs where the key is the secret's name and the value is the secret's value

        $secretsString = " ";
        foreach ($secrets as $secretName => $secretValue)
        {
            $secretsString = $secretsString . "$secretName=$secretValue ";
        }

        Process::run("fly secrets set $secretsString -a $appName --stage")
            ->throw();
    }

    private function getAuthToken(): string
    {
        $result = Process::run("fly auth token")
            ->throw();
        return $result->output();
    }

    public function getLaravelAppName(): string
    {
        if (!file_exists('fly.toml')) return '';
        $tomlArray = Toml::parseFile('fly.toml');
        return array_key_exists('app', $tomlArray) ? $tomlArray['app'] : '';
    }

    public function getFlyAppStatus( ): array 
    {
        $result = Process::run('fly status --json')->throw();
        return json_decode($result->output(), true);
    }

    public function getFlyVolumesStatus(): array 
    {
        $result = Process::run('fly volumes list --json')->throw();
        return json_decode($result->output(), true);
    }

    public function getLaravelOrganization(): string
    {
        $result = Process::run("fly status --json")
            ->throw();
        $statusArray = json_decode($result->output(), true);
        return $statusArray['Organization']['Slug'];
    }

    public function getLaravelPrimaryRegion(): string
    {
        if (!file_exists('fly.toml')) return '';
        $tomlArray = Toml::parseFile('fly.toml');
        return array_key_exists('primary_region', $tomlArray) ? $tomlArray['primary_region'] : '';
    }
}
