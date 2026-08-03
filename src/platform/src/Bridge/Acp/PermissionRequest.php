<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Platform\Bridge\Acp;

/**
 * Represents an ACP permission request from the agent.
 */
final class PermissionRequest
{
    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        public readonly string $sessionId,
        public readonly string $toolCallId,
        public readonly string $title,
        public readonly string $kind,
        public readonly string $status,
        public readonly array $options,
    ) {
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function fromParams(array $params): self
    {
        $toolCall = $params['toolCall'] ?? [];
        $options = $params['options'] ?? [];

        return new self(
            sessionId: $params['sessionId'] ?? '',
            toolCallId: $toolCall['toolCallId'] ?? '',
            title: $toolCall['title'] ?? '',
            kind: $toolCall['kind'] ?? '',
            status: $toolCall['status'] ?? '',
            options: $options,
        );
    }
}
