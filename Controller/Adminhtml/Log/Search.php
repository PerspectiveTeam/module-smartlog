<?php
declare(strict_types=1);

namespace Perspective\SmartLog\Controller\Adminhtml\Log;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Perspective\SmartLog\Model\Config;
use Perspective\SmartLog\Model\SearchService;

class Search extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Perspective_SmartLog::search';

    public function __construct(
        Action\Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly SearchService $searchService,
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
            $query = trim((string) $this->getRequest()->getParam('query', ''));
            $limit = (int) $this->getRequest()->getParam('limit', 10);
            $dateFrom = $this->getRequest()->getParam('date_from') ?: null;
            $dateTo = $this->getRequest()->getParam('date_to') ?: null;

            if ($query === '') {
                return $result->setData([
                    'success' => false,
                    'message' => (string) __('Please enter a search query.'),
                ]);
            }

            // Clamp limit
            $limit = max(1, min(100, $limit));

            $results = $this->searchService->search($query, $limit, $dateFrom, $dateTo);

            return $result->setData([
                'success' => true,
                'results' => $results,
                'total' => count($results),
            ]);
        } catch (\Exception $e) {
            return $result->setData([
                'success' => false,
                'message' => (string) __('Search error: %1', $e->getMessage()),
            ]);
        }
    }
}
