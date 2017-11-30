<?php

namespace Taylcd\ReportGUI;

use jojoe77777\FormAPI\FormAPI;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;

class ReportGUI extends PluginBase implements Listener
{
    /** @var Config */
    protected $lang;

    /** @var Config */
    protected $reports;

    /** @var FormAPI */
    protected $FormAPI;

    private $selection = [], $admin_selection = [];

    public function onLoad()
    {
        $folder = $this->getDataFolder();
        $this->saveDefaultConfig();
        $this->saveResource('language.yml');

        $this->lang = new Config($folder . 'language.yml', Config::YAML);
        $this->reports = new Config($folder . 'reports.yml', Config::YAML);
    }

    public function onEnable()
    {
        $this->FormAPI = $this->getServer()->getPluginManager()->getPlugin("FormAPI");
        if(!$this->FormAPI or $this->FormAPI->isDisabled())
        {
            $this->getLogger()->warning('Dependency FormAPI not found, disabling...');
            $this->getPluginLoader()->disablePlugin($this);
        }
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->getServer()->getScheduler()->scheduleDelayedRepeatingTask(new SaveTask($this), $this->getConfig()->get('save-period', 600) * 20, $this->getConfig()->get('save-period', 600) * 20);
        $this->getServer()->getLogger()->info(TextFormat::AQUA . 'ReportGUI enabled. ' . TextFormat::GRAY . 'Made by Taylcd with ' . TextFormat::RED . "\xe2\x9d\xa4");
    }

    public function onDisable()
    {
        $this->save();
    }

    public function onPlayerJoin(PlayerJoinEvent $event)
    {
        if($event->getPlayer()->isOp()) if($count = count($this->reports->getAll())) $event->getPlayer()->sendMessage($this->getMessage('admin.unread-reports', $count));
    }

