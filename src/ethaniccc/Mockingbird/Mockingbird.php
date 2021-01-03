<?php

declare(strict_types=1);

namespace ethaniccc\Mockingbird;

use ethaniccc\Mockingbird\commands\AlertCooldownCommand;
use ethaniccc\Mockingbird\commands\ToggleAlertsCommand;
use ethaniccc\Mockingbird\commands\UserDebugCommand;
use ethaniccc\Mockingbird\commands\UserLogsCommand;
use ethaniccc\Mockingbird\detections\Detection;
use ethaniccc\Mockingbird\listener\MockingbirdListener;
use ethaniccc\Mockingbird\threads\CalculationThread;
use ethaniccc\Mockingbird\threads\DebugWriteThread;
use ethaniccc\Mockingbird\user\UserManager;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\Server;
use pocketmine\snooze\SleeperNotifier;
use pocketmine\utils\TextFormat;

class Mockingbird extends PluginBase{

    /** @var Mockingbird */
    private static $instance;
    /** @var Detection[] */
    public $availableChecks;
    /** @var DebugWriteThread */
    public $debugThread;
    /** @var CalculationThread - Thread where calculations that have long execution times go. */
    public $calculationThread;

    public static function getInstance() : Mockingbird{
        return self::$instance;
    }

    public function onEnable() : void{
        if(self::$instance !== null){
            return;
        }
        $threadNotifier = new SleeperNotifier();
        $this->calculationThread = new CalculationThread($threadNotifier);
        Server::getInstance()->getTickSleeper()->addNotifier($threadNotifier, function() : void{
            while(($task = $this->calculationThread->getFinishTask()) !== null){
                ($task)($this->calculationThread->getFromDone());
            }
        });
        $this->calculationThread->start(PTHREADS_INHERIT_NONE);
        $this->debugThread = new DebugWriteThread($this->getDataFolder() . 'debug_log.txt');
        $this->debugThread->start();
        file_put_contents($this->getDataFolder() . 'debug_log.txt', '');
        self::$instance = $this;
        // ... why do I have to do this :cringethonk: ?
        if(((float) $this->getDescription()->getVersion()) !== $this->getConfig()->get('version')){
            if($this->updateConfig()){
                $this->getLogger()->debug('Mockingbird config has been updated');
                $this->getConfig()->reload();
            } else {
                $this->getLogger()->alert('Something went wrong while updating the config, please go manually edit the new config.');
            }
        }
        UserManager::init();
        new MockingbirdListener();
        $this->getAvailableChecks();
        $this->registerCommands();
        $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function(int $currentTick) : void{
            foreach(UserManager::getInstance()->getUsers() as $user){
                $user->tickProcessor->run();
            }
        }), 1);
    }

    public function getPrefix() : string{
        return $this->getConfig()->get('prefix') . TextFormat::RESET;
    }

    private function registerCommands() : void{
        $commands = [
            new ToggleAlertsCommand($this),
            new UserLogsCommand($this),
            new UserDebugCommand($this),
            new AlertCooldownCommand($this),
        ];
        $this->getServer()->getCommandMap()->registerAll($this->getName(), $commands);
    }

    private function getAvailableChecks() : void{
        $path = $this->getFile() . 'src/ethaniccc/Mockingbird/detections';
        foreach(scandir($path) as $file){
            if(is_dir("$path/$file") && !in_array($file, ['.', '..'])){
                $type = $file;
                foreach(scandir("$path/$file") as $otherFile){
                    if(is_dir("$path/$file/$otherFile") && !in_array($file, ['.', '..'])){
                        $subType = $otherFile;
                        foreach(scandir("$path/$file/$otherFile") as $check){
                            if(!is_dir("$path/$file/$otherFile/$check")){
                                $extension = explode('.', $check)[1];
                                if(strtolower($extension) === 'php'){
                                    $checkName = explode('.', $check)[0];
                                    $fullCheckName = "ethaniccc\\Mockingbird\\detections\\$type\\$subType\\$checkName";
                                    try{
                                        $classInfo = new \ReflectionClass($fullCheckName);
                                        if(!$classInfo->isAbstract() && $classInfo->isSubclassOf(Detection::class)){
                                            $this->availableChecks[] = new $fullCheckName($classInfo->getShortName(), ($settings = $this->getConfig()->get($classInfo->getShortName())) === false ? null : $settings);
                                        }
                                    } catch(\ReflectionException $e){}
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    private function updateConfig() : bool{
        // get all the previous settings the user has
        $oldConfig = $this->getConfig()->getAll();
        // remove the version - it's (most likely) outdated
        unset($oldConfig['version']);
        @unlink($this->getConfig()->getPath());
        $this->reloadConfig();
        foreach($oldConfig as $key => $value){
            // if the setting found in the old config is not in the current config,
            // the old config is (probably) too old.
            if(!isset($this->getConfig()->getAll()[$key])){
                $this->getLogger()->debug("Unknown key=$key");
                @unlink($this->getConfig()->getPath());
                $this->reloadConfig();
                return false;
            } else {
                // replace possible missing options in array
                if(is_array($value)){
                    $keys = array_keys($value);
                    foreach($this->getConfig()->get($key) as $offset => $var){
                        if(!in_array($offset, $keys)){
                            $value[$offset] = $var;
                        }
                    }
                }
                // set the current config setting value to the old config setting value
                $this->getConfig()->set($key, $value);
            }
        }
        // save the config - (why are comments being deleted here?)
        // TODO: Make some sort of hack to bring back the config notes :C
        $this->getConfig()->save();
        return true;
    }

    public function onDisable(){
        if($this->getConfig()->get('upload_debug')){
            $options = array(
                'ssl' => array(
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ),
                'http' => array(
                    'http' => "Content-type: application/x-www-form-urlencoded\r\n",
                    'method' => 'POST',
                    'content' => http_build_query(['data' => base64_encode(file_get_contents($this->getDataFolder() . 'debug_log.txt'))])
                )
            );
            $response = @file_get_contents('https://mb-debug-logs.000webhostapp.com/', false, stream_context_create($options));
            $this->getLogger()->debug("Response: $response");
        }
    }

}