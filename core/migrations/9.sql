-- v9: spremljena porezna razrada (PDV + PnP + neoporezivo) fiskaliziranog računa,
-- onako kako ju je đurđa (izvor istine) izračunala i poslala CIS-u. Koristi se za
-- točan A4 ispis računa i porezni audit; shop sam ne računa poreze.
ALTER TABLE orders ADD COLUMN fiscal_taxes TEXT NULL;
