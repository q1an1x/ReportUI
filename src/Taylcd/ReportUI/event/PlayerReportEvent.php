<?php

namespace Taylcd\ReportUI\event;

use pocketmine\event\Cancellable;
use pocketmine\event\player\PlayerEvent;
use pocketmine\Player;

class PlayerReportEvent extends PlayerEvent implements Cancellable{
    public static $handlerList = null;

    private $reported;
    private $reason;

    public function __construct(Player $player, string $reported, string $reason){
        $this->player = $player;
        $this->reported = $reported;
        $this->reason = $reason;
    }

    public function getReported(){
        return $this->reported;
    }

    public function getReason(){
        return $this->reason;
    }
}