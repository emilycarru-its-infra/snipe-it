<?php

return [
    'none' => 'There are no orders yet.',
    'not_linked' => 'This asset is not linked to any orders.',
    'delete_confirm' => 'Are you sure you want to delete this order?',

    'create' => [
        'success' => 'Order created successfully.',
    ],

    'update' => [
        'success' => 'Order updated successfully.',
    ],

    'delete' => [
        'success' => 'Order deleted successfully.',
    ],

    'purchase_order' => [
        'linked' => 'Order linked to purchase order :po.',
        'unlinked' => 'Order unlinked from its purchase order.',
        'log_note' => 'Purchase order changed from :from to :to; :items line items and :invoices invoices moved with it.',
        'none' => 'none',
    ],

    'vendor_order' => [
        'set' => 'Vendor order number set to :number.',
        'cleared' => 'Vendor order number cleared.',
        'log_note' => 'Vendor order number changed from :from to :to; order number :order_from → :order_to.',
        'none' => 'none',
    ],

    'cancel_success' => 'Order cancelled.',
    'reopen_success' => 'Order reopened.',

    'item' => [
        'add_success' => 'Line item added to the order.',
        'delete_success' => 'Line item removed from the order.',
        'receive_success' => 'Line item marked as received.',
        'nothing_to_receive' => 'Every line on this order is already received.',
        'no_items_named' => 'No line items were named. Omit items entirely to receive every open line.',
        'unreceive_success' => 'Line item marked as not received.',
        'type_invalid' => 'That item type cannot be added to an order.',
        'not_found' => 'The selected item could not be found.',
        'ingest_not_found' => 'No :type exists with id :id.',
    ],

    'shipment' => [
        'add_success' => 'Shipment added to the order.',
        'update_success' => 'Shipment updated.',
        'delete_success' => 'Shipment removed from the order.',
        'receive_success' => 'Shipment marked as received.',
    ],

    'invoice' => [
        'add_success' => 'Invoice added to the order.',
        'update_success' => 'Invoice updated.',
        'delete_success' => 'Invoice removed from the order.',
    ],
];
