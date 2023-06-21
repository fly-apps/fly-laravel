<?php

namespace Tests;

use LaravelZero\Framework\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /** COMMAND NAMES */
    public const DEPLOY_STR = 'deploy'; 
    public const LAUNCH_STR = 'launch';

    /** FILE NAMES */
    public const FLY_TOML_FILE_NAME_STR = 'fly.toml';

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
}
