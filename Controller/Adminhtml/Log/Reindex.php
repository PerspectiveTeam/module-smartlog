<?php
declare(strict_types=1);

namespace Perspective\SmartLog\Controller\Adminhtml\Log;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Perspective\SmartLog\Model\Config;
use Perspective\SmartLog\Model\Indexer;

class Reindex extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Perspective_SmartLog::reindex';

    public function __construct(
        Action\Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly Indexer $indexer,
        private readonly Config $config
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();

        if (!$this->config->isEnabled()) {
            return $result->setData([
                'success' => false,
                'message' => (string) __('SmartLog is disabled. Enable it in Stores > Configuration > Perspective > SmartLog.'),
            ]);
        }

        try {
            $totalIndexed = $this->indexer->reindex();

            return $result->setData([
                'success' => true,
                'message' => (string) __('Successfully indexed %1 log chunks.', $totalIndexed),
                'total' => $totalIndexed,
            ]);
        } catch (\Exception $e) {
            return $result->setData([
                'success' => false,
                'message' => (string) __('Indexing error: %1', $e->getMessage()),
            ]);
        }
    }
}
