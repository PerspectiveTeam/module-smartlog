<?php
declare(strict_types=1);

namespace Perspective\SmartLog\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;

class Config
{
    private const XML_PATH_PREFIX = 'smartlog/';
    private const CATALOG_SEARCH_PREFIX = 'catalog/search/';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_PREFIX . 'general/enabled');
    }

    public function getProvider(): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_PATH_PREFIX . 'embedding/provider');
    }

    public function getApiKey(): string
    {
        $encrypted = (string) $this->scopeConfig->getValue(self::XML_PATH_PREFIX . 'embedding/api_key');
        return $this->encryptor->decrypt($encrypted);
    }

    public function getModel(): string
    {
        $provider = $this->getProvider();
        $field = match ($provider) {
            'gemini' => 'model_gemini',
            'anthropic' => 'model_anthropic',
            default => 'model_openai',
        };

        return (string) $this->scopeConfig->getValue(self::XML_PATH_PREFIX . 'embedding/' . $field);
    }

    public function getChunkSize(): int
    {
        return (int) ($this->scopeConfig->getValue(self::XML_PATH_PREFIX . 'embedding/chunk_size') ?: 50);
    }

    public function getBatchSize(): int
    {
        return (int) ($this->scopeConfig->getValue(self::XML_PATH_PREFIX . 'embedding/batch_size') ?: 20);
    }

    public function getOpenSearchHost(): string
    {
        return (string) ($this->scopeConfig->getValue(self::CATALOG_SEARCH_PREFIX . 'opensearch_server_hostname') ?: 'localhost');
    }

    public function getOpenSearchPort(): int
    {
        return (int) ($this->scopeConfig->getValue(self::CATALOG_SEARCH_PREFIX . 'opensearch_server_port') ?: 9200);
    }

    public function isOpenSearchAuthEnabled(): bool
    {
        return (bool) $this->scopeConfig->getValue(self::CATALOG_SEARCH_PREFIX . 'opensearch_enable_auth');
    }

    public function getOpenSearchUsername(): string
    {
        return (string) $this->scopeConfig->getValue(self::CATALOG_SEARCH_PREFIX . 'opensearch_username');
    }

    public function getOpenSearchPassword(): string
    {
        return (string) $this->scopeConfig->getValue(self::CATALOG_SEARCH_PREFIX . 'opensearch_password');
    }

    public function getOpenSearchTimeout(): int
    {
        return (int) ($this->scopeConfig->getValue(self::CATALOG_SEARCH_PREFIX . 'opensearch_server_timeout') ?: 15);
    }

    public function getOpenSearchIndexName(): string
    {
        return (string) ($this->scopeConfig->getValue(self::XML_PATH_PREFIX . 'opensearch/index_name') ?: 'smartlog_vectors');
    }

    public function getEmbeddingLength(): int
    {
        $provider = $this->getProvider();
        $model = $this->getModel();

        return match ($provider) {
            'openai' => match ($model) {
                'text-embedding-3-large' => 3072,
                default => 1536,
            },
            'gemini' => 768,
            'anthropic' => match ($model) {
                'voyage-3-large' => 2048,
                'voyage-3-lite' => 512,
                default => 1024,
            },
            default => 1536,
        };
    }
}
