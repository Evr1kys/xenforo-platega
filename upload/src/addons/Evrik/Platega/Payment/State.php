<?php

namespace Evrik\Platega\Payment;

class State extends \XF\Payment\CallbackState
{
    public $source = 'callback';
    public $isPost = false;
    public $merchantHeader = '';
    public $secretHeader = '';
    public $event = [];
    public $invoice = [];
    public $remote = [];
}
