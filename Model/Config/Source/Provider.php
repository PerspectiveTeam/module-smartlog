<?php
declare(strict_types=1);

namespace Perspective\SmartLog\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class Provider implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'openai', 'label' => __('OpenAI')],
            ['value' => 'gemini', 'label' => __('Google Gemini')],
            ['value' => 'anthropic', 'label' => __('Anthropic (Voyage AI)')],
        ];
    }
}
