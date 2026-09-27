-- Indexes legal administration windows and aggregate reads (HIL-941).

ALTER TABLE `hilos_legal_acceptance`
    ADD KEY `idx_legal_acceptance_document_revision` (`document`, `revision_id`, `accepted_at`),
    ADD KEY `idx_legal_acceptance_accepted_at` (`accepted_at`);
