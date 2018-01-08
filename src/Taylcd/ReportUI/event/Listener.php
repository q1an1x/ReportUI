<?php

namespace Taylcd\ReportUI\event;

use Taylcd\ReportUI\ReportUI;

class Listener implements \pocketmine\event\Listener{
    /** @var ReportUI */
    private $plugin;

    public function __construct(ReportUI $plugin){
        $this->plugin = $plugin;
    }

    public function onPlayerJoin(\pocketmine\event\player\PlayerJoinEvent $event){
        if($this->plugin->getConfig()->get("enable-new-report-notification-on-join", true) && $event->getPlayer()->hasPermission("report.admin.notification") && $count = count($this->plugin->getReports()->getAll())){
            $event->getPlayer()->sendMessage($this->plugin->getMessage('admin.unread-reports', $count));
        }
    }
}