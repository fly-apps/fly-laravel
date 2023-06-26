<?php

use Symfony\Component\Console\Command\Command as CommandAlias;

it('Exits when fly.toml exists and user declines deploy prompt.', function () {

    // PREP: Temp Copy flytoml template
    $this->createTemporaryFlyTomlFile();

    // ACTION: Run Launch Command + Decline deploy command
    $this->artisan( $this::LAUNCH_STR ) 
    ->expectsConfirmation( 'Do you want to run the deploy command instead?', 'no' )
    ->assertExitCode( CommandAlias::SUCCESS ); 

    // ASSERT: Deploy Command was not called
    $this->assertCommandNotCalled( $this::DEPLOY_STR );
    
    // CLEAN: Delete temp fly.toml 
    $this->deleteFlyTomlFileInBaseDir();

});

it('Triggers Deploy command when fly.toml exists and user accepts deploy prompt.', function(){

    // PREP: Temp Copy flytoml template
    $this->createTemporaryFlyTomlFile();

    // ACTION: Run Launch Command + Accept deploy prompt
    $this->artisan('launch')
    ->expectsConfirmation('Do you want to run the deploy command instead?','yes');

    // ASSERT: Deploy commmand called
    $this->assertCommandCalled('App\Commands\DeployCommand');

    // CLEAN: Delete temp fly.toml 
    $this->deleteFlyTomlFileInBaseDir();
    
});

it('Declines invalid app names.', function(){

    // Exception Test: Not working : $this->expectException(\Illuminate\Process\Exceptions\ProcessFailedException::class);

    // ACTION: Run Launch Command + Provide Invalid App Name 
    // ASSERT: Exit Code is Failure Code
    $this->artisan('launch')
    ->expectsQuestion('Choose an app name (leave blank to generate one)','$')
    ->assertExitCode(CommandAlias::FAILURE); 

});


