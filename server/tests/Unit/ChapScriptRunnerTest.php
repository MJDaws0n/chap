<?php

namespace Tests\Unit;

use Tests\TestCase;
use Chap\Services\ChapScript\ChapScriptRunner;
use Chap\Services\ChapScript\ChapScriptValidationException;

class ChapScriptRunnerTest extends TestCase
{
    public function testRejectsInvalidVersion(): void
    {
        $this->expectException(ChapScriptValidationException::class);
        ChapScriptRunner::validateScript([
            'chap_script_version' => 999,
            'steps' => [['type' => 'stop', 'message' => 'x']],
        ]);
    }

    public function testRejectsUnknownStepType(): void
    {
        $this->expectException(ChapScriptValidationException::class);
        ChapScriptRunner::validateScript([
            'chap_script_version' => 1,
            'steps' => [['type' => 'run_shell', 'cmd' => 'rm -rf /']],
        ]);
    }

    public function testSetEnvUpdatesEnvMap(): void
    {
        $script = [
            'chap_script_version' => 1,
            'steps' => [
                ['type' => 'set_env', 'key' => 'EULA', 'value' => 'TRUE'],
            ],
        ];

        $res = ChapScriptRunner::run($script, [], ['EULA' => 'FALSE']);
        $this->assertSame('completed', $res['status']);
        $this->assertSame('TRUE', $res['env']['EULA']);
    }

    public function testIfBranchEvaluatesTruthy(): void
    {
        $script = [
            'chap_script_version' => 1,
            'steps' => [
                [
                    'type' => 'if',
                    'condition' => ['op' => 'is_truthy', 'value' => ['env' => 'EULA']],
                    'then' => [['type' => 'set_env', 'key' => 'OK', 'value' => 'yes']],
                    'else' => [['type' => 'set_env', 'key' => 'OK', 'value' => 'no']],
                ],
            ],
        ];

        $res1 = ChapScriptRunner::run($script, [], ['EULA' => 'TRUE']);
        $this->assertSame('completed', $res1['status']);
        $this->assertSame('yes', $res1['env']['OK']);

        $res2 = ChapScriptRunner::run($script, [], ['EULA' => 'FALSE']);
        $this->assertSame('completed', $res2['status']);
        $this->assertSame('no', $res2['env']['OK']);
    }

    public function testPromptConfirmPausesAndResumes(): void
    {
        $script = [
            'chap_script_version' => 1,
            'steps' => [
                [
                    'type' => 'prompt_confirm',
                    'var' => 'accept',
                    'title' => 'Confirm',
                    'description' => 'desc',
                    'confirm' => ['text' => 'OK', 'variant' => 'neutral'],
                    'cancel' => ['text' => 'No'],
                ],
                [
                    'type' => 'if',
                    'condition' => ['op' => 'eq', 'left' => ['var' => 'accept'], 'right' => true],
                    'then' => [['type' => 'set_env', 'key' => 'EULA', 'value' => 'TRUE']],
                    'else' => [['type' => 'stop', 'message' => 'cancelled']],
                ],
            ],
        ];

        $first = ChapScriptRunner::run($script, [], ['EULA' => 'FALSE']);
        $this->assertSame('waiting', $first['status']);
        $this->assertSame('confirm', $first['prompt']['type']);

        $resumeOk = ChapScriptRunner::resume($script, $first['state'], $first['env'], ['confirmed' => true]);
        $this->assertSame('completed', $resumeOk['status']);
        $this->assertSame('TRUE', $resumeOk['env']['EULA']);

        $first2 = ChapScriptRunner::run($script, [], ['EULA' => 'FALSE']);
        $resumeNo = ChapScriptRunner::resume($script, $first2['state'], $first2['env'], ['confirmed' => false]);
        $this->assertSame('stopped', $resumeNo['status']);
    }

