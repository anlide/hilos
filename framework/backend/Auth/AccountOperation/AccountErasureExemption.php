<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

/**
 * Why a mounted collection, the framework's or a project's, is left out of the account erasure census.
 */
enum AccountErasureExemption: string
{
    /** The erasure's own trace of a person, kept on purpose. */
    case Trace = 'trace';
    /** A working record of account operations themselves. */
    case ServiceRecord = 'service_record';
    /** A rule of the installation, not of one person. */
    case InstallationPolicy = 'installation_policy';
    /** A row whose reference to the person is cleared instead of the row being erased. */
    case ReferenceNullified = 'reference_nullified';
    /** A record held before or apart from any account. */
    case OutsideAccount = 'outside_account';
    /** Data with nothing personal in it. */
    case NotPersonal = 'not_personal';
}
