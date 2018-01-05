<?php

namespace Taylcd\ReportUI\task;

use pocketmine\scheduler\PluginTask;

class SaveTask extends PluginTask{
    public function onRun(int $currentTick){
        $this->getOwner()->save();
    }
}