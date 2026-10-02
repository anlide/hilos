<?php

declare(strict_types=1);

namespace Demo\Chat\Agents\Hilos;

use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Hilos;
use Hilos\Core\Exception\LogicException;
use Hilos\DataExport\AbstractDataExportAgent;
use Hilos\DataExport\DataExportTime;
use Hilos\DataExport\DataExportWriter;
use Hilos\Database\Context\HilosDbContext;
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
        HilosDbContext::files,
    ];

    /**
     * @param int $userId Person whose profile and content are exported
     * @param DataExportWriter $writer Archive serialization boundary
     * @throws HilosException When a record or attachment cannot be exported
     * @throws LogicException When an attachment links no registry row, which its foreign key forbids
     */
    protected function applyAccountExport(int $userId, DataExportWriter $writer): void
    {
        $messages = [];
        foreach (Hilos::$db->eventMessages->byAuthor($userId) as $message) {
            $attachments = [];
            foreach ($message->attachments as $attachment) {
                // The name and the bytes are the registry's; the foreign key keeps the row while the attachment lives.
                $file = $attachment->file ?? throw new LogicException("Attachment {$attachment->id} links no registry row");
                $attachments[] = [
                    'filename' => $file->filename,
                    'file' => $writer->file($file->storedName, Hilos::$fs->files[$file->storedName]->getPath()),
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
        $writer->section('chat', [
            'profile' => ['name' => Hilos::$db->users[$userId]?->name],
            'messages' => $messages,
            'registeredAt' => DataExportTime::iso($registeredAt),
        ]);
    }
}
