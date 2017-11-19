<?php

namespace Taylcd\ReportGUI;

use pocketmine\scheduler\PluginTask;

class SaveTask extends PluginTask
{
    public function onRun(int $currentTick)
    {
        $this->getOwner()->save();
    }
}