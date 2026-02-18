<?php

namespace Lastdino\Monox\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class ApiSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_sync_requires_api_key_when_configured()
    {
        Config::set('monox.api_key', 'secret-key');

        // APIキーなしのアクセス
        $response = $this->postJson('/api/monox/v1/inventory/sync', []);
        $response->assertStatus(401);

        // 間違ったAPIキーでのアクセス
        $response = $this->postJson('/api/monox/v1/inventory/sync', [], [
            'X-API-KEY' => 'wrong-key',
        ]);
        $response->assertStatus(401);

        // 正しいAPIキーでのアクセス
        // (404 または 200/422 になるはず。InventoryControllerが実装されているなら。
        // ここでは認証をパスしたことを確認できれば良い)
        $response = $this->postJson('/api/monox/v1/inventory/sync', [], [
            'X-API-KEY' => 'secret-key',
        ]);

        // 認証をパスすれば、バリデーションエラー(422)か実装内容に応じたレスポンスになる
        // 401ではないことを確認する
        $this->assertNotEquals(401, $response->getStatusCode());
    }

    public function test_api_sync_allows_access_when_api_key_is_not_configured()
    {
        Config::set('monox.api_key', null);

        $response = $this->postJson('/api/monox/v1/inventory/sync', []);

        // 認証なしでアクセスできるため、401にはならない
        $this->assertNotEquals(401, $response->getStatusCode());
    }
}
