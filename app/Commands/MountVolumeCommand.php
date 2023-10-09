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
    protected $description = 'Mount a volume to the Laravel application ';

    /**
     * Execute the console command.
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
        $this->line( "Volumes don't automatically sync their data&mdash;remember to apply your own replication logic if you need consistent data across your Fly App's Volumes." );
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
        $this->task( 'Detect regional volumes to create.', function() use($flyIoService, &$userInput){
            $statusJson = Process::run('fly status --json')->throw();
            $statusArr = json_decode($statusJson->output(), true);

            $userInput['machinesCount'] = $this->getRegionalMachineCount( $statusArr );
            $userInput['volumeName'] = (str_replace( '-', '_', $statusArr['Name'] )).'_storage_vol';
        });
       
        return $userInput;
    }
    
    private function setUp( array $userInput, FlyIoService $flyIoService)
    {
        // Create volumes that can accommodate the number of machines
        $this->createVolumesPerMachine( $userInput );

        // Setup flytoml
        $this->updateFlyTomlMount( $userInput, new TomlGenerator() );

        // Create storage folder back up & copy re-init template
        Process::run( 'cp -r storage storage_' );
        $this->copyFiles();
       
        // Deploy changes or not
        if( $this->confirm('Do you wish to deploy and complete mounting the volumes to your Fly App?') ){
            $process = Process::timeout(600)
            ->start('fly deploy', function (string $type, string $output) {
                echo $output;
            });
            $result = $process->wait()->throw();
            $this->line($result->output());
        }
    }

    /***
     * Helper functions starts below!
     */

    private function getRegionalMachineCount( array $statusArr )
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
        $this->task('Creating volumes per machine detected.', function() use($userInput) {
            foreach( $userInput['machinesCount'] as $region=>$count ){
                $this->line( 'Detected '.$count.' machines in the '.$region.' region. Creating Volumes with name '.$userInput['volumeName'.'.'] );
                $command = 'fly volumes create '. $userInput['volumeName'] .' --count '.$count.' --region '.$region;
                $result = Process::run( $command )->throw();
                $values = json_decode($result->output(), true);
            }
        });
        
    }

    private function updateFlyTomlMount( array $userInput, TomlGenerator $generator )
    {
        $this->task('Updating Fly.toml to mount volume.', function() use( $userInput, $generator ){
            // Get current toml content
            $laravelTomlArray = Toml::parseFile('fly.toml');

            // Set fly.toml volume mount 
            $laravelTomlArray['mount']['source'] = $userInput['volumeName'];
            $laravelTomlArray['mount']['destination'] = "/var/www/html/storage";

            // Re-Generate fly.toml with mount values
            $generator->generateToml($laravelTomlArray, 'fly.toml');
        });
    }
   
    private function copyFiles()
    {
        $this->task( 'Copying storage folder re-initialization script.', function(){
            // The templates path
            $templatesPath = getcwd().'/vendor/fly-apps/fly-laravel/resources/templates';

            // Copy over storage_vol/1_storage_init.sh
            File::copy( $templatesPath.'/storage_vol/1_storage_init.sh', getcwd() . '/.fly/scripts/1_storage_init.sh' );
        });       
    }

}
