<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Users;

use Hilos\Auth\AccountOperation\AccountMergeRefusal;
use Hilos\Database\Entity\Item\AccountOperation as EntityAccountOperation;
use Hilos\Database\Object\Item\AccountOperation as ObjectAccountOperation;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Users\AccountMergeRefusalText;
use PHPUnit\Framework\TestCase;

/** Nonparameterized merge refusal words need no database or authentication lookup. */
final class AccountMergeRefusalTextTest extends TestCase
{
    public function testReadySentencesPreserveEveryExistingTemplateWithoutPlaceholders(): void
    {
        $operation = new AccountOperation(ObjectAccountOperation::fromEntity(new EntityAccountOperation()));
        foreach ([
            'password_fate_required' => 'Both accounts have a password: pass --password=survivor|loser|none',
            'protection_changed' => 'Second-factor protection changed. Review the accounts and choose again.',
            'second_factor_fate_required' => 'Choose second-factor protection: survivor or both (--second-factor on the console).',
            'loser_without_second_factor' => 'The loser has no confirmed authenticator. Remove the second-factor choice.',
            'survivor_without_second_factor' => "The survivor has no confirmed authenticator. Transfer the loser's protection or cancel.",
            'loser_pending_removal' => 'The loser has a pending second-factor removal. Cancel it or wait for it to finish.',
            'survivor_pending_removal' => 'The survivor has a pending second-factor removal. Cancel it or wait for it to finish.',
        ] as $code => $expected) {
            $actual = AccountMergeRefusalText::of($operation, AccountMergeRefusal::from($code));
            self::assertSame($expected, $actual);
            self::assertStringNotContainsString('%s', $actual);
            self::assertStringNotContainsString('%d', $actual);
        }
    }

    public function testExistingRefusalPriorityIncludesTheDerivedPasswordChoiceInItsApprovedPlace(): void
    {
        self::assertSame(['missing', 'survivor_merged', 'loser_merged', 'being_deleted', 'occupied', 'password_fate_required',
            'protection_changed', 'second_factor_fate_required', 'loser_without_second_factor', 'survivor_without_second_factor',
            'loser_pending_removal', 'survivor_pending_removal'], array_column(AccountMergeRefusal::cases(), 'value'));
    }
}
