<?php

use Symfony\Component\Console\Command\Command as CommandAlias;
use Illuminate\Support\Facades\Http;
use Mockery;

use Illuminate\Process\PendingProcess;
use Illuminate\Contracts\Process\ProcessResult;
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
    $this->deleteFlyTomlFileInBaseDir();

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
    $this->deleteFlyTomlFileInBaseDir();
    
});

/**
 * Input Features
 */
test( 'Exits with failure when invalid app name ', function(){ 

    // ACTION: Run Launch Command + Provide Invalid App Name 
    // ASSERT: Exit Code is Failure Code
    $this->artisan( $this::LAUNCH_SIGNATURE )
    ->expectsQuestion( $this::ASK_CHOOSE_APP_NAME_OR_BLANK, '$' )
    ->assertExitCode( CommandAlias::FAILURE ); 

});


/**TODO:Either this or 'askOrganizationName selects Personal organization when only one organization is available.' */
test( 'Selects Personal organization when only one organization is available.', function(){

        // Make sure that call to these url are mocked with predefined data
        Http::fake([
            'https://api.fly.io/graphql' => Http::response([
                'data'=>[
                    'currentUser'=>['email'=>'test@test.com'],
                    'organizations'=>[
                        'nodes'=>[
                            [
                                "id" => "testId",
                                "slug" => "testSlug",
                                "name" => "testName",
                                "type" => "PERSONAL",
                                "viewerRole" => "admin",
                            ] 
                        ]
                    ]
                ]
            ])
        ]);

        // Mock Process for platform regions list
        Process::fake([
            'flyctl platform regions --json' => Process::describe()
            ->output(file_get_contents('tests/Assets/resp.json'))
            ->errorOutput('First line of error output')
            ->exitCode(0)
        ]);

        // Mock the methods of the FlyioService
        $pM = $this->partialMock(
            FlyIoService::class,
            function (MockInterface $mock) {

                $mock
                ->shouldReceive('askOrganizationName')
                ->andReturn('stes');

                $mock
                ->shouldReceive('askPrimaryRegion')
    
                ->andReturn('sdfs');
            }
        );

        $this->partialMock(
            \App\Commands\LaunchCommand::class,
            function(MockInterface $mock){
                $mock->shouldReceive('setupLaravel')->once()->andReturn('testse');
            }
        );
    
        // Works
        $user = [];
        dd( app( \App\Commands\LaunchCommand::class)->setupLaravel( $user,  new FlyIoService()) );
        // Does not work at all because somehow setupLaravel is not getting mocked through artisan()!
        // Plus setupLaravel is actually private, so I cant mock it
        $this->artisan( 'launch' )
        ->expectsQuestion( $this::ASK_CHOOSE_APP_NAME_OR_BLANK, 'test' )
        ->expectsQuestion( 'Select additional processes to run (comma-separated)', '' );

})->todo();

