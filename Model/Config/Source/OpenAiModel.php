<?php
declare(strict_types=1);

namespace Perspective\SmartLog\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class OpenAiModel implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'text-embedding-3-small', 'label' => __('text-embedding-3-small (1536 dim, recommended)')],
            ['value' => 'text-embedding-3-large', 'label' => __('text-embedding-3-large (3072 dim)')],
            ['value' => 'text-embedding-ada-002', 'label' => __('text-embedding-ada-002 (1536 dim, legacy)')],
        ];
    }
}
