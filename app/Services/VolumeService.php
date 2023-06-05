<?php
namespace App\Services;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

class VolumeService
{
    // Class Attributes
    public $options;
    public function __construct( 
        public \LaravelZero\Framework\Commands\Command $command,
        public string $confirmKey = 'y'
    ){
        $this->options = [
            $this->confirmKey=>'Yes',
            'n'=>'No'
        ];
        return $this;
    }
    
    // Prompt User
    public function promptVolume( 
        string $laravelAppName
    ){
        $volumeMount = [];
        $answer = $this->command->choice(
            "Would you like to persist data in your storage folder( this will mount a Volume to the storage folder )?",
            $this->options,
            $this->confirmKey
        );

        if( $answer == $this->confirmKey ){
            $volumeMount['source'] = $this->command->ask("What should the volume be called?", str_replace("-", "_", $laravelAppName . "-data"));
            $volumeMount['destination'] = '/var/www/html/storage';
        }   
        return $volumeMount;
    }

    // Setup script to backup storage dir
    public function setUpVolumeScript()
    {       
        Process::run( 'cp -r storage storage_' );

        file_put_contents( 
            '.fly/scripts/1_storage_init.sh', 
            'FOLDER=/var/www/html/storage/app
            if [ ! -d "$FOLDER" ]; then
                echo "$FOLDER is not a directory, copying  storage_ content to storage"
                cp -r /var/www/html/storage_/. /var/www/html/storage
                echo "deleting storage_..."
                rm -rf /var/www/html/storage_
            fi' 
        );
    }

    // Clean backup created
    public function cleanUp()
    {
        if( file_exists('storage_') ){
            Process::run( 'rm -r storage_' );
        }
    }
}