<?php

namespace Tests\Unit;

use Tests\TestCase;
use Chap\Services\DynamicEnv;

class DynamicEnvTest extends TestCase
{
    public function testResolvesPortsAndScalarPlaceholders(): void
    {
        $env = [
            'APP_NAME' => '{name}',
            'NODE' => '{node}',
            'REPO' => '{repo}',
            'BRANCH' => '{repo_brach}',
            'CPU' => '{cpu}',
            'RAM_MB' => '{ram}',
            'PORT0' => '{port[0]}',
            'MIXED' => 'mc:{repo_brach}@{port[0]} mem={ram}M',
        ];

        $ports = [25565];
        $ctx = [
            'name' => 'my-app',
            'node' => 'node-1',
            'repo' => 'https://example.com/repo.git',
            'repo_branch' => 'main',
            'cpu' => '1',
            'ram' => 2048,
        ];

        $res = DynamicEnv::resolve($env, $ports, $ctx);
        $this->assertSame([], $res['errors']);
        $this->assertSame('my-app', $res['resolved']['APP_NAME']);
        $this->assertSame('node-1', $res['resolved']['NODE']);
        $this->assertSame('https://example.com/repo.git', $res['resolved']['REPO']);
        $this->assertSame('main', $res['resolved']['BRANCH']);
        $this->assertSame('1', $res['resolved']['CPU']);
        $this->assertSame('2048', $res['resolved']['RAM_MB']);
        $this->assertSame('25565', $res['resolved']['PORT0']);
        $this->assertSame('mc:main@25565 mem=2048M', $res['resolved']['MIXED']);
    }

    public function testMissingNonOptionalPlaceholderProducesErrorAndLeavesToken(): void
    {
        $env = [
            'RAM_MB' => '{ram}',
        ];

        $res = DynamicEnv::resolve($env, [], ['name' => 'x']);
        $this->assertArrayHasKey('RAM_MB', $res['errors']);
        $this->assertSame('{ram}', $res['resolved']['RAM_MB']);
    }

    public function testMissingOptionalRepoDoesNotError(): void
    {
        $env = [
            'REPO' => '{repo}',
            'BRANCH' => '{repo_brach}',
        ];

        $res = DynamicEnv::resolve($env, [], ['name' => 'x', 'ram' => 1, 'cpu' => '1', 'node' => 'n']);
        $this->assertSame([], $res['errors']);
        $this->assertSame('', $res['resolved']['REPO']);
        $this->assertSame('', $res['resolved']['BRANCH']);
    }
}
