<?php

namespace Tests;

use LaravelZero\Framework\Testing\TestCase as BaseTestCase;


class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /** COMMAND SIGNATURES */
    public const LAUNCH_SIGNATURE = 'launch';

    /** COMMAND CLASS */
    public const DEPLOY_CLASS = 'App\Commands\DeployCommand'; 

    /** FILE NAMES */
    public const FLY_TOML_FILE_NAME_STR = 'fly.toml';
    public const FLY_CONFIG_FILES =  [ 'fly.toml', 'Dockerfile', '.dockerignore', '.fly/scripts/caches.sh', '.fly/entrypoint.sh'];

    /** QUESTIONS */
    public const ASK_TO_DEPLOY_INSTEAD = 'Do you want to run the deploy command instead?';
    public const ASK_CHOOSE_APP_NAME_OR_BLANK = 'Choose an app name (leave blank to generate one)';
    public const ASK_SELECT_APP_PRIMARY_REGION = "Select your app's primary region";
    public const ASK_SELECT_PROCESSES = 'Select additional processes to run (comma-separated)';
    public const ASK_DEPLOY_APP = 'Do you want to deploy your app?';
    
    /** QUESTION ANSWERS */
    public const ANSWER_NO = 'no';
    public const ANSWER_YES = 'yes';

    /** PREPARATION FUNCTIONS */
    function createTemporaryFlyTomlFile()
    {
        copy( 'resources/templates/'.$this::FLY_TOML_FILE_NAME_STR, $this::FLY_TOML_FILE_NAME_STR );
    }

    /** CLEAN UP FUNCTIONS  */
    function deleteConfigFilesDir()
    {
        // Clean files
        foreach( $this::FLY_CONFIG_FILES as $fileToCheck ){
            if( file_exists($fileToCheck) )
                $this->deleteFileInBaseDir( $fileToCheck );
        }

        // Clean directories
        $dirs = ['./.fly/scripts', './.fly'];
        foreach( $dirs as $dir ){
            if( file_exists($dir) ){
                rmdir( $dir );
            }
        }
    }

    function deleteFileInBaseDir( $fileName )
    {
        unlink( './'.$fileName );
    }

    /** MOCK FUNCTIONS  */
    function organizationsMock( $multiple=false )
    {
        if( $multiple ){
            return [
                [
                    "id" => "testId",
                    "slug" => "PERSONAL",
                    "name" => "testName",
                    "type" => "PERSONAL",
                    "viewerRole" => "admin",
                ],
                [
                    "id" => "testId2",
                    "slug" => "test",
                    "name" => "test",
                    "type" => "SHARED",
                    "viewerRole" => "admin",
                ] 
            ];
        }else{
            return [
                [
                    "id" => "testId",
                    "slug" => "PERSONAL",
                    "name" => "testName",
                    "type" => "PERSONAL",
                    "viewerRole" => "admin",
                ] 
            ];
        }
    }

    
    function commandWithOutput()
    {
        // Command instance with output attribute 
        
        $out = new \Symfony\Component\Console\Output\BufferedOutput(
            \Symfony\Component\Console\Output\OutputInterface::OUTPUT_RAW,
            true
        );

        $comm = new \App\Commands\LaunchCommand();
        $comm->setOutput( new \Illuminate\Console\OutputStyle( 
            new \Symfony\Component\Console\Input\ArrayInput([]),  
            $out 
        ) );

        return $comm;
    }
}