    public function getMessage($key, ...$replacement): string
    {
        $message = $this->lang->getNested($key, 'Missing message: ' . $key);
        foreach($replacement as $index => $value) $message = str_replace("%$index", $value, $message);
        return $message;
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool
    {
        if(!$sender instanceof Player)
        {
            $sender->sendMessage(TextFormat::RED . 'This command can only be called in-game.');
            return true;
        }
        switch($command->getName())
        {
            case 'report':
                if(!isset($args[0])) unset($this->selection[$sender->getName()]);
                else $this->selection[$sender->getName()] = $args[0];
                $this->sendReportGUI($sender);
                return true;
            case 'reportadmin':
                if(isset($args[0]) && ($args[0] == 'deletebyreporter' || $args[0] == 'dbr'))
                {
                    if(!isset($args[1]))
                    {
                        $sender->sendMessage($this->getMessage('admin.name-not-entered'));
                    }
                    $this->deleteReportByReporter($args[1]);
                    $sender->sendMessage($this->getMessage('admin.deleted-by-reporter'), $args[1]);
                    return true;
                }
                if(isset($args[0]) && ($args[0] == 'deletebytarget' || $args[0] == 'dbt'))
                {
                    if(!isset($args[1]))
                    {
                        $sender->sendMessage($this->getMessage('admin.name-not-entered'));
                    }
                    $this->deleteReportByTarget($args[1]);
                    $sender->sendMessage($this->getMessage('admin.deleted-by-target'), $args[1]);
                    return true;
                }
                $this->sendAdminGUI($sender);
        }
        return true;
    }

    private function sendReportGUI(Player $sender)
    {
        if(isset($this->selection[$sender->getName()]))
        {
            $this->sendReasonSelect($sender);
            return;
        }
        $form = $this->FormAPI->createCustomForm(function(Player $sender, array $data)
        {
            if(count($data) < 2) return;
            $this->selection[$sender->getName()] = $data[1];
            $this->sendReasonSelect($sender);
        });
        $form->setTitle($this->getMessage('gui.title'));
        $form->addLabel($this->getMessage('gui.label'));
        $form->addInput($this->getMessage('gui.input'));
        $form->sendToPlayer($sender);
    }

    private function sendReasonSelect(Player $sender)
    {
        $name = $this->selection[$sender->getName()];
        if(!$name || !$this->getServer()->getOfflinePlayer($name)->getFirstPlayed())
        {
            $sender->sendMessage($this->getMessage('gui.player-not-found'));
            return;
        }
        if(strtolower($name) == strtolower($sender->getName()))
        {
            $sender->sendMessage($this->getMessage('gui.cant-report-self'));
            return;
        }
        if($this->getServer()->getOfflinePlayer($this->selection[$sender->getName()])->isOp() && !$this->getConfig()->get('allow-reporting-ops'))
        {
            $sender->sendMessage($this->getMessage('report.op'));
            return;
        }
        if($this->getServer()->getOfflinePlayer($this->selection[$sender->getName()])->isBanned() && !$this->getConfig()->get('allow-reporting-banned-players'))
        {
            $sender->sendMessage($this->getMessage('report.banned'));
            return;
        }
        $form = $this->FormAPI->createSimpleForm(function(Player $sender, array $data)
        {
            if($data[0] === null) return;
            if($data[0] == count($this->getConfig()->get('reasons')))
            {
                if(!$this->getConfig()->get('allow-custom-reason')) return;
                $form = $this->FormAPI->createCustomForm(function(Player $sender, array $data)
                {
                    if (count($data) < 2) return;
                    if(!$data[1] || strlen($data[1]) < $this->getConfig()->get('custom-reason-min-length', 4) || strlen($data[1]) < $this->getConfig()->get('custom-reason-min-length', 4))
                    {
                        $sender->sendMessage($this->getMessage('report.bad-reason'));
                        return;
                    }
                    $this->addReport($sender->getName(), $this->selection[$sender->getName()], $data[1]);
                    $sender->sendMessage($this->getMessage('report.successful', $this->selection[$sender->getName()], $data[1]));
                });
                $form->setTitle($this->getMessage('gui.title'));
                $form->addLabel($this->getMessage('gui.custom.label', $this->selection[$sender->getName()]));
                $form->addInput($this->getMessage('gui.custom.input'));
                $form->sendToPlayer($sender);
                return;
            }
            $this->addReport($sender->getName(), $this->selection[$sender->getName()], $this->getConfig()->get('reasons')[$data[0]] ?? 'None');
            $sender->sendMessage($this->getMessage('report.successful', $this->selection[$sender->getName()], $this->getConfig()->get('reasons')[$data[0]] ?? 'None'));
        });
        $form->setTitle($this->getMessage('gui.title'));
        $form->setContent($this->getMessage('gui.content', $this->selection[$sender->getName()]));
        foreach($this->getConfig()->get('reasons') as $reason)
        {
            $form->addButton($reason);
        }
        if($this->getConfig()->get('allow-custom-reason')) $form->addButton($this->getMessage('gui.custom-reason'));
        $form->sendToPlayer($sender);
    }

    private function sendAdminGUI(Player $sender)
    {
        $form = $this->FormAPI->createSimpleForm(function(Player $sender, array $data)
        {
            if($data[0] === null || count($this->reports->getAll()) < 1) return;
            $this->admin_selection[$sender->getName()] = $data[0];
            $form = $this->FormAPI->createSimpleForm(function(Player $sender, array $data)
            {
                if($data[0] === null) return;
                $report = $this->reports->get($this->admin_selection[$sender->getName()]);
                switch($data[0])
                {
                    case 0:
                        $this->deleteReport($this->admin_selection[$sender->getName()]);
                        $sender->sendMessage($this->getMessage('admin.deleted'));
                        return;
                    case 1:
                        $this->deleteReportByTarget($report['target']);
                        $sender->sendMessage($this->getMessage('admin.deleted-by-target', $report['target']));
                        return;
                    case 2:
                        if(($player = $this->getServer()->getOfflinePlayer($report['target'])) !== null) $player->setBanned(true);
                        $this->deleteReportByTarget($report['target']);
                        $sender->sendMessage($this->getMessage('admin.banned', $report['target']));
                        return;
                    case 3:
                        $this->sendAdminGUI($sender);
                        return;
                }
            });
            $report = $this->reports->get($this->admin_selection[$sender->getName()]);
            $form->setTitle($this->getMessage('admin.title'));
            $count = 0;
            foreach($this->reports->getAll() as $_report)
            {
                if(strtolower($_report['target']) == strtolower($report['target'])) $count ++;
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
        $foo = false;
        foreach($this->reports->getAll() as $report)
        {
            $foo = true;
            $form->addButton($this->getMessage('admin.button.report', $report['target'], date("Y-m-d h:i", $report['time'])));
        }
        if(!$foo)
        {
            $form->addButton($this->getMessage('admin.no-report'));
        }
        $form->sendToPlayer($sender);
    }

    private function addReport(string $reporter, string $target, string $reason)
    {
        $reports = $this->reports->getAll();
        array_unshift($reports, ['reporter'=>$reporter, 'target'=>$target, 'reason'=>$reason, 'time' => time()]);
        $this->reports->setAll($reports);
    }

    public function save()
    {
        $this->reports->save();
    }

    private function deleteReport(int $id)
    {
        $reports = $this->reports->getAll();
        array_splice($reports, $id, 1);
        $this->reports->setAll($reports);
    }

    private function deleteReportByTarget(string $name)
    {
        $reports = $this->reports->getAll();
        for($i = 0; $i < count($reports); $i ++)
            if(strtolower($reports[$i]['target']) == strtolower($name))
            {
                array_splice($reports, $i, 1);
                $i --;
            }
        $this->reports->setAll($reports);
    }

    private function deleteReportByReporter(string $name)
    {
        $reports = $this->reports->getAll();
        for($i = 0; $i < count($reports); $i ++)
            if(strtolower($reports[$i]['reporter']) == strtolower($name))
            {
                array_splice($reports, $i, 1);
                $i --;
            }
        $this->reports->setAll($reports);
    }
}