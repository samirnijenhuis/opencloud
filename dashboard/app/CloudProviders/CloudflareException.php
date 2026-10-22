<?php

namespace App\CloudProviders;

use RuntimeException;

/** The message is the text that Cloudflare sent. */
class CloudflareException extends RuntimeException {}
