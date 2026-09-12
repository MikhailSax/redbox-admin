<?php

namespace App\Service;

/**
 * A booking action is not possible (slot taken, hold expired, …); the message is shown to the manager.
 */
final class BookingException extends \RuntimeException
{
}
