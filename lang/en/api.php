<?php

return [
    'errors' => [
        'not_found' => 'Not found.',
        'validation_error' => 'Validation failed.',
    ],
    'auth' => [
        'invalid_credentials' => 'Invalid credentials.',
        'login_success' => 'Login successful.',
        'logout_success' => 'Logout successful.',
        'account_not_found' => 'Account does not exist.',
        'account_not_activated' => 'Your account is not activated. Please contact administrator.',
        'agent_policy_not_configured' => 'Commission policy has not been configured. Please contact administrator.',
    ],
    'order' => [
        'index_success' => 'User order list loaded successfully.',
        'create_success' => 'Order create metadata loaded successfully.',
        'show_success' => 'Order detail loaded successfully.',
        'history_commission_success' => 'Order commission history loaded successfully.',
        'statuses_success' => 'Order status list loaded successfully.',
        'store_success' => 'Order created successfully.',
        'update_success' => 'Order updated successfully.',
        'preview_success' => 'Order preview generated successfully.',
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
