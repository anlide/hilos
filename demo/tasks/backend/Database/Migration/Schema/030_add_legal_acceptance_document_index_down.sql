-- Reverts the legal administration indexes.

ALTER TABLE `hilos_legal_acceptance`
    DROP KEY `idx_legal_acceptance_document_revision`,
    DROP KEY `idx_legal_acceptance_accepted_at`;
