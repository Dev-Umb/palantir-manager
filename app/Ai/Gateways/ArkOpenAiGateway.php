<?php

namespace App\Ai\Gateways;

use Laravel\Ai\Gateway\OpenAi\OpenAiGateway;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;

class ArkOpenAiGateway extends OpenAiGateway
{
    /**
     * Map an assistant message without changing Ark's streamed item order.
     */
    protected function mapAssistantMessage(AssistantMessage|Message $message, array &$input): void
    {
        if (! $message instanceof AssistantMessage
            || blank($message->content)
            || $message->toolCalls->isEmpty()) {
            parent::mapAssistantMessage($message, $input);

            return;
        }

        $input[] = [
            'role' => 'assistant',
            'content' => [
                [
                    'type' => 'output_text',
                    'text' => $message->content,
                ],
            ],
        ];

        parent::mapAssistantMessage(
            new AssistantMessage('', $message->toolCalls, $message->providerContentBlocks),
            $input,
        );
    }
}
