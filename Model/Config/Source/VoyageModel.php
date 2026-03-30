<?php
declare(strict_types=1);

namespace Perspective\SmartLog\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class VoyageModel implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'voyage-3', 'label' => __('voyage-3 (1024 dim, recommended)')],
            ['value' => 'voyage-3-lite', 'label' => __('voyage-3-lite (512 dim, faster)')],
            ['value' => 'voyage-3-large', 'label' => __('voyage-3-large (2048 dim)')],
        ];
    }
}
