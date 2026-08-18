<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Purely internal to DeliverMerchantWebhookJob - thrown to make Laravel's queue retry
 * machinery kick in (a job that throws gets retried per its backoff() schedule until
 * $tries is exhausted, then lands in failed_jobs). Never rendered to an HTTP response,
 * unlike the DomainException-based exceptions elsewhere in this app - there's no
 * request in flight when this fires, it happens on a queue worker.
 */
class WebhookDeliveryFailedException extends RuntimeException {}
