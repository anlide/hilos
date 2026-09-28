<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Analytics\SecretPayloadMask;
use PHPUnit\Framework\TestCase;

/**
 * The rule analytics writes an action's declared secrets by (HIL-1187).
 *
 * One rule for the flat frame and for the envelope a worker hands an agent, where the action
 * lies under `data`: a declared key is masked at the top level and one level inside `data`,
 * a null or empty value is left alone, and nothing the declaration does not name is touched.
 */
final class SecretPayloadMaskTest extends TestCase
{
    public function testDeclaredKeyAtTheTopLevelIsMasked(): void
    {
        $masked = SecretPayloadMask::apply(['email' => 'ann@example.test', 'password' => 'hunter2'], ['password']);

        $this->assertSame(['email' => 'ann@example.test', 'password' => SecretPayloadMask::MASK], $masked);
    }

    public function testDeclaredKeyInsideDataIsMasked(): void
    {
        $envelope = [
            'acceptKey' => 'accept-1',
            'action' => 'profile_change_password',
            'data' => ['code' => '123456', 'newPassword' => 'next-secret', 'signOutOthers' => true],
        ];

        $masked = SecretPayloadMask::apply($envelope, ['code', 'newPassword']);

        $this->assertSame('accept-1', $masked['acceptKey']);
        $this->assertSame('profile_change_password', $masked['action']);
        $this->assertSame(
            ['code' => SecretPayloadMask::MASK, 'newPassword' => SecretPayloadMask::MASK, 'signOutOthers' => true],
            $masked['data'],
        );
    }

    public function testNullAndEmptyValuesStayAsTheyCame(): void
    {
        $payload = ['code' => '', 'password' => null, 'data' => ['code' => '', 'password' => null]];

        $this->assertSame($payload, SecretPayloadMask::apply($payload, ['code', 'password']));
    }

    public function testArrayAndNumberAreMaskedLikeAString(): void
    {
        $masked = SecretPayloadMask::apply(
            ['passkey' => ['id' => 'cred-1', 'signature' => 'sig'], 'code' => 123456, 'flag' => false],
            ['passkey', 'code', 'flag'],
        );

        $this->assertSame(
            ['passkey' => SecretPayloadMask::MASK, 'code' => SecretPayloadMask::MASK, 'flag' => SecretPayloadMask::MASK],
            $masked,
        );
    }

    public function testAbsentKeyIsNotAdded(): void
    {
        $payload = ['email' => 'ann@example.test', 'data' => ['email' => 'ann@example.test']];

        $this->assertSame($payload, SecretPayloadMask::apply($payload, ['password']));
    }

    public function testKeyOutsideTheDeclarationIsLeftAlone(): void
    {
        $masked = SecretPayloadMask::apply(['code' => '123456', 'label' => 'Phone'], ['code']);

        $this->assertSame('Phone', $masked['label']);
    }

    public function testMaskGoesNoDeeperThanOneLevelOfData(): void
    {
        $payload = ['data' => ['data' => ['code' => '123456']]];

        $this->assertSame($payload, SecretPayloadMask::apply($payload, ['code']));
    }
}
