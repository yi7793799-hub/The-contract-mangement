<?php
declare(strict_types=1);

namespace App\Services;

/**
 * API 余额查询服务
 * 用于查询 DeepSeek 和 Gitee AI 的账户余额
 */
class ApiBalanceService
{
    /**
     * 查询 DeepSeek 账户余额
     */
    public function getDeepSeekBalance(): array
    {
        $config = deepseek_config();
        $apiKey = $config['api_key'] ?? '';

        if (empty($apiKey)) {
            return ['success' => false, 'error' => 'API Key 未配置'];
        }

        try {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => 'https://api.deepseek.com/user/balance',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $apiKey,
                    'Content-Type: application/json',
                ],
                CURLOPT_TIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error) {
                return ['success' => false, 'error' => '网络错误: ' . $error];
            }

            if ($httpCode !== 200) {
                return ['success' => false, 'error' => "HTTP {$httpCode}"];
            }

            $data = json_decode($response, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return ['success' => false, 'error' => 'JSON 解析失败'];
            }

            // DeepSeek 返回格式: {"is_available":true,"balance_infos":[{"currency":"CNY","total_balance":"10.00","granted_balance":"0.00","topped_up_balance":"10.00"}]}
            $balanceInfo = $data['balance_infos'][0] ?? [];

            return [
                'success' => true,
                'currency' => $balanceInfo['currency'] ?? 'CNY',
                'total_balance' => floatval($balanceInfo['total_balance'] ?? 0),
                'granted_balance' => floatval($balanceInfo['granted_balance'] ?? 0),
                'topped_up_balance' => floatval($balanceInfo['topped_up_balance'] ?? 0),
                'is_available' => $data['is_available'] ?? false,
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * 查询 Gitee AI 账户余额
     * Gitee AI 暂无公开的余额查询 API，需要通过调用测试来判断
     */
    public function getGiteeBalance(): array
    {
        // Gitee AI 暂无公开的余额查询接口
        // 返回提示信息
        return [
            'success' => true,
            'note' => 'Gitee AI 暂不支持余额查询',
            'total_balance' => null,
        ];
    }

    /**
     * 获取所有 API 余额
     */
    public function getAllBalances(): array
    {
        return [
            'deepseek' => $this->getDeepSeekBalance(),
            'gitee' => $this->getGiteeBalance(),
        ];
    }
}
