<?php

use App\Services\FlyIoService;
use Mockery\MockInterface;

beforeEach(function(){
    $this->flyService = new FlyIoService();
});


test( 'validateAppName accepts valid app name.', function(){
   
    $testAppName = 'an-okay-app-name'; 

    $validation = $this->flyService->validateAppName( $testAppName );
    
    $this->assertTrue( $validation == $testAppName );
});

test( 'validateAppName does not accept invalid app name.', function(){

    $testAppName = '$'; 

    $this->expectException(\Illuminate\Process\Exceptions\ProcessFailedException::class);

    $this->flyService->validateAppName( $testAppName );

});

/**TODO:Either this or 'Selects Personal organization when only one organization is available.' */
test( 'askOrganizationName selects Personal organization when only one organization is available.', function(){
   
    $organizations =  $this->organizationsMock();
    
    $comm = new \App\Commands\LaunchCommand();

    
    $selected = app(\App\Services\FlyIoService::class)->askOrganizationName( $organizations, $comm);

    dd( $selected );
})->todo();
