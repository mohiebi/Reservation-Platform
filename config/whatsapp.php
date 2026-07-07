<?php

return [
    'cloud_api_url' => env('WHATSAPP_CLOUD_API_URL', 'https://graph.facebook.com/v21.0'),
    'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
    'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
    'app_secret' => env('WHATSAPP_APP_SECRET'),
];
