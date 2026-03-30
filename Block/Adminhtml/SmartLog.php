<?php
declare(strict_types=1);

namespace Perspective\SmartLog\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Perspective\SmartLog\Model\Config;

class SmartLog extends Template
{
    public function __construct(
        Context $context,
        private readonly Config $config,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function isEnabled(): bool
    {
        return $this->config->isEnabled();
    }

    public function getSearchUrl(): string
    {
        return $this->getUrl('smartlog/log/search');
    }

    public function getReindexUrl(): string
    {
        return $this->getUrl('smartlog/log/reindex');
    }

    public function getProvider(): string
    {
        return ucfirst($this->config->getProvider());
    }

    public function getModel(): string
    {
        return $this->config->getModel();
    }

    public function getConfigUrl(): string
    {
        return $this->getUrl('adminhtml/system_config/edit', ['section' => 'smartlog']);
    }
}
