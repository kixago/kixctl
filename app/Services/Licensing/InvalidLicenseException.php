<?php

namespace App\Services\Licensing;

use RuntimeException;

/**
 * Thrown for ANY license that cannot be trusted: malformed, bad
 * signature, unknown version, or invalid payload shape. The app never
 * lets this bubble to a user — Entitlements catches it and degrades to
 * the free tier (an untrusted license is simply "no license").
 */
class InvalidLicenseException extends RuntimeException
{
}
