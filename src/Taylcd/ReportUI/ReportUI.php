<?php

namespace Taylcd\ReportUI;

use jojoe77777\FormAPI\FormAPI;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\plugin\PluginDescription;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;
use Taylcd\ReportUI\event\Listener;
use Taylcd\ReportUI\event\PlayerReportEvent;
use Taylcd\ReportUI\event\ReportProcessedEvent;
use Taylcd\ReportUI\task\SaveTask;

class ReportUI extends PluginBase
{
    const CONFIG_VERSION = 2;

    /** @var Config */
    protected $lang;

    /** @var Config */
    protected $reports;

    /** @var FormAPI */
    protected $FormAPI;

    private $reportCache = [];
    private $adminCache = [];

    public function onLoad(){
        $this->saveDefaultConfig();
        $this->saveResource('language.yml');
        $this->lang = new Config($this->getDataFolder() . 'language.yml', Config::YAML);
        $this->reports = new Config($this->getDataFolder() . 'reports.yml', Config::YAML);

        if($this->getConfig()->get("check-update", true)){
            $this->getLogger()->info("Checking update...");
            try{
                if(($version = (new PluginDescription(file_get_contents("https://raw.githubusercontent.com/Taylcd/ReportUI/master/plugin.yml")))->getVersion()) != $this->getDescription()->getVersion()){
                    $this->getLogger()->notice("New version $version available! Get it here: " . $this->getDescription()->getWebsite());
                } else {
                    $this->getLogger()->info("Already up-to-date.");
                }
            } catch(\Exception $ex) {
                $this->getLogger()->warning("Unable to check update.");
            }
        }

        if($this->getConfig()->get('config-version') < self::CONFIG_VERSION){
            rename($this->getDataFolder() . "config.yml", $this->getDataFolder() . "config.old.yml");
            $this->saveDefaultConfig();
            $this->getConfig()->reload();
            $this->getLogger()->notice($this->getMessage("console.config-outdated"));
        }
    }

    public function onEnable(){
        $this->FormAPI = $this->getServer()->getPluginManager()->getPlugin("FormAPI");
        if(!$this->FormAPI or $this->FormAPI->isDisabled()){
            $this->getLogger()->warning('Dependency FormAPI not found, disabling...');
            $this->getPluginLoader()->disablePlugin($this);
        }
        $this->getServer()->getPluginManager()->registerEvents(new Listener($this), $this);
        $this->getServer()->getScheduler()->scheduleDelayedRepeatingTask(new SaveTask($this), $this->getConfig()->get('save-period', 600) * 20, $this->getConfig()->get('save-period', 600) * 20);
        $this->getServer()->getLogger()->info(TextFormat::AQUA . 'ReportUI enabled. ' . TextFormat::GRAY . 'Made by Taylcd with ' . TextFormat::RED . "\xe2\x9d\xa4");
    }

    public function onDisable(){
        $this->save();
    }

    public function save(){
        $this->reports->save();
    }

    /**
     * Create a new report
     *
     * @param string $reporter
     * @param string $target
     * @param string $reason
     */
    public function addReport(string $reporter, string $target, string $reason){
        $reports = $this->reports->getAll();
        array_unshift($reports, [
            'reporter' => $reporter,
            'target' => $target,
            'reason' => $reason,
            'time' => time()
        ]);
        $this->reports->setAll($reports);

        if($this->getConfig()->get("enable-new-report-notification", true)){
            foreach($this->getServer()->getOnlinePlayers() as $player){
                if($player->hasPermission("report.admin.notification")){
                    $player->sendMessage($this->getMessage("admin.new-report", $reporter, $target, $reason));
                }
            }
        }
    }

    /**
     * Delete specific report
     *
     * @param string $search
     * @param $value
     */
    public function deleteReport(string $search, $value){
        if($search == "id"){
            $reports = $this->reports->getAll();
            array_splice($reports, $value, 1);
            $this->reports->setAll($reports);
        }else{
            $reports = $this->reports->getAll();
            for($i = 0; $i < count($reports); $i ++){
                if(strtolower($reports[$i][$search]) == strtolower($value)){
                    $i --;
                    array_splice($reports, $i, 1);
                }
            }
            $this->reports->setAll($reports);
        }
    }

    /**
     * Get all reports on the server
     *
     * @return Config
     */
    public function getReports(){
        return $this->reports;
    }

