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
        $form = $this->FormAPI->createCustomForm(function(Player $sender, array $data)
        {
            if(count($data) < 2) return;
            if(!$data[1] || !$this->getServer()->getOfflinePlayer($data[1])->getFirstPlayed())
            {
                $sender->sendMessage($this->getMessage('gui.player-not-found'));
                return;
            }
            if(strtolower($data[1]) == strtolower($sender->getName()))
            {
                $sender->sendMessage($this->getMessage('gui.cant-report-self'));
                return;
            }
            $this->selection[$sender->getName()] = $data[1];
            $form = $this->FormAPI->createSimpleForm(function(Player $sender, array $data)
            {
                if($data[0] === null) return;
                $this->addReport($sender->getName(), $this->selection[$sender->getName()], $this->getConfig()->get('reasons')[$data[0]] ?? 'None');
                $sender->sendMessage($this->getMessage('report.successful', $this->selection[$sender->getName()], $this->getConfig()->get('reasons')[$data[0]] ?? 'None'));
            });
            $form->setTitle($this->getMessage('gui.title'));
            $form->setContent($this->getMessage('gui.content', $this->selection[$sender->getName()]));
            foreach($this->getConfig()->get('reasons') as $reason)
            {
                $form->addButton($reason);
            }
            $form->sendToPlayer($sender);
        });
        $form->setTitle($this->getMessage('gui.title'));
        $form->addLabel($this->getMessage('gui.label'));
        $form->addInput($this->getMessage('gui.input'));
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
                        $sender->sendMessage($this->getMessage('banned', $report['target']));
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
        foreach($reports as $index => $report)
        {
            if(strtolower($report['target']) == strtolower($name)) array_splice($reports, $index, 1);
        }
        $this->reports->setAll($reports);
    }

    private function deleteReportByReporter(string $name)
    {
        $reports = $this->reports->getAll();
        foreach($reports as $index => $report)
        {
            if(strtolower($report['reporter']) == strtolower($name)) array_splice($reports, $index, 1);
        }
        $this->reports->setAll($reports);
    }
}