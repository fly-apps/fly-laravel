<?php

use Symfony\Component\Console\Command\Command as CommandAlias;


/** 
 * Fly TOML Present Features
 */
test('Exits when fly.toml exists and user declines deploy prompt.', function () {

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
    $this->deleteFlyTomlFileInBaseDir();

});

test('Triggers Deploy command when fly.toml exists and user accepts deploy prompt.', function(){

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
    $this->deleteFlyTomlFileInBaseDir();
    
});

/**
 * Input Features
 */
test('Declines invalid app names.', function(){

    // Exception Test: Not working : $this->expectException(\Illuminate\Process\Exceptions\ProcessFailedException::class);

    // ACTION: Run Launch Command + Provide Invalid App Name 
    // ASSERT: Exit Code is Failure Code
    $this->artisan('launch')
    ->expectsQuestion('Choose an app name (leave blank to generate one)','$')
    ->assertExitCode(CommandAlias::FAILURE); 

});


