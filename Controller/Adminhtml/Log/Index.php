<?php
declare(strict_types=1);

namespace Perspective\SmartLog\Controller\Adminhtml\Log;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;

class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Perspective_SmartLog::search';

    public function execute()
    {
        $resultPage = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $resultPage->setActiveMenu('Perspective_SmartLog::search');
        $resultPage->getConfig()->getTitle()->prepend(__('SmartLog — Log Search'));

        return $resultPage;
    }
}
