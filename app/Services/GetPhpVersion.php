<?php
namespace App\Services;

use Illuminate\Support\Facades\Process;

class GetPhpVersion
{
    /**
     * Determine PHP Version
     * First Case: Can't determine version through command line; use $default version || TESTCASE: $resultVersion = false;
     * Second Case: Version is below 7.4; use version 7.4 || TESTCASE: $resultVersion = "7.3";
     * Third Case: Version received is okay; use version received || TESTCASE: $resultVersion = "7.6"; $resultVersion = "8.2";
     */
    public function get( \App\Commands\LaunchCommand $launch ): string
    {
        // Default Version
        $defaultVersion = "8.0";

        // Get Version Output
        $resultVersion = phpversion();

        // Determine Best Version
        if( $resultVersion !== false )
        {
            if( version_compare( $resultVersion, '7.4', '<') )
            {
                $launch->line( "PHP version is below 7.4 that does not have compatible container, using PHP 7.4 instead." );
                return "7.4";
            }

            // Else Use the Version Output
                // $resultVersion has 3 digits, like this: 8.1.10 . We only need two so take only the first 2.
            $resultArray = explode(".", $resultVersion);
            $resultVersion = "$resultArray[0].$resultArray[1]";
            $launch->line( "Detected PHP version: $resultVersion" );
            return $resultVersion;
        }

        // Else use Default Version if unsuccessful with version extraction
        $launch->line( "Could not find PHP version, using PHP $defaultVersion which has the broadest compatibility" );
        return $defaultVersion;
    }
}
