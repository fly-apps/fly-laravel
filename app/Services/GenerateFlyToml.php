<?php
namespace App\Services;

use Illuminate\Support\Facades\Process;

class GenerateFlyToml
{
    public $templatePath = 'storage/fly.template.toml';
    public $writeToPath  = './fly.toml';
    
    /**
     * Generate fly.toml file
     * Initially rely on hardcoded string to generate fly.toml file
     */
    public function get( 
        \App\Commands\Launch $launch, 
        string $appName,
        string $nodeVersion,
        string $phpVersion
    )
    {     
        // Generate config   
        $flyToml = $this->fromTemplate();
        $flyToml = preg_replace(
            ['~app = ".*"~', '~NODE_VERSION = ".*"~', '~PHP_VERSION = ".*"~'],
            ["app = \"$appName\"", "NODE_VERSION = \"$nodeVersion\"", "PHP_VERSION = \"$phpVersion\""],
            $flyToml
        );
        
        // Writo/Override to file
        file_put_contents( $this->writeToPath, $flyToml );
        $launch->line( 'Wrote config file fly.toml' );
    } 

    /**
     * Generate from template file in $templatePath
     */
    public function fromTemplate(): string
    {
        return file_get_contents( $this->templatePath );
    }

    /**
     * Generate from fly.toml retrieved from Fly.io, located at $writeToPath
     */
    public function fromFlyctl( string $appName ): string
    {
        Process::run("flyctl config save -a $appName")->output();
        return file_get_contents( $this->writeToPath );
    }
} 