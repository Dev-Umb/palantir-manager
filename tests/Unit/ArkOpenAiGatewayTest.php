<?php

namespace Tests\Unit;

use App\Ai\Gateways\ArkOpenAiGateway;
use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Responses\Data\ToolCall;
use PHPUnit\Framework\TestCase;

class ArkOpenAiGatewayTest extends TestCase
{
    public function test_text_preceding_a_tool_call_keeps_its_original_order(): void
    {
        $input = $this->gateway()->mapAssistantMessageForTest(
            new AssistantMessage(
                '请填写以下信息。',
                collect([
                    new ToolCall(
                        id: 'tool-item-1',
                        name: 'present_user_form',
                        arguments: ['form' => 'requisition'],
                        resultId: 'call-1',
                    ),
                ]),
            ),
        );

        $this->assertSame(['assistant', 'function_call'], [
            $input[0]['role'],
            $input[1]['type'],
        ]);
        $this->assertSame('请填写以下信息。', $input[0]['content'][0]['text']);
        $this->assertSame('present_user_form', $input[1]['name']);
    }

    public function test_tool_only_messages_keep_the_standard_openai_mapping(): void
    {
        $input = $this->gateway()->mapAssistantMessageForTest(
            new AssistantMessage(
                '',
                collect([
                    new ToolCall(
                        id: 'tool-item-1',
                        name: 'present_user_form',
                        arguments: [],
                        resultId: 'call-1',
                    ),
                ]),
            ),
        );

        $this->assertCount(1, $input);
        $this->assertSame('function_call', $input[0]['type']);
        $this->assertSame('{}', $input[0]['arguments']);
    }

    public function test_plain_assistant_messages_keep_the_standard_openai_mapping(): void
    {
        $input = $this->gateway()->mapAssistantMessageForTest(
            new Message('assistant', '普通回答'),
        );

        $this->assertCount(1, $input);
        $this->assertSame('assistant', $input[0]['role']);
        $this->assertSame('普通回答', $input[0]['content'][0]['text']);
    }

    private function gateway(): ArkOpenAiGateway
    {
        $events = $this->createMock(Dispatcher::class);

        return new class($events) extends ArkOpenAiGateway
        {
            public function mapAssistantMessageForTest(AssistantMessage|Message $message): array
            {
                $input = [];
                $this->mapAssistantMessage($message, $input);

                return $input;
            }
        };
    }
}
