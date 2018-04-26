/*browser:true*/
/*global define*/
define(
    [
        'uiComponent',
        'Magento_Checkout/js/model/payment/renderer-list'
    ],
    function (
        Component,
        rendererList
    ) {
        'use strict';
        rendererList.push(
            {
                type: 'pumbolt',
                component: 'PayUM_Pumbolt/js/view/payment/method-renderer/pumbolt-method'
            }
        );
        /** Add view logic here if needed */
        return Component.extend({});
    }
);
