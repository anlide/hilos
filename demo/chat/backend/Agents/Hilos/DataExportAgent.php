<?php

declare(strict_types=1);

namespace Demo\Chat\Agents\Hilos;

use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Hilos;
use Hilos\DataExport\AbstractDataExportAgent;
use Hilos\DataExport\DataExportTime;
use Hilos\DataExport\DataExportWriter;
use Hilos\HilosException;

/** Exports only the project's records belonging to the person. */
final class DataExportAgent extends AbstractDataExportAgent
{
    public const array READS_DB = [
        ...parent::READS_DB,
        ChatDbContext::users,
        ChatDbContext::eventMessages,
        ChatDbContext::eventAttachments,
        ChatDbContext::events,
        ChatDbContext::eventUserRegistrations,
        ChatDbContext::eventUserRenames,
    ];

    /**
     * @param int $userId Person whose profile and content are exported
     * @param DataExportWriter $writer Archive serialization boundary
     * @throws HilosException When a record or attachment cannot be exported
     */
    protected function applyAccountExport(int $userId, DataExportWriter $writer): void
    {
        $messages = [];
        foreach (Hilos::$db->eventMessages->byAuthor($userId) as $message) {
            $attachments = [];
            foreach ($message->attachments as $attachment) {
                $attachments[] = [
                    'filename' => $attachment->filename,
                    'file' => $writer->file($attachment->storedName, $attachment->file->getPath()),
                ];
            }
            $messages[] = [
                'sentAt' => DataExportTime::iso($message->event?->timestamp),
                'text' => $message->message,
                'attachments' => $attachments,
            ];
        }
        $registeredAt = null;
        foreach (Hilos::$db->eventUserRegistrations->byTarget($userId) as $registration) {
            $timestamp = $registration->event?->timestamp;
            if ($timestamp !== null && ($registeredAt === null || $timestamp < $registeredAt)) {
                $registeredAt = $timestamp;
            }
        }
        $renames = [];
        foreach (Hilos::$db->eventUserRenames->byTarget($userId) as $rename) {
            $renames[] = [
                'from' => $rename->oldName,
                'to' => $rename->newName,
                'at' => DataExportTime::iso($rename->event?->timestamp),
            ];
        }
        $writer->section('chat', [
            'profile' => ['name' => Hilos::$db->users[$userId]?->name],
            'messages' => $messages,
            'registeredAt' => DataExportTime::iso($registeredAt),
            'renames' => $renames,
        ]);
    }
}
