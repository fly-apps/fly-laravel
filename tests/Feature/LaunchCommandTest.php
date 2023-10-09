<?php

use Symfony\Component\Console\Command\Command as CommandAlias;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use App\Services\FlyIoService;
use Mockery\MockInterface;


/** 
 * Take note that string constants like LAUNCH_SIGNATURE 
 * are found in test/TestCase.php 
 * */

/** 
 * Fly TOML Present Features
 */
test( 'Exits when fly.toml exists and user declines deploy prompt.', function () {

    // PREP: Temp Copy flytoml template
    $this->createTemporaryFlyTomlFile();

    // ACTION: Run Launch Command + Decline deploy command
    $this->artisan( $this::LAUNCH_SIGNATURE )
    ->expectsConfirmation( 
        $this::ASK_TO_DEPLOY_INSTEAD, 
        $this::ANSWER_NO
    )->assertExitCode( CommandAlias::SUCCESS ); 

    // ASSERT: Deploy Command was not called
    $this->assertCommandNotCalled( $this::DEPLOY_CLASS );
    
    // CLEAN: Delete temp fly.toml 
    $this->deleteFileInBaseDir( $this::FLY_TOML_FILE_NAME_STR );

});

test( 'Triggers Deploy command when fly.toml exists and user accepts deploy prompt.', function(){

    // PREP: Temp Copy flytoml template
    $this->createTemporaryFlyTomlFile();

    // ACTION: Run Launch Command + Accept deploy prompt
    $this->artisan( $this::LAUNCH_SIGNATURE )
    ->expectsConfirmation( 
        $this::ASK_TO_DEPLOY_INSTEAD,
        $this::ANSWER_YES
    );

    // ASSERT: Deploy commmand called
    $this->assertCommandCalled( $this::DEPLOY_CLASS );

    // CLEAN: Delete temp fly.toml 
    $this->deleteFileInBaseDir( $this::FLY_TOML_FILE_NAME_STR );
    
});

/**
 * Input Features
 */
test( 'Exits with failure when invalid app name is given.', function(){ 

    // ACTION: Run Launch Command + Provide Invalid App Name 
    // ASSERT: Exit Code is Failure Code
    $this->artisan( $this::LAUNCH_SIGNATURE )
    ->expectsQuestion( $this::ASK_CHOOSE_APP_NAME_OR_BLANK, '$' )
    ->assertExitCode( CommandAlias::FAILURE ); 

});

test( 'Initializes config files for a Laravel fly app and exits successfully when Deploy prompt declined.', function(){

    /** PREPARATION */
    // Mock orgs listed from graphql api
    Http::fake([
        'https://api.fly.io/graphql' => Http::response(
            file_get_contents('tests/Assets/orgs-graphql.json')
        )
    ]);

    // Mock regions listed from flyctl
    Process::fake([
        'flyctl platform regions --json' => Process::describe()
        ->output(file_get_contents('tests/Assets/regions.json'))
        ->errorOutput('First line of error output')
        ->exitCode(0)
    ]);

    // Mock methods of the FlyioService
    $this->partialMock(
        FlyIoService::class,
        function (MockInterface $mock) {

            $mock
            ->shouldReceive('createApp')
            ->andReturn('sample-app-name');

            $mock
            ->shouldReceive('setAppSecrets');
        }
    );

    /** ACTION */
    $this->artisan('launch')
    ->expectsQuestion( $this::ASK_CHOOSE_APP_NAME_OR_BLANK, '' )
    ->expectsOutputToContain("Personal") 
    ->expectsQuestion( $this::ASK_SELECT_APP_PRIMARY_REGION, 'ams' )
    ->expectsQuestion( $this::ASK_SELECT_PROCESSES, ['none'] )
    ->expectsQuestion( $this::ASK_DEPLOY_APP, false )
    ->assertExitCode( CommandAlias::SUCCESS );
    
    /** ASSERTION */
    foreach( $this::FLY_CONFIG_FILES as $fileToCheck ){
        $fileExists = file_exists($fileToCheck);
        $this->assertTrue( $fileExists == true );
    }

    /** CLEAN UP */
    $this->deleteConfigFilesDir();


});