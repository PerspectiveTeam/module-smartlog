<?php
declare(strict_types=1);

namespace Perspective\SmartLog\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class GeminiModel implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'text-embedding-004', 'label' => __('text-embedding-004 (768 dim, recommended)')],
            ['value' => 'embedding-001', 'label' => __('embedding-001 (768 dim, legacy)')],
        ];
    }
}
