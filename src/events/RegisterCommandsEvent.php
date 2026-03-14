<?php

namespace samuelreichor\coPilot\events;

use samuelreichor\coPilot\commands\CommandInterface;
use yii\base\Event;

/**
 * Fired when registering available slash commands.
 * Allows adding custom prompt commands that can be triggered from the chat input.
 */
class RegisterCommandsEvent extends Event
{
    /** @var CommandInterface[] */
    public array $commands = [];
}
