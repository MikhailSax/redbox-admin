<?php

namespace App\Service;

/**
 * A payment action is not possible (no client, schedule already made, …); the message is shown to the manager.
 */
final class PaymentException extends \RuntimeException
{
}
