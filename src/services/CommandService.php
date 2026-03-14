<?php

namespace samuelreichor\coPilot\services;

use samuelreichor\coPilot\commands\CommandInterface;
use samuelreichor\coPilot\events\RegisterCommandsEvent;
use yii\base\Component;

class CommandService extends Component
{
    /**
     * Event fired when registering available commands.
     */
    public const EVENT_REGISTER_COMMANDS = 'registerCommands';

    /**
     * Get all registered commands.
     *
     * @return CommandInterface[]
     */
    public function getCommands(): array
    {
        $event = new RegisterCommandsEvent();

        $this->trigger(self::EVENT_REGISTER_COMMANDS, $event);

        return $event->commands;
    }

    /**
     * Get commands as arrays for the frontend.
     *
     * @return array<int, array{name: string, description: string, prompt: string, params?: array<int, array<string, mixed>>}>
     */
    public function getCommandDefinitions(): array
    {
        return array_map(function(CommandInterface $cmd) {
            $def = [
                'name' => $cmd->getName(),
                'description' => $cmd->getDescription(),
                'prompt' => $cmd->getPrompt(),
            ];

            $param = $cmd->getParam();
            if ($param !== null) {
                $def['param'] = $param;
            }

            return $def;
        }, $this->getCommands());
    }
}
