<?php
namespace App\Services;

use Illuminate\Support\Facades\Process;

class GetPhpVersion
{
    /**
     * Determine PHP Version
     * First Case: Can't determine version through command line; use $default version || TESTCASE: $resultArr = [];
     * Second Case: Version is below 7.4; use version 7.4 || TESTCASE: $resultArr = [0,"7.3"];
     * Third Case: Version received is okay; use version received || TESTCASE: $resultArr = [0,"7.6"]; $resultArr = [0,"8.2"];
     */
    public function get( \App\Commands\Launch $launch ): string
    {
        // Default Values
        $defaultVersion = "8.0";
        $regex = '/PHP ([0-9]+\.[0-9]+)\.[0-9]/';

        // Get Version Output
        $result = Process::run( 'php -v' );

        // Extract Version from Output
        $resultArr = [];
        preg_match( $regex, $result->output(), $resultArr );
        
        // Determine Version Extracted
        if( count($resultArr) > 1 )
        {
            if( version_compare( $resultArr[1], '7.4', '<') )
            {
                $launch->line( "PHP version is below 7.4 that does not have compatible container, using PHP 7.4 instead." );
                return "7.4";
            }

            // Else use the version extracted
            $launch->line( "Detected PHP version: $resultArr[1]" );
            return $resultArr[1];
        }

        // Else use default version if unsuccessful with version extraction
        $launch->line( "Could not find PHP version, using PHP $defaultVersion which has the broadest compatibility" );
        return $defaultVersion;
    } 
}