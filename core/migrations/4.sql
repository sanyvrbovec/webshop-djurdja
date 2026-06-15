-- v4: Fiskalni audit — pohrana TOČNOG zahtjeva poslanog đurđi (uz već postojeći
-- raw_response). Omogućuje vlasniku da neovisno provjeri što je poslano u Poreznu
-- i što je Porezna odgovorila (JIR/ZKI). Append-only; admin pregled je read-only.
ALTER TABLE fiscal_log ADD COLUMN raw_request LONGTEXT NULL AFTER error_message;
