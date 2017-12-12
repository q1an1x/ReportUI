<?php

namespace Taylcd\ReportUI;

use pocketmine\scheduler\PluginTask;

class SaveTask extends PluginTask
{
    public function onRun(int $currentTick)
    {
        $this->getOwner()->save();
    }
}