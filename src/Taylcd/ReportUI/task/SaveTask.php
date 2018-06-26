<?php

namespace Taylcd\ReportUI\task;

use pocketmine\scheduler\Task;

class SaveTask extends Task {
    public function onRun(int $currentTick){
        $this->getOwner()->save();
    }
}