    public function testPromptLinksIncludedInPayload(): void
    {
        $script = [
            'chap_script_version' => 1,
            'steps' => [
                [
                    'type' => 'prompt_confirm',
                    'var' => 'ok',
                    'title' => 'T',
                    'description' => 'D',
                    'links' => [
                        ['label' => 'EULA', 'url' => 'https://www.minecraft.net/en-us/eula'],
                    ],
                ],
            ],
        ];

        $res = ChapScriptRunner::run($script, [], []);
        $this->assertSame('waiting', $res['status']);
        $this->assertIsArray($res['prompt']['links']);
        $this->assertSame('EULA', $res['prompt']['links'][0]['label']);
        $this->assertSame('https://www.minecraft.net/en-us/eula', $res['prompt']['links'][0]['url']);
    }

    public function testPromptLinksRejectsJavascriptUrl(): void
    {
        $this->expectException(ChapScriptValidationException::class);

        ChapScriptRunner::validateScript([
            'chap_script_version' => 1,
            'steps' => [
                [
                    'type' => 'prompt_confirm',
                    'var' => 'ok',
                    'title' => 'T',
                    'description' => 'D',
                    'links' => [
                        ['label' => 'Bad', 'url' => 'javascript:alert(1)'],
                    ],
                ],
            ],
        ]);
    }

    public function testMinecraftEulaGateScriptFlow(): void
    {
        $script = [
            'chap_script_version' => 1,
            'steps' => [
                [
                    'type' => 'if',
                    'condition' => ['op' => 'not_truthy', 'value' => ['env' => 'EULA']],
                    'then' => [
                        ['type' => 'prompt_confirm', 'var' => 'accept_eula', 'title' => 'EULA', 'description' => 'x', 'confirm' => ['text' => 'Accept', 'variant' => 'danger']],
                        [
                            'type' => 'if',
                            'condition' => ['op' => 'eq', 'left' => ['var' => 'accept_eula'], 'right' => true],
                            'then' => [['type' => 'set_env', 'key' => 'EULA', 'value' => 'TRUE']],
                            'else' => [['type' => 'stop', 'message' => 'no']],
                        ],
                    ],
                    'else' => [],
                ],
            ],
        ];

        $start = ChapScriptRunner::run($script, [], ['EULA' => 'FALSE']);
        $this->assertSame('waiting', $start['status']);

        $done = ChapScriptRunner::resume($script, $start['state'], $start['env'], ['confirmed' => true]);
        $this->assertSame('completed', $done['status']);
        $this->assertSame('TRUE', $done['env']['EULA']);

        $noPrompt = ChapScriptRunner::run($script, [], ['EULA' => 'TRUE']);
        $this->assertSame('completed', $noPrompt['status']);
    }

    public function testPromptValueNumberParsesToFloat(): void
    {
        $script = [
            'chap_script_version' => 1,
            'steps' => [
                ['type' => 'prompt_value', 'var' => 'n', 'input_type' => 'number', 'title' => 'N'],
                ['type' => 'set_env', 'key' => 'NUM', 'value' => ['var' => 'n']],
            ],
        ];

        $first = ChapScriptRunner::run($script, [], []);
        $this->assertSame('waiting', $first['status']);
        $this->assertSame('value', $first['prompt']['type']);
        $this->assertSame('number', $first['prompt']['input']['type']);

        $done = ChapScriptRunner::resume($script, $first['state'], $first['env'], ['value' => '12.5']);
        $this->assertSame('completed', $done['status']);
        $this->assertSame('12.5', $done['env']['NUM']);
    }

    public function testPromptValueSelectAcceptsString(): void
    {
        $script = [
            'chap_script_version' => 1,
            'steps' => [
                [
                    'type' => 'prompt_value',
                    'var' => 'mode',
                    'input_type' => 'select',
                    'title' => 'Mode',
                    'options' => [
                        ['label' => 'A', 'value' => 'a'],
                        ['label' => 'B', 'value' => 'b'],
                    ],
                ],
                ['type' => 'set_env', 'key' => 'MODE', 'value' => ['var' => 'mode']],
            ],
        ];

        $first = ChapScriptRunner::run($script, [], []);
        $this->assertSame('waiting', $first['status']);

        $done = ChapScriptRunner::resume($script, $first['state'], $first['env'], ['value' => 'b']);
        $this->assertSame('completed', $done['status']);
        $this->assertSame('b', $done['env']['MODE']);
    }
}
