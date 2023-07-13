<?php
use Illuminate\Support\Facades\Http;
use App\Services\FlyIoService;

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


