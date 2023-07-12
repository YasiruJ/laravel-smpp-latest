
# Laravel SMPP

This is forked from [franzose/laravel-smpp](https://github.com/franzose/laravel-smpp) package and this one use the [alexandr-mironov/php-smpp](https://github.com/alexandr-mironov/php-smpp) library.

## Installation
You can install Laravel SMPP using Composer command:
```bash
$ composer require YasiruJ/laravel-smpp
```

Then you need to add `LaravelSmpp\LaravelSmppServiceProvider::class` to your providers array in the `config/app.php`
and copy default configuration by invoking `$ php artisan vendor:publish` command.

## Usage

You can use the service pretty straightforward and inject dependency in your controller:
 
```php
<?php

namespace App\Http\Controllers;

class SmsController extends Controller
{
    public function send(SmppServiceInterface $smpp)
    {
        // One number
        $this->smpp->sendOne(1234567890, 'Hi, this SMS was send via SMPP protocol');
        
        // Multiple numbers
        $this->smpp->sendBulk([1234567890, 0987654321], 'Hi!');
    }
}
```

However it is better to abstract your SMS sending service from the SMPP implementation by defining a SMPP-compatible service interface.
