<?php

namespace Taylcd\ReportUI\event;

use pocketmine\event\Cancellable;
use pocketmine\event\Event;

class ReportProcessedEvent extends Event implements Cancellable{
    public static $handlerList = null;

    public const PROCESS_TYPE_DELETE = 0;
    public const PROCESS_TYPE_DELETE_ALL = 1;
    public const PROCESS_TYPE_BAN = 2;

    private $reported;
    private $reason;
    private $processType;

    public function __construct(string $reported, string $reason, int $processType){
        $this->reported = $reported;
        $this->reason = $reason;
        $this->processType = $processType;
    }

    public function getReported(){
        return $this->reported;
    }

    public function getReason(){
        return $this->reason;
    }

    public function getProcessType(){
        return $this->processType;
    }
}