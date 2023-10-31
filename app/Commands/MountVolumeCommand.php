<?php

namespace App\Commands;

use App\Services\FlyIoService;
use App\Services\TomlGenerator;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Process\Exceptions\ProcessFailedException;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Command\Command as CommandAlias;
use Illuminate\Support\Facades\File;
use Yosymfony\Toml\Toml;
use Exception;
use Log;

class MountVolumeCommand extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'mount:volume';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Mount volume(s) to the Laravel application\'s storage folder based on the number of available machines for the Fly App.';

    /**
     * Execute the console command.
     * 1. input() - Detect the number of machines per region, generate volume name from the app name
     * 2. setUp() - Create volumes based on input, 
     *            - Revise flyToml file to mount newly generated volume, 
     *            - Setup backup script to re-init storage folder during initial mounting of volume
     *            - Prompt deploy changes
     * 
     * @return mixed
     */
    public function handle(FlyIoService $flyIoService)
    {
        try
        {
            $userInput = [];
            $this->input($userInput, $flyIoService);
            $this->setUp($userInput, $flyIoService);
        }
        catch (ProcessFailedException $e)
        {
            $this->error($e->result->errorOutput());
            return CommandAlias::FAILURE;
        }

        // finalize
        $this->line( "Volumes don't automatically sync their data--remember to apply your own replication logic if you need consistent data across your Fly App's Volumes." );
        return CommandAlias::SUCCESS;
    }

    /**
     * Define the command's schedule.
     *
     * @param Schedule $schedule
     * @return void
     */
    public function schedule(Schedule $schedule): void
    {
        // $schedule->command(static::class)->everyMinute();
    }

    private function input(array &$userInput, FlyIoService $flyIoService)
    {
        $this->task( 'Detecting regional volumes to create', function() use($flyIoService, &$userInput){
            // The status command contains details of the app
            $statusJson = Process::run('fly status --json')->throw();
            $statusArr = json_decode($statusJson->output(), true);
        
            // Where we can count machines per region
            $userInput['machinesCount'] = $this->getMachineCountPerRegion( $statusArr );
            $userInput['volumeName']    = (str_replace( '-', '_', $statusArr['Name'] )).'_storage_vol';
        
            // Get the number of existing volumes
            $volJson = Process::run('fly volumes list --json')->throw();
            $volArr = json_decode($volJson->output(), true);
            $userInput['existingVols'] = $this->getExistingVolumesPerRegion( $volArr );
        });

        return $userInput;
    }

    private function getExistingVolumesPerRegion( $volArr )
    {
        $arr = [];
        foreach( $volArr as $vol ){
            $region = $vol['region'];
            if( !isset($arr[$region]) )
                $arr[$region] = 0;
            $arr[$region] += 1;
        } 
        return $arr;
    }

    private function setUp( array $userInput, FlyIoService $flyIoService)
    {
        // Create volumes that can accommodate the number of machines
        $this->createVolumesPerMachine( $userInput );

        // Setup flytoml
        $this->mountVolumeInFlyToml( $userInput, new TomlGenerator() );

        // Create storage folder back up & copy re-init template
        $this->setUpReInitStorageFolder( );

        // Deploy changes or not
        if( $this->confirm('Do you wish to deploy and complete mounting the volumes to your Fly App?') ){
            $process = Process::timeout(600)
            ->start('fly deploy --wait-timeout 400', function (string $type, string $output) {
                echo $output;
            });
            $result = $process->wait()->throw();
            $this->line($result->output());
        }
    }

    /***
     * Helper functions starts below!
     */

    private function getMachineCountPerRegion( array $statusArr )
    {
        // Get count of each machine per region
        $machines = [];
        foreach( $statusArr['Machines'] as $item ){
            if( $item['config']['env']['FLY_PROCESS_GROUP'] == 'app' ){
                if( !isset($machines[ $item['region'] ]) )
                    $machines[ $item['region'] ] = 1;
                else
                    $machines[ $item['region'] ] += 1;
            }
        }
        return $machines;
    }

    private function createVolumesPerMachine( array $userInput )
    {
        $this->task('Creating volumes per machine', function() use($userInput) {
            foreach( $userInput['machinesCount'] as $region=>$count ){

                $this->newLine(2);
                $this->line( ' Detected '.$count.' machines in the '.$region.' region...' );
               
                if( isset($userInput['existingVols'][$region]) ){
                    $this->line( ' Found '.$userInput['existingVols'][$region].' existing Volumes in the region...' );
                    $count = $count-$userInput['existingVols'][$region];
                }
                

                if( $count > 0 ){
                    $this->line( ' Creating '.$count.' volumes named '. $userInput['volumeName'].' in the '.$region.' region...' );
                    $this->newLine();
                    $command = 'fly volumes create '. $userInput['volumeName'] .' --count '.$count.' --region '.$region;
                    $result = Process::run( $command )->throw();
                    $values = json_decode($result->output(), true);
                }

            }
        });
    }

    private function mountVolumeInFlyToml( array $userInput, TomlGenerator $generator )
    {
        $this->task('Updating fly.toml to mount volume', function() use( $userInput, $generator ){
            // Get current toml content
            $laravelTomlArray = Toml::parseFile('fly.toml');

            // Set fly.toml volume mount 
            $laravelTomlArray['mount']['source'] = $userInput['volumeName'];
            $laravelTomlArray['mount']['destination'] = "/var/www/html/storage";

            // Re-Generate fly.toml with mount values
            $generator->generateToml($laravelTomlArray, 'fly.toml');

        });
    }

    private function setUpReInitStorageFolder()
    {
        // Setup reinit for storage folder
        Process::run( 'cp -r storage storage_' );
        $this->copyFiles();
    }

    private function copyFiles()
    {
        $this->task( 'Copying storage folder re-initialization script', function(){
            // The templates path
            $templatesPath = getcwd().'/vendor/fly-apps/fly-laravel/resources/templates';

            // Copy over storage_vol/1_storage_init.sh
            File::copy( $templatesPath.'/storage_vol/1_storage_init.sh', getcwd() . '/.fly/scripts/1_storage_init.sh' );
        });       
    }

}