<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;

/** A contact's order as a document (Telegram contact page → Orders). */
class OrderController extends Controller
{
    public function show(Order $order)
    {
        return view('admin.orders.show', ['order' => $order->load('telegramUser', 'lead', 'conversation')]);
    }
}
