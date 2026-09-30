<?php
namespace App\Enums;
enum ChatbotType: string {
    case Support='support'; case Sales='sales'; case FAQ='faq'; case WooCommerce='woocommerce'; case HR='hr'; case Custom='custom'; case Trial='trial';

    /** The type itself, not a ChatbotTypePrice catalogue row's own bilingual name — every admin selector/badge that shows the raw type used to print the bare English value regardless of locale. */
    public function label(): string {
        return __('chatbot.type_' . $this->value);
    }
}
