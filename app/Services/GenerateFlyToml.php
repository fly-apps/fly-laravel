<?php
namespace App\Services;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\View;

/**
 * NOTES:
 * Added Laravel's view component to project. Followed tutorial here: https://laravel-zero.com/docs/view
 * Created config/view.php, and added "resources" to directories in `box.json`
 */
class GenerateFlyToml
{
    /**
     * Path references
     */
    public $writeToPath  = './fly.toml';
    public $templatePath = 'fly-template';

    /**
     * Config variables
     */
    public function __construct(
        public string $appName,
        public string $nodeVersion,
        public string $phpVersion
    ){}


    /**
     * Generate fly.toml file
     * Initially rely on hardcoded string to generate fly.toml file
     */
    public function get( \App\Commands\LaunchCommand $launch )
    {
        // Generate config
        $flyToml = $this->fromTemplate();

        // Writo/Override to file
        file_put_contents( $this->writeToPath, $flyToml );
        $launch->line( 'Wrote config file fly.toml' );
    }

    /**
     * Generate from template file in $templatePath
     */
    public function fromTemplate(): string
    {
        return View::make(
            $this->templatePath,
            [ 'appName'=>$this->appName, 'nodeVersion'=>$this->nodeVersion, 'phpVersion'=>$this->phpVersion ]
        )->render();
    }

    /**
     * Generate from fly.toml retrieved from Fly.io, located at $writeToPath
     */
    public function fromFlyctl( ): string
    {
        Process::run("flyctl config save -a $this->appName")->output();
        $flyToml = file_get_contents( $this->writeToPath );
        return preg_replace(
            ['~app = ".*"~', '~NODE_VERSION = ".*"~', '~PHP_VERSION = ".*"~'],
            ["app = \"$this->appName\"", "NODE_VERSION = \"$this->nodeVersion\"", "PHP_VERSION = \"$this->phpVersion\""],
            $flyToml
        );
    }
}
