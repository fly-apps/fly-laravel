<?php
namespace App\Services;

use LaravelZero\Framework\Commands\Command;

class ExtractFlyAppName
{
    /**
     * Config variables
     */
    public function __construct(
        public string $appName,
        public string $message
    )
    {}

    /**
     * In case app name is auto generated, extract app name from generation message
     * 
     * NOTE: where $message has the format "New app created: blue-pond-4265"
     */
    public function get(): string
    {   
        // Extract auto generated name
        if( $this->appName == '--generate-name' )
        {
            $appName = explode( "New app created:", $this->message );
            $appName = $appName[ 1 ];
            return str_replace(array("\r", "\n", ' '), '', $appName);
        }

        // Return user input Fly App Name
        return $this->appName;
    }
}