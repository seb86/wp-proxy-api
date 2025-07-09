<?php
$allowed_plugins = [
    'cart-rest-api-for-woocommerce' => [
        'owner' => 'co-cart',
        'repo' => 'co-cart',
        // Optional: Allow a branch to be downloaded as a zip
        'branch_download' => 'trunk',
        'legacy' => true,
    ],
    'cocart-core' => [
        'owner' => 'co-cart',
        'repo' => 'co-cart',
        // Optional: Only fetch releases from this tag onwards
        'min_release' => 'v5.0.0',
    ],
    'cocart-plus' => [
        'owner' => 'cocart-headless',
        'repo' => 'cocart-plus',
    ],
    // Add more plugins as needed
];