    public function getMessage($key, ...$replacement) : string{
        if(!$message = $this->lang->getNested($key)){
            if($message = (new Config($this->getFile() . "resources/language.yml", Config::YAML))->getNested($key)){
                $this->lang->setNested($key, $message);
                $this->lang->save();
            }else{
                $this->getLogger()->warning("Message $key not found.");
            }
        }
        foreach($replacement as $index => $value){
            $message = str_replace("%$index", $value, $message);
        }
        return $message;
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool{
        if(!$sender instanceof Player){
            $sender->sendMessage(TextFormat::RED . 'This command can only be called in-game.');
            return true;
        }
        switch($command->getName()){
            case 'report':
                if(!isset($args[0])) unset($this->reportCache[$sender->getName()]);
                else $this->reportCache[$sender->getName()] = $args[0];
                $this->sendReportGUI($sender);
                return true;
            case 'reportadmin':
                $this->sendAdminGUI($sender);
        }
        return true;
    }

    private function sendReportGUI(Player $sender){
        if(isset($this->reportCache[$sender->getName()])){
            $this->sendReasonSelect($sender);
            return;
        }

        $form = $this->FormAPI->createCustomForm(function(Player $sender, array $data){
            if(count($data) < 2){
                return;
            }
            $this->reportCache[$sender->getName()] = $data[1];
            $this->sendReasonSelect($sender);
        });

        $form->setTitle($this->getMessage('gui.title'));
        $form->addLabel($this->getMessage('gui.label'));
        $form->addInput($this->getMessage('gui.input'));
        $form->sendToPlayer($sender);
    }

    private function sendReasonSelect(Player $sender){
        $name = $this->reportCache[$sender->getName()];
        if(!$name || !$this->getServer()->getOfflinePlayer($name)->getFirstPlayed()){
            $sender->sendMessage($this->getMessage('gui.player-not-found'));
            return;
        }
        if(strtolower($name) == strtolower($sender->getName())){
            $sender->sendMessage($this->getMessage('gui.cant-report-self'));
            return;
        }
        if($this->getServer()->getOfflinePlayer($this->reportCache[$sender->getName()])->isOp() && !$this->getConfig()->get('allow-reporting-ops')){
            $sender->sendMessage($this->getMessage('report.op'));
            return;
        }
        if($this->getServer()->getOfflinePlayer($this->reportCache[$sender->getName()])->isBanned() && !$this->getConfig()->get('allow-reporting-banned-players')){
            $sender->sendMessage($this->getMessage('report.banned'));
            return;
        }

        $form = $this->FormAPI->createSimpleForm(function(Player $sender, array $data){
            if($data[0] === null){
                return;
            }
            if($data[0] == count($this->getConfig()->get('reasons'))){
                if(!$this->getConfig()->get('allow-custom-reason')){
                    return;
                }
                $form = $this->FormAPI->createCustomForm(function(Player $sender, array $data){
                    if (count($data) < 2){
                        return;
                    }
                    if(!$data[1] || strlen($data[1]) < $this->getConfig()->get('custom-reason-min-length', 4) || strlen($data[1]) < $this->getConfig()->get('custom-reason-min-length', 4)){
                        $sender->sendMessage($this->getMessage('report.bad-reason'));
                        return;
                    }
                    $this->addReport($sender->getName(), $this->reportCache[$sender->getName()], $data[1]);
                    $sender->sendMessage($this->getMessage('report.successful', $this->reportCache[$sender->getName()], $data[1]));
                });
                $form->setTitle($this->getMessage('gui.title'));
                $form->addLabel($this->getMessage('gui.custom.label', $this->reportCache[$sender->getName()]));
                $form->addInput($this->getMessage('gui.custom.input'));
                $form->sendToPlayer($sender);
                return;
            }

            $this->getServer()->getPluginManager()->callEvent($ev = new PlayerReportEvent($sender, $this->reportCache[$sender->getName()], $this->getConfig()->get('reasons')[$data[0]] ?? 'None'));
            if(!$ev->isCancelled()){
                $this->addReport($sender->getName(), $this->reportCache[$sender->getName()], $this->getConfig()->get('reasons')[$data[0]] ?? 'None');
                $sender->sendMessage($this->getMessage('report.successful', $this->reportCache[$sender->getName()], $this->getConfig()->get('reasons')[$data[0]] ?? 'None'));
            }
        });
        $form->setTitle($this->getMessage('gui.title'));
        $form->setContent($this->getMessage('gui.content', $this->reportCache[$sender->getName()]));
        foreach($this->getConfig()->get('reasons') as $reason){
            $form->addButton($reason);
        }
        if($this->getConfig()->get('allow-custom-reason')){
            $form->addButton($this->getMessage('gui.custom-reason'));
        }
        $form->sendToPlayer($sender);
    }

    private function sendAdminGUI(Player $sender){
        $form = $this->FormAPI->createSimpleForm(function(Player $sender, array $data){
            if($data[0] === null){
                return;
            }
            switch($data[0]){
                case 0:
                    $form = $this->FormAPI->createSimpleForm(function(Player $sender, array $data){
                        if($data[0] === null || count($this->reports->getAll()) < 1){
                            return;
                        }
                        $this->adminCache[$sender->getName()] = $data[0];

                        $form = $this->FormAPI->createSimpleForm(function(Player $sender, array $data){
                            if($data[0] === null) return;
                            $report = $this->reports->get($this->adminCache[$sender->getName()]);
                            switch($data[0]){
                                case 0:
                                    $this->getServer()->getPluginManager()->callEvent($ev = new ReportProcessedEvent($report['target'], $report['reason'], ReportProcessedEvent::PROCESS_TYPE_DELETE));
                                    if(!$ev->isCancelled()){
                                        $this->deleteReport("id", $this->adminCache[$sender->getName()]);
                                        $sender->sendMessage($this->getMessage('admin.deleted'));
                                    }
                                    return;
                                case 1:
                                    $this->getServer()->getPluginManager()->callEvent($ev = new ReportProcessedEvent($report['target'], $report['reason'], ReportProcessedEvent::PROCESS_TYPE_DELETE_ALL));
                                    if(!$ev->isCancelled()){
                                        $this->deleteReport("target", $report['target']);
                                        $sender->sendMessage($this->getMessage('admin.deleted-by-target', $report['target']));
                                    }
                                    return;
                                case 2:
                                    $this->getServer()->getPluginManager()->callEvent($ev = new ReportProcessedEvent($report['target'], $report['reason'], ReportProcessedEvent::PROCESS_TYPE_BAN));
                                    if(!$ev->isCancelled()){
                                        if(($player = $this->getServer()->getOfflinePlayer($report['target'])) !== null) $player->setBanned(true);
                                        $this->deleteReport("target", $report['target']);
                                        $sender->sendMessage($this->getMessage('admin.banned', $report['target']));
                                    }
                                    return;
                                case 3:
                                    $this->sendAdminGUI($sender);
                                    return;
                            }
                        });

                        $report = $this->reports->get($this->adminCache[$sender->getName()]);
                        $form->setTitle($this->getMessage('admin.title'));
                        $count = 0;
                        foreach($this->reports->getAll() as $_report){
                            if(strtolower($_report['target']) == strtolower($report['target'])){
                                $count ++;
                            }
                        }
                        $form->setContent($this->getMessage('admin.detail', $report['target'], $report['reporter'], date("Y-m-d h:i", $report['time']), $report['reason'], $count));
                        $form->addButton($this->getMessage('admin.button.delete'));
                        $form->addButton($this->getMessage('admin.button.delete-all'));
                        $form->addButton($this->getMessage('admin.button.ban'));
                        $form->addButton($this->getMessage('admin.button.back'));
                        $form->sendToPlayer($sender);
                    });

                    $form->setTitle($this->getMessage('admin.title'));
                    $form->setContent($this->getMessage('admin.content'));
                    $reportExist = false;
                    foreach($this->reports->getAll() as $report){
                        $reportExist = true;
                        $form->addButton($this->getMessage('admin.button.report', $report['target'], date("Y-m-d h:i", $report['time'])));
                    }
                    if(!$reportExist){
                        $form->setContent($form->getContent() . $this->getMessage('admin.no-report'));
                        $form->addButton($this->getMessage('admin.button.close'));
                    }
                    $form->sendToPlayer($sender);
                    break;
                case 1:
                    $form = $this->FormAPI->createCustomForm(function(Player $sender, array $data){
                        if(count($data) < 2){
                            return;
                        }
                        if(!$data[1] || !$this->getServer()->getOfflinePlayer($data[1])->getFirstPlayed()){
                            $sender->sendMessage($this->getMessage('gui.player-not-found'));
                            return;
                        }
                        $this->deleteReport("reporter", $data[1]);
                        $sender->sendMessage($this->getMessage('admin.deleted-by-reporter', $data[1]));
                    });

                    $form->addLabel($this->getMessage('admin.delete-by-reporter-content'));
                    $form->addInput($this->getMessage('gui.input'));
                    $form->sendToPlayer($sender);
                    break;
                case 2:
                    $form = $this->FormAPI->createCustomForm(function(Player $sender, array $data){
                        if(count($data) < 2){
                            return;
                        }
                        if(!$data[1] || !$this->getServer()->getOfflinePlayer($data[1])->getFirstPlayed()){
                            $sender->sendMessage($this->getMessage('gui.player-not-found'));
                            return;
                        }
                        $this->deleteReport("target", $data[1]);
                        $sender->sendMessage($this->getMessage('admin.deleted-by-target', $data[1]));
                    });

                    $form->addLabel($this->getMessage('admin.delete-by-target-content'));
                    $form->addInput($this->getMessage('gui.input'));
                    $form->sendToPlayer($sender);
                    break;
            }
        });

        $form->setContent($this->getMessage('admin.main-content'));
        $form->addButton($this->getMessage('admin.button.view-reports'));
        $form->addButton($this->getMessage('admin.button.delete-by-reporter'));
        $form->addButton($this->getMessage('admin.button.delete-by-target'));
        $form->sendToPlayer($sender);
    }
}