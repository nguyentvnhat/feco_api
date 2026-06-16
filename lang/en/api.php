<?php

return [
    'errors' => [
        'not_found' => 'Not found.',
        'validation_error' => 'Validation failed.',
    ],
    'auth' => [
        'invalid_credentials' => 'Invalid login credentials.',
        'login_success' => 'Login successful.',
        'logout_success' => 'Logout successful.',
        'account_not_found' => 'Account does not exist.',
        'account_not_activated' => 'Your account is not activated. Please contact administrator.',
        'account_locked' => 'Your account has been locked. Please contact administrator to unlock it.',
        'agent_policy_not_configured' => 'Commission policy has not been configured. Please contact administrator.',
    ],
    'order' => [
        'index_success' => 'User order list loaded successfully.',
        'create_success' => 'Order create metadata loaded successfully.',
        'show_success' => 'Order detail loaded successfully.',
        'history_commission_success' => 'Agent commission history loaded successfully.',
        'history_commission_no_agent_profile' => 'This account has no agent profile to view commission history.',
        'statuses_success' => 'Order status list loaded successfully.',
        'store_success' => 'Order created successfully.',
        'store_failed' => 'An error occurred while creating the order. Please try again.',
        'update_success' => 'Order updated successfully.',
        'destroy_success' => 'Order deleted successfully.',
        'cancel_success' => 'Order cancelled successfully.',
        'cancel_already_done' => 'This order is already cancelled/returned.',
        'cancel_not_allowed' => 'You cannot cancel order :order_no. Please contact Admin for support.',
        'preview_success' => 'Order preview generated successfully.',
        'clone_template_success' => 'Order clone template loaded successfully.',
        'preview_no_agent_profile' => 'This account has no agent profile for discount preview.',
        'agent_code_not_found' => 'Current user agent code was not found for order number generation.',
    ],
    'agent' => [
        'children_success' => 'Child agents loaded successfully.',
        'current_agent_not_found' => 'This account is not linked to an agent.',
    ],
    'setting' => [
        'index_success' => 'Settings loaded successfully.',
    ],
];
