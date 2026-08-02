<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Symfony\AI\Platform\Bridge\Acp\Factory;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallComplete;

require_once dirname(__DIR__).'/bootstrap.php';

$platform = Factory::createPlatform(
    command: env('ACP_BINARY'),
    logger: logger(),
);

$result = $platform->invoke('acp-v1', 'Delete the file /tmp/unwanted.txt', ['stream' => true]);

foreach ($result->asStream() as $delta) {
    if ($delta instanceof ThinkingDelta) {
        output()->write('<fg=#999999>'.$delta->getThinking().'</>');
        continue;
    }

    if ($delta instanceof TextDelta) {
        output()->write($delta->getText());
        continue;
    }

    if ($delta instanceof ToolCallComplete) {
        foreach ($delta->getToolCalls() as $toolCall) {
            output()->writeln('');
            output()->writeln('<comment>[Permission requested]</comment> '.$toolCall->getName());
            output()->writeln('<error>[Permission denied by default]</error>');
            $output = $toolCall->getArguments()['output'] ?? null;
            if (is_string($output) && '' !== $output) {
                output()->writeln('<fg=#999999>[tool output]</> '.$output);
            }
        }
    }
}
