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
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingDelta;

require_once dirname(__DIR__).'/bootstrap.php';

$platform = Factory::createPlatform(
    workingDirectory: dirname(__DIR__, 2),
    command: env('ACP_BINARY'),
    logger: logger(),
);

$messages = new MessageBag(
    Message::ofUser('What is Symfony? Explain in 3 sentences. Do not use skills'),
);

$result = $platform->invoke('acp-v1', $messages, ['stream' => true]);

foreach ($result->asStream() as $delta) {
    if ($delta instanceof TextDelta) {
        output()->write($delta->getText());
        continue;
    }

    if ($delta instanceof ThinkingDelta) {
        output()->write('<fg=#999999>'.$delta->getThinking().'</>');
    }
}

echo \PHP_EOL;
