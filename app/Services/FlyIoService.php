<?php

namespace App\Services;

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
    public function getOrganizations()
    {
        $response = Http::withToken($this->getAuthToken())
            ->acceptJson()
            ->contentType("application/json")
            ->post('https://api.fly.io/graphql', ["query" => "query {currentUser {email} organizations {nodes{id slug name type viewerRole}}}"])
            ->throw();

        // organizations will be an array of arrays that look like this: array("id" => , "slug" => , "name" => , "type" => , "viewerRole" => )
        $organizations = $response->collect("data.organizations.nodes")->toArray();

        return $organizations;
    }

    public function createApp($appName, $organizationName): string
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

    public function setAppSecrets(string $appName, array $secrets)
    {
        // $secrets should be an array of key-value pairs where the key is the secret's name and the value is the secret's value

        $secretsString = " ";
        foreach ($secrets as $secretName => $secretValue)
        {
            $secretsString = $secretsString . "$secretName=$secretValue ";
        }

        Process::run("fly secrets set $secretsString -a $appName --stage")->throw();
    }

    private function getAuthToken() : string
    {
        $result = Process::run("fly auth token")->throw();
        return $result->output();
    }

    public function getLaravelAppName() : string
    {
        if (!file_exists('fly.toml')) return '';
        $tomlArray = Toml::parseFile('fly.toml');
        return array_key_exists('app', $tomlArray) ? $tomlArray['app'] : '';
    }
}
