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

    /** QUESTIONS */
    public const ASK_TO_DEPLOY_INSTEAD = 'Do you want to run the deploy command instead?';
    public const ASK_CHOOSE_APP_NAME_OR_BLANK = 'Choose an app name (leave blank to generate one)';
    
    /** QUESTION ANSWERS */
    public const ANSWER_NO = 'no';
    public const ANSWER_YES = 'yes';

    /** TEMPLATE PATHS */
    public const TEMPLATE_PATH_STR = 'resources/templates';

    /** PREPARATION FUNCTIONS */
    function createTemporaryFlyTomlFile()
    {
        copy( $this::TEMPLATE_PATH_STR.'/'.$this::FLY_TOML_FILE_NAME_STR, $this::FLY_TOML_FILE_NAME_STR );
    }

    /** CLEAN UP FUNCTIONS  */
    function deleteFlyTomlFileInBaseDir()
    {
        unlink( './'.$this::FLY_TOML_FILE_NAME_STR );
    }

    /** MOCK FUNCTIONS  */
    function organizationsMock()
    {
        return [
            [
                "id" => "testId",
                "slug" => "testSlug",
                "name" => "testName",
                "type" => "PERSONAL",
                "viewerRole" => "admin",
            ] 
        ];
    }
}
