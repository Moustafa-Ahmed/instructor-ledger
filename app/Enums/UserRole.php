<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a user does on the platform.
 *
 * Student    — buys subscriptions, has ledger entries of type=allocation
 *              against them (the earnings paid out to instructors).
 * Instructor — teaches courses (rows in course_instructor) and has
 *              ledger entries of type=allocation and type=payout against
 *              them.
 *
 * payout_destination is only meaningful when role=instructor; for
 * students it stays null.
 */
enum UserRole: string
{
    case Student = 'student';
    case Instructor = 'instructor';
}
