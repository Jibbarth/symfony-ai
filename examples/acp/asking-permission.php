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
use Symfony\AI\Platform\Bridge\Acp\PermissionRequest;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallComplete;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallStart;
use Symfony\AI\Platform\Result\Stream\Delta\ToolInputDelta;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Question\ChoiceQuestion;

require_once dirname(__DIR__).'/bootstrap.php';

function askPermission(string $title, string $kind, array $options, ConsoleOutput $output): ?string
{
    $helper = new QuestionHelper();
    $input = new ArgvInput();

    $choices = array_column($options, 'name');
    $question = new ChoiceQuestion(
        sprintf('<question>Permission requested: %s (%s)</question> ', $title, $kind),
        $choices,
        0
    );
    $question->setErrorMessage('Invalid choice. Please select a valid option.');

    $selectedName = $helper->ask($input, $output, $question);
    $selectedOption = array_filter($options, static fn ($opt) => $opt['name'] === $selectedName);
    $selectedOption = array_values($selectedOption);

    return $selectedOption[0]['optionId'] ?? null;
}

$platform = Factory::createPlatform(
    command: env('ACP_BINARY'),
    logger: logger(),
    onPermissionRequest: static fn (PermissionRequest $request): ?string => askPermission(
        $request->title,
        $request->kind,
        $request->options,
        output()
    ),
);

$result = $platform->invoke('acp-v1', 'Delete the file /tmp/unwanted.txt', ['stream' => true]);

foreach ($result->asStream() as $delta) {
    if ($delta instanceof TextDelta) {
        output()->write($delta->getText());
        continue;
    }

    if ($delta instanceof ThinkingDelta) {
        output()->write('<fg=#999999>'.$delta->getThinking().'</>');
        continue;
    }

    if ($delta instanceof ToolCallStart) {
        output()->writeln(\PHP_EOL.'<info>[tool: '.$delta->getName().']</info>');
        continue;
    }

    if ($delta instanceof ToolInputDelta) {
        output()->write('<fg=#999999>'.$delta->getPartialJson().'</>');
        continue;
    }

    if ($delta instanceof ToolCallComplete) {
        foreach ($delta->getToolCalls() as $toolCall) {
            output()->writeln(\PHP_EOL.'<info>[tool completed: '.$toolCall->getName().']</info>');
            $output = $toolCall->getArguments()['output'] ?? null;
            if (is_string($output) && '(no output)' !== $output && '' !== $output) {
                output()->writeln('<fg=#999999>[tool output]</> '.$output);
            }
        }
    }
}

output()->writeln('');
