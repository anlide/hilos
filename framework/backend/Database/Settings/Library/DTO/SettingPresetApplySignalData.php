<?php

declare(strict_types=1);

namespace Hilos\Database\Settings\Library\DTO;

use Hilos\BaseDTO;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Action\HandoverAskInterface;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Database\Settings\Preset\SettingPresetGroupProviderInterface;
use Hilos\Database\Settings\Preset\SettingPresetResolver;
use Hilos\Pages\AbstractHilosSettingPresetsPage;

/**
 * A presets section → the settings library: apply this preset of this group (HIL-946).
 *
 * What {@see HilosSignalConstants::HILOS_SETTING_PRESET_APPLY} carries. The whole operation
 * crosses, checking included: {@see SettingPresetResolver::apply()} judges every value of the
 * preset before it writes the first of them, and splitting that in two would rewrite the one
 * thing this leaf set out not to touch. What stays on the screen is the single reading it needs
 * before the write - which preset was selected a moment ago, which is what picks the sentence.
 *
 * The group travels as the class name of its provider and not as a set of pairs. The page
 * already holds it that way, under `GROUP_PROVIDER`, and already proves it that way, with
 * `is_subclass_of()` against {@see SettingPresetGroupProviderInterface}; the library repeats
 * exactly that check and refuses in words when something else arrives. A set of pairs would let
 * a frame declare its own preset, which is a thing a section's provider is there to forbid.
 *
 * The reply name is a section's own ({@see AbstractHilosSettingPresetsPage} makes each one
 * declare it), so it rides in the frame like every other ask of this library.
 */
final class SettingPresetApplySignalData extends BaseDTO implements HandoverAskInterface
{
    /**
     * @param string $replySignal Agent-signal name the library reports back under
     * @param string $acceptKey Initiating connection accept key to answer
     * @param ?string $requestId Client-minted request id of the tracked submit, or null when untracked
     * @param string $action Browser action name the ack is addressed to
     * @param ?string $successMessage Sentence to speak on success, or null where the gesture has none
     * @param string $groupProvider Class name of the group provider whose presets are applied
     * @param string $preset Machine name of the preset to apply
     */
    public function __construct(
        public readonly string $replySignal,
        public readonly string $acceptKey,
        public readonly ?string $requestId,
        public readonly string $action,
        public readonly ?string $successMessage,
        public readonly string $groupProvider,
        public readonly string $preset,
    ) {
    }

    /**
     * Convert DTO to array for transport.
     *
     * @return array<string, mixed> DTO data as array
     */
    public function toArray(): array
    {
        return [
            'replySignal' => $this->replySignal,
            'acceptKey' => $this->acceptKey,
            'requestId' => $this->requestId,
            'action' => $this->action,
            'successMessage' => $this->successMessage,
            'groupProvider' => $this->groupProvider,
            'preset' => $this->preset,
        ];
    }

    /**
     * Create DTO from array.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the payload names no group, no preset, or nobody to answer
     */
    public static function fromArray(array $data): static
    {
        return new static(
            replySignal: self::requireString($data, 'replySignal'),
            acceptKey: self::requireString($data, 'acceptKey'),
            requestId: self::optionalString($data, 'requestId'),
            action: self::requireString($data, 'action'),
            successMessage: self::optionalString($data, 'successMessage'),
            groupProvider: self::requireString($data, 'groupProvider'),
            preset: self::requireString($data, 'preset'),
        );
    }
}
