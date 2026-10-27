<?php

namespace App\Domains\CloudProviders\Exceptions;

use RuntimeException;

/** The message is the text that Cloudflare sent. */
class CloudflareException extends RuntimeException {}
