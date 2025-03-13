<?php

use Illuminate\Support\Facades\Route;
use Paymenter\Extensions\Gateways\CryptoCloud\CryptoCloud;

Route::post('/extensions/cryptocloud/webhook', [CryptoCloud::class, 'webhook']